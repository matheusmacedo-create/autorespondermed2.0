<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\EvidenceKind;
use App\Jobs\ProcessSellerDefense;
use App\Models\Dispute;
use App\Services\DiscordNotificationService;
use App\Services\DisputeEventRecorder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Defesa enviada pelo seller pelo link público. Vale também para o complemento
 * pedido pelo analista: o texto novo entra no fim do relato, não por cima.
 */
class SubmitSellerDefenseAction
{
    public function __construct(
        private readonly StoreEvidenceFile $storeEvidence,
        private readonly DisputeEventRecorder $events,
        private readonly DiscordNotificationService $discord,
    ) {}

    /**
     * @param  array<int, array{kind: string, description: string, file: UploadedFile}>  $evidences
     * @param  array<string, mixed>  $metadata
     * @param  array{order_reference?: ?string, tracking_code?: ?string}  $details  o que o dossiê pediu ao seller
     */
    public function handle(Dispute $dispute, string $contactName, string $statement, array $evidences, array $metadata = [], array $details = []): Dispute
    {
        $duplicates = 0;

        $dispute = DB::transaction(function () use ($dispute, $contactName, $statement, $evidences, $metadata, $details, &$duplicates) {
            // Relê com trava: a aba pode ter ficado aberta enquanto o caso andou
            // (padrão "estado lido de retrato antigo" do KYC).
            $fresh = Dispute::query()->lockForUpdate()->findOrFail($dispute->id);

            if (! $fresh->status->acceptsSellerSubmission() || $fresh->isTokenExpired()) {
                throw new RuntimeException('Este caso não aceita mais envio pelo link.');
            }

            $isComplement = $fresh->seller_submitted_at !== null;
            $statement = trim($statement);

            $fresh->seller_statement = $isComplement && $fresh->seller_statement
                ? $fresh->seller_statement."\n\n--- Complemento em ".now()->timezone('America/Sao_Paulo')->format('d/m/Y H:i')." ---\n".$statement
                : $statement;

            foreach ($evidences as $evidence) {
                $stored = $this->storeEvidence->handle(
                    $fresh,
                    $evidence['file'],
                    EvidenceKind::from($evidence['kind']),
                    $evidence['description'],
                    'seller',
                );

                $duplicates += $stored === null ? 1 : 0;
            }

            // O que o seller informou soma ao que já havia (complemento não apaga o
            // rastreio dado antes).
            $details = array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : null, $details));

            $fresh->fill([
                'seller_contact_name' => trim($contactName),
                'seller_submitted_at' => now(),
                'seller_submission_metadata' => $metadata + ['duplicates_skipped' => $duplicates],
                'seller_details' => array_merge((array) $fresh->seller_details, $details) ?: null,
                'complement_request' => null,
                'status' => DisputeStatus::SELLER_SUBMITTED,
            ])->save();

            return $fresh;
        });

        $this->events->record(
            DisputeEventType::SELLER_SUBMITTED,
            $dispute,
            sprintf('Seller enviou a defesa com %d arquivo(s).', count($evidences) - $duplicates),
            ['files' => count($evidences) - $duplicates, 'ip' => $metadata['ip_address'] ?? null],
            source: 'seller',
        );

        if ($duplicates > 0) {
            $this->events->record(
                DisputeEventType::DUPLICATE_EVIDENCE_SKIPPED,
                $dispute,
                "{$duplicates} arquivo(s) idêntico(s) a evidências que o caso já tinha foram ignorados.",
                ['duplicates' => $duplicates],
                source: 'seller',
            );
        }

        $this->discord->sellerSubmitted($dispute->loadMissing('wooviAccount'));

        // Cruza com o dossiê, completa o que faltava e - se a opção e as travas
        // deixarem - envia sozinho. Fora da requisição do seller.
        if (config('med.dossie.habilitado') || config('med.envio_automatico.habilitado')) {
            rescue(fn () => ProcessSellerDefense::dispatch($dispute->id), report: true);
        }

        return $dispute;
    }
}
