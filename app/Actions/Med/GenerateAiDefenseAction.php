<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\MerchantStore;
use App\Services\Defense\DefenseWriter;
use App\Services\Defense\DefenseWriterException;
use App\Services\DisputeEventRecorder;
use App\Services\Woovi\WooviClient;
use App\Support\BusinessDays;
use Throwable;

/**
 * Rascunho da defesa pela IA. Monta o caso só com o que pode sair do sistema - os
 * fatos que a conferência marcou ok (já mascarados), as pendências, as evidências
 * (tipo e descrição), o relato do seller e, quando a conta tem a feature "MED API",
 * o relato de quem contestou (infraction-reports) - e guarda o rascunho. Nada vai à
 * Woovi aqui: o analista revisa, ajusta o texto e envia pelo fluxo de sempre.
 */
class GenerateAiDefenseAction
{
    /**
     * Versão das regras do rascunho. Sobe quando uma regra muda o que a carta pode
     * afirmar; rascunho de versão anterior não sai no envio em lote (med:responder).
     * 2: prazo de loja só com a política capturada do site ou o padrão confirmado.
     * 3: caso contestado por decisão da operação (pagador diferente do cliente, venda
     *    não localizada) não afirma legitimidade nem ausência de indício de fraude.
     */
    public const RULES_VERSION = 3;

    public function __construct(
        private readonly DefenseWriter $writer,
        private readonly DisputeEventRecorder $events,
    ) {}

    public function handle(Dispute $dispute, ?int $userId = null): Dispute
    {
        $dispute->loadMissing(['wooviAccount', 'evidences', 'sources']);

        if (! $dispute->status->canSendToWoovi()) {
            throw new DefenseWriterException("Caso {$dispute->status->label()}: não aceita mais defesa.");
        }

        $case = $this->case($dispute);

        try {
            $result = $this->writer->write($case);
        } catch (DefenseWriterException $e) {
            $this->events->record(DisputeEventType::AI_DEFENSE_FAILED, $dispute, 'Defesa com IA não gerada: '.$e->getMessage(), level: 'warning', source: $userId ? 'analyst' : 'system');

            throw $e;
        }

        $draft = $result['draft'];

        $dispute->forceFill([
            'ai_defense' => $draft + ['model' => $result['model'], 'generated_at' => now()->toIso8601String(), 'used_claim' => filled($case['claim']), 'rules_version' => self::RULES_VERSION],
            'ai_defense_generated_at' => now(),
            // O texto editável parte do rascunho; edição do analista não é sobrescrita
            // por um rascunho novo sem ele pedir (botão "Usar o rascunho da IA"). Texto
            // igual ao rascunho anterior ninguém mexeu: vale o novo.
            'defense_text' => blank($dispute->defense_text) || $dispute->defense_text === data_get($dispute->ai_defense, 'narrative')
                ? $draft['narrative']
                : $dispute->defense_text,
        ])->save();

        $this->events->record(
            DisputeEventType::AI_DEFENSE_GENERATED,
            $dispute,
            'Rascunho da defesa gerado pela IA. Recomendação: '.self::recommendationLabel($draft['recommendation'] ?? null).'.',
            ['model' => $result['model'], 'usage' => $result['usage'], 'recommendation' => $draft['recommendation'] ?? null, 'used_claim' => filled($case['claim'])],
            source: $userId ? 'analyst' : 'system',
        );

        return $dispute;
    }

