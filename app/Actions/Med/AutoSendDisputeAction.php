<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\SourceStatus;
use App\Models\Dispute;
use App\Models\DisputeSource;
use App\Services\DisputeEventRecorder;
use App\Services\Woovi\WooviException;
use Throwable;

/**
 * Envio automático à Woovi depois da defesa do seller. Pula o analista, então só
 * acontece com TODAS as travas (config/med.php > envio_automatico). Qualquer uma
 * falhando, o caso fica na fila do analista e o motivo vai para a linha do tempo.
 *
 * As travas existem porque mandar sozinho é defender sozinho: arquivo forjado,
 * conta invadida ou venda já devolvida sairiam sem ninguém olhar.
 */
class AutoSendDisputeAction
{
    public function __construct(
        private readonly SendEvidenceToWooviAction $send,
        private readonly SyncDisputeAction $sync,
        private readonly DisputeEventRecorder $events,
    ) {}

    /**
     * @return bool true quando enviou
     */
    public function handle(Dispute $dispute): bool
    {
        $dispute->loadMissing('wooviAccount');

        // Opção desligada (geral ou na conta) não gera ruído na linha do tempo.
        if (! config('med.envio_automatico.habilitado') || ! $dispute->wooviAccount->auto_send) {
            return false;
        }

        $reasons = $this->blockers($dispute);

        if ($reasons !== []) {
            $this->events->record(
                DisputeEventType::AUTO_SEND_SKIPPED,
                $dispute,
                'Envio automático não feito - caso segue para o analista: '.implode('; ', $reasons).'.',
                ['reasons' => $reasons],
            );

            return false;
        }

        try {
            $sent = $this->send->handle($dispute, source: 'system');
        } catch (WooviException $e) {
            // Caso que a Woovi já decidiu: relê para fechar aqui também.
            if ($e->status === 400) {
                rescue(fn () => $this->sync->handle($dispute), report: false);
            }

            return false;
        } catch (Throwable $e) {
            $this->events->record(DisputeEventType::AUTO_SEND_SKIPPED, $dispute, 'Envio automático não feito: '.$e->getMessage(), level: 'warning');

            return false;
        }

        $this->events->record(DisputeEventType::AUTO_SENT, $dispute, "{$sent} documento(s) enviados à Woovi automaticamente, depois da defesa do seller e da conferência ok.");

        return true;
    }

    /**
     * O que impede o envio automático (vazio = pode enviar). Na dúvida, bloqueia:
     * dado que falta ou que não foi relido conta como trava, não como passe.
     *
     * @return array<int, string>
     */
    public function blockers(Dispute $dispute): array
    {
        $match = (array) $dispute->dossier_match;
        $checks = collect((array) ($match['checks'] ?? []))->keyBy('key');
        $value = $dispute->value ?? data_get($dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION), 'value');
        $ceiling = (int) config('med.envio_automatico.teto_centavos');
        $situation = strtoupper(trim((string) $dispute->situation_type));
        $reason = mb_strtolower((string) $dispute->dispute_reason);
        $refunds = $this->source($dispute, DossierSource::WOOVI_REFUNDS);
        $kyc = $this->source($dispute, DossierSource::KYC_STATUS);

        return array_values(array_filter([
            $dispute->status !== DisputeStatus::SELLER_SUBMITTED ? "caso em \"{$dispute->status->label()}\", não em defesa recebida" : null,
            // O analista já entrou no caso: a resposta ao que ele pediu é ele que vê.
            $dispute->events()->where('event', DisputeEventType::COMPLEMENT_REQUESTED->value)->exists() ? 'o analista pediu complemento neste caso' : null,
            $match === [] ? 'dossiê sem conferência' : null,
            $match !== [] && ! ($match['ready'] ?? false) ? 'conferência com pendência ou divergência' : null,
            ($checks['no_refund']['status'] ?? null) !== MatchDossierAction::OK ? 'devoluções da cobrança não conferidas ou já existentes' : null,
            ! $this->freshSince($refunds, $dispute) ? 'devoluções não relidas depois da defesa do seller' : null,
            $kyc !== null && $kyc->status !== SourceStatus::NOT_CONFIGURED
                && (($checks['kyc_approved']['status'] ?? null) !== MatchDossierAction::OK || ! $this->freshSince($kyc, $dispute))
                ? 'KYC do seller não confirmado como aprovado agora' : null,
            ! is_numeric($value) ? 'valor do caso desconhecido' : null,
            is_numeric($value) && (int) $value > $ceiling ? 'valor acima do teto do envio automático' : null,
            $situation === '' && config('med.envio_automatico.exigir_situacao') ? 'alegação do MED (situationType) não informada pela Woovi' : null,
            $situation !== '' && in_array($situation, array_map('strtoupper', (array) config('med.envio_automatico.situacoes_bloqueadas')), true)
                ? "alegação {$dispute->situation_type} exige análise humana" : null,
            collect((array) config('med.envio_automatico.motivos_bloqueados'))->contains(fn (string $word): bool => $word !== '' && str_contains($reason, mb_strtolower($word)))
                ? 'motivo da disputa indica conta invadida ou coação' : null,
        ]));
    }

    private function source(Dispute $dispute, DossierSource $source): ?DisputeSource
    {
        return $dispute->sources()->where('source', $source->value)->first();
    }

    /**
     * Fonte relida com sucesso depois da defesa do seller (sem erro guardado).
     */
    private function freshSince(?DisputeSource $source, Dispute $dispute): bool
    {
        return $source !== null
            && $source->status === SourceStatus::OK
            && blank($source->error)
            && $source->fetched_at !== null
            && ($dispute->seller_submitted_at === null || $source->fetched_at->greaterThanOrEqualTo($dispute->seller_submitted_at));
    }
}
