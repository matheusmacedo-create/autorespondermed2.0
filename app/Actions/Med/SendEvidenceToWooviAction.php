<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Services\DiscordNotificationService;
use App\Services\DisputeEventRecorder;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;
use App\Support\DisputeLock;
use Illuminate\Support\Facades\URL;
use RuntimeException;

/**
 * Responde o MED na Woovi: gera a carta de defesa, sobe os arquivos ainda não
 * enviados e chama POST /api/v1/dispute/{id}/evidence com todos de uma vez.
 *
 * Só marca como enviado depois que a Woovi confirma (padrão "escrever local
 * antes de confirmar o remoto" do KYC). Falha não perde nada: as evidências
 * ficam sem `sent_at` e o próximo envio tenta de novo, com os mesmos
 * correlationID - o upload na Woovi é idempotente por eles.
 */
class SendEvidenceToWooviAction
{
    /** Depois de a Woovi recusar o fileId, quantos dias até tentar de novo. */
    private const FILE_ID_RETRY_DAYS = 7;

    public function __construct(
        private readonly GenerateDefenseLetterAction $generateDefenseLetter,
        private readonly MatchDossierAction $match,
        private readonly DisputeEventRecorder $events,
        private readonly DiscordNotificationService $discord,
    ) {}

    /**
     * @param  string  $source  analyst (clique no painel) | system (envio automático)
     * @return int quantos documentos foram enviados
     */
    public function handle(Dispute $dispute, ?int $userId = null, string $source = 'analyst'): int
    {
        // Um envio por caso de cada vez, e nunca durante a montagem do dossiê: o
        // clique do analista, o envio automático e o retry de um job podiam mandar
        // duas cartas diferentes, ou subir um documento que a montagem estava trocando.
        return DisputeLock::run($dispute, fn () => $this->send($dispute->fresh() ?? $dispute, $userId, $source), waitSeconds: 0);
    }

    private function send(Dispute $dispute, ?int $userId, string $source): int
    {
        $dispute->loadMissing('wooviAccount');

        if (! $dispute->status->canSendToWoovi()) {
            throw new RuntimeException("Caso {$dispute->status->label()}: não aceita mais evidência.");
        }

        // A carta afirma os itens ok da conferência: refeita aqui, com as evidências de
        // agora, ela não cita documento que saiu do caso depois da última montagem.
        if ($dispute->dossier_match !== null) {
            $this->match->handle($dispute);
        }

        $this->generateDefenseLetter->handle($dispute);

        $pending = $dispute->evidences()->whereNull('sent_at')->orderBy('id')->get();

        if ($pending->isEmpty()) {
            throw new RuntimeException('Nenhuma evidência nova para enviar.');
        }

        $client = WooviClient::for($dispute->wooviAccount);
        $mode = $this->initialMode($dispute);

        try {
            try {
                [$documents, $mode] = $this->documents($client, $pending, $mode, $dispute);
                $response = $client->sendEvidence($dispute->wooviIdentifier(), $documents);
            } catch (WooviException $e) {
                // A conta sobe o arquivo (FILE_POST), mas não tem a feature de evidência por
                // fileId: a recusa vem só aqui. Reenvia uma vez com o link do arquivo no
                // storage da própria Woovi, que ela baixa e guarda na hora.
                if ($mode !== 'file_id' || ! $e->isFileIdNotAllowed()) {
                    throw $e;
                }

                $dispute->wooviAccount->forceFill(['evidence_file_id_refused_at' => now()])->save();

                $this->events->record(
                    DisputeEventType::EVIDENCE_SEND_FAILED,
                    $dispute,
                    'A Woovi não aceita evidência por fileId nesta conta - reenviando com o link do arquivo guardado na Woovi.',
                    ['status' => $e->status, 'error_code' => $e->errorCode],
                    level: 'warning',
                );

                [$documents, $mode] = $this->documents($client, $pending, 'woovi_url', $dispute);
                $response = $client->sendEvidence($dispute->wooviIdentifier(), $documents);
            }
        } catch (WooviException $e) {
            $this->fail($dispute, $pending, $e->getMessage(), ['status' => $e->status, 'error_code' => $e->errorCode], $source);

            throw $e;
        }

        $returnedUrls = collect($response)->keyBy('correlationID');

        foreach ($pending as $evidence) {
            $evidence->fill([
                'sent_at' => now(),
                'woovi_url' => $returnedUrls->get($evidence->correlation_id)['url'] ?? $evidence->woovi_url,
                'last_error' => null,
            ])->save();
        }

        $dispute->fill([
            'status' => DisputeStatus::EVIDENCE_SENT,
            'evidence_sent_at' => now(),
            'reviewed_by' => $userId ?? $dispute->reviewed_by,
        ])->save();

        $this->events->record(
            DisputeEventType::EVIDENCE_SENT,
            $dispute,
            sprintf('%d documento(s) enviados à Woovi (%s).', $pending->count(), self::modeLabel($mode)),
            ['evidence_ids' => $pending->pluck('id')->all(), 'mode' => $mode],
            source: $source,
        );

        return $pending->count();
    }