    public static function recommendationLabel(?string $value): string
    {
        return match ($value) {
            'defender' => 'defender',
            'defender_com_ressalvas' => 'defender com ressalvas',
            'nao_defender' => 'não defender',
            default => 'sem recomendação',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function case(Dispute $dispute): array
    {
        $checks = collect((array) data_get($dispute->dossier_match, 'checks', []));

        return [
            'case' => array_filter([
                'tipo' => $dispute->type ?: 'MED',
                'valor' => $dispute->formattedValue(),
                'motivo_informado' => $dispute->dispute_reason,
                'alegacao_bacen' => $dispute->situation_type,
                'aberto_em' => $dispute->opened_at?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'recebedor' => data_get($dispute->merchant, 'trade_name') ?: $dispute->seller()['name'],
                'produto' => $dispute->products(),
                'pago_em' => $dispute->paidAt()?->timezone('America/Sao_Paulo')->format('d/m/Y H:i'),
                'pedido_informado_pelo_recebedor' => data_get($dispute->seller_details, 'order_reference'),
                'rastreio_informado_pelo_recebedor' => data_get($dispute->seller_details, 'tracking_code'),
            ]),
            'delivery' => $this->delivery($dispute),
            'store_policies' => $this->storePolicies($dispute),
            'facts' => MatchDossierAction::facts($dispute->dossier_match),
            'gaps' => $checks
                ->filter(fn ($check): bool => is_array($check) && ($check['status'] ?? null) !== MatchDossierAction::OK)
                ->map(fn (array $check): string => ($check['label'] ?? $check['key'] ?? '?').': '.(($check['status'] ?? '') === MatchDossierAction::DIVERGENT ? 'divergente' : 'sem dado').(filled($check['detail'] ?? null) ? " ({$check['detail']})" : ''))
                ->values()
                ->all(),
            'evidences' => $dispute->evidences
                ->reject(fn (DisputeEvidence $evidence): bool => $evidence->kind === EvidenceKind::DEFENSE_LETTER)
                ->map(fn (DisputeEvidence $evidence): string => $evidence->kind->label().($evidence->description ? ' - '.$evidence->description : ''))
                ->values()
                ->all(),
            'seller_statement' => $dispute->seller_statement,
            'analyst_notes' => $dispute->analyst_notes,
            'claim' => $this->claim($dispute),
        ];
    }

    /**
     * Texto das políticas publicadas no site da loja (capturadas pela ÚnicoPag). É
     * conteúdo de terceiro: vai como dado, nunca como instrução.
     *
     * @return array<string, string>
     */
    private function storePolicies(Dispute $dispute): array
    {
        $store = filled($dispute->merchant_account_id)
            ? MerchantStore::query()->where('merchant_account_id', $dispute->merchant_account_id)->first()
            : null;

        if (! $store?->isFresh()) {
            return [];
        }

        return collect((array) $store->captures)
            ->filter(fn (array $capture): bool => in_array($capture['kind'], ['shipping', 'exchange'], true) && filled($capture['text'] ?? null))
            ->mapWithKeys(fn (array $capture): array => [
                (MerchantStore::KINDS[$capture['kind']] ?? $capture['kind']).' ('.$capture['url'].')' => mb_substr((string) $capture['text'], 0, 5000),
            ])
            ->all();
    }

    /**
     * Quanto tempo passou entre o pagamento e a abertura do MED (e até hoje), em dias
     * úteis. A política padrão de prazo só vem junto quando a operação confirmou que
     * vale para todas as lojas; fora isso, prazo de loja só pela política capturada
     * do site dela ({@see storePolicies()}).
     *
     * @return array<string, mixed>|null
     */
    private function delivery(Dispute $dispute): ?array
    {
        $paidAt = $dispute->paidAt();

        if ($paidAt === null) {
            return null;
        }

        $tracking = (int) config('med.defesa.prazo_rastreio_dias_uteis');
        $min = (int) config('med.defesa.prazo_entrega_dias_uteis_min');
        $max = (int) config('med.defesa.prazo_entrega_dias_uteis');
        $atOpening = $dispute->opened_at ? BusinessDays::between($paidAt, $dispute->opened_at) : null;
        $standard = (bool) config('med.defesa.politica_padrao_confirmada');

        return array_filter([
            'dias_uteis_do_pagamento_ate_a_abertura_do_med' => $atOpening,
            'dias_uteis_do_pagamento_ate_hoje' => BusinessDays::between($paidAt, now()),
            'politica_de_envio_padrao_das_lojas' => $standard ? "Código de rastreio em até {$tracking} dias úteis; entrega em {$min} a {$max} dias úteis após o despacho, contados em dias úteis." : null,
            'prazo_maximo_dias_uteis_desde_o_pagamento' => $standard ? $tracking + $max : null,
            'med_aberto_dentro_do_prazo_de_entrega' => $standard && $atOpening !== null ? $atOpening <= $tracking + $max : null,
        ], fn ($value): bool => $value !== null);
    }

    /**
     * Relato de quem contestou, pela notificação de infração do Banco Central. Só com
     * a feature "MED API": sem ela (403) ou sem o id da recuperação, a IA segue sem.
     */
    private function claim(Dispute $dispute): ?string
    {
        $fundsRecoveryId = data_get($dispute->sourcePayload(DossierSource::WOOVI_FUNDS_RECOVERY), 'fundsRecoveryId');

        if (! is_string($fundsRecoveryId) || $fundsRecoveryId === '' || ! $dispute->wooviAccount) {
            return null;
        }

        try {
            $reports = WooviClient::for($dispute->wooviAccount)->getInfractionReports($fundsRecoveryId);
        } catch (Throwable) {
            return null;
        }

        $details = collect($reports)
            ->map(fn (array $report): string => trim((string) ($report['reportDetails'] ?? '')))
            ->filter()
            ->unique()
            ->implode("\n---\n");

        return $details !== '' ? mb_substr($details, 0, 4000) : null;
    }
}