    /**
     * Modo do envio: o configurado, menos o fileId que a conta já recusou há pouco
     * (vai direto pelo link do arquivo na Woovi; passada a semana, tenta de novo e
     * passa a usar o fileId sozinho quando a Woovi liberar a feature).
     */
    private function initialMode(Dispute $dispute): string
    {
        $mode = (string) config('services.woovi.evidence_mode', 'file_id');
        $refusedAt = $dispute->wooviAccount->evidence_file_id_refused_at;

        if ($mode === 'file_id' && $refusedAt !== null && $refusedAt->gt(now()->subDays(self::FILE_ID_RETRY_DAYS))) {
            return 'woovi_url';
        }

        return in_array($mode, ['file_id', 'woovi_url', 'url'], true) ? $mode : 'file_id';
    }

    /**
     * @param  iterable<DisputeEvidence>  $pending
     * @return array{0: array<int, array<string, string>>, 1: string} os documentos e o modo que valeu
     */
    private function documents(WooviClient $client, iterable $pending, string $mode, Dispute $dispute): array
    {
        $documents = [];

        foreach ($pending as $evidence) {
            [$documents[], $mode] = $this->document($client, $evidence, $mode, $dispute);
        }

        return [$documents, $mode];
    }

    /**
     * Monta o item de `documents` - com `fileId` ou com `url`, nunca os dois.
     *
     * - file_id: o arquivo sobe para a Woovi e vai pelo id (feature DISPUTE_EVIDENCE_FILE_ID).
     * - woovi_url: o arquivo sobe para a Woovi e vai pelo link de download que ela
     *   devolve (storage dela; ela baixa e guarda a cópia na hora do envio).
     * - url: link assinado deste sistema - só quando nem o upload na Woovi funciona.
     *
     * @return array{0: array<string, string>, 1: string} o documento e o modo para os próximos
     */
    private function document(WooviClient $client, DisputeEvidence $evidence, string $mode, Dispute $dispute): array
    {
        $base = [
            'description' => $evidence->wooviDescription(),
            'correlationID' => $evidence->correlation_id,
        ];

        if ($mode === 'file_id' || $mode === 'woovi_url') {
            try {
                // O link do arquivo na Woovi expira: no modo woovi_url sobe sempre (com o
                // mesmo correlationID a Woovi devolve o arquivo guardado e um link novo).
                $file = $mode === 'woovi_url' || ! $evidence->woovi_file_id
                    ? $client->uploadEvidenceFile($evidence->contents(), $evidence->original_name, $evidence->mime_type, $evidence->correlation_id)
                    : [];

                if (filled($file['id'] ?? null) && $file['id'] !== $evidence->woovi_file_id) {
                    $evidence->forceFill(['woovi_file_id' => $file['id']])->save();
                }

                if ($mode === 'file_id' && $evidence->woovi_file_id) {
                    return [['fileId' => $evidence->woovi_file_id] + $base, $mode];
                }

                if ($mode === 'woovi_url' && filled($file['url'] ?? null)) {
                    return [['url' => (string) $file['url']] + $base, $mode];
                }
            } catch (WooviException $e) {
                if (! $e->isFileIdNotAllowed()) {
                    throw $e;
                }

                // Conta sem FILE_POST (ou sem a feature): segue pelo link deste sistema,
                // para este e os próximos arquivos.
                $this->events->record(
                    DisputeEventType::EVIDENCE_SEND_FAILED,
                    $dispute,
                    'Conta sem permissão de subir arquivo na Woovi - enviando pelo link deste sistema.',
                    ['status' => $e->status, 'error_code' => $e->errorCode],
                    level: 'warning',
                );
            }

            $mode = 'url';
        }

        $url = URL::temporarySignedRoute(
            'med.evidence.download',
            now()->addMinutes((int) config('services.woovi.evidence_url_ttl_minutes', 1440)),
            ['evidence' => $evidence->id],
        );

        return [['url' => $url] + $base, $mode];
    }

    public static function modeLabel(string $mode): string
    {
        return match ($mode) {
            'file_id' => 'arquivos pelo id na Woovi',
            'woovi_url' => 'arquivos guardados na Woovi, pelo link dela',
            default => 'arquivos pelo link deste sistema',
        };
    }

    /**
     * @param  iterable<DisputeEvidence>  $pending
     * @param  array<string, mixed>  $context
     */
    private function fail(Dispute $dispute, iterable $pending, string $error, array $context, string $source): void
    {
        foreach ($pending as $evidence) {
            $evidence->forceFill(['last_error' => $error])->save();
        }

        $this->events->record(DisputeEventType::EVIDENCE_SEND_FAILED, $dispute, $error, $context, level: 'error', source: $source);
        $this->discord->evidenceSendFailed($dispute, $error);
    }
}
