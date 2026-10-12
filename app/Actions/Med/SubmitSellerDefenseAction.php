<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\EvidenceKind;
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
     */
    public function handle(Dispute $dispute, string $contactName, string $statement, array $evidences, array $metadata = []): Dispute
    {
        $dispute = DB::transaction(function () use ($dispute, $contactName, $statement, $evidences, $metadata) {
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
                $this->storeEvidence->handle(
                    $fresh,
                    $evidence['file'],
                    EvidenceKind::from($evidence['kind']),
                    $evidence['description'],
                    'seller',
                );
            }

            $fresh->fill([
                'seller_contact_name' => trim($contactName),
                'seller_submitted_at' => now(),
                'seller_submission_metadata' => $metadata,
                'complement_request' => null,
                'status' => DisputeStatus::SELLER_SUBMITTED,
            ])->save();

            return $fresh;
        });

        $this->events->record(
            DisputeEventType::SELLER_SUBMITTED,
            $dispute,
            sprintf('Seller enviou a defesa com %d arquivo(s).', count($evidences)),
            ['files' => count($evidences), 'ip' => $metadata['ip_address'] ?? null],
            source: 'seller',
        );

        $this->discord->sellerSubmitted($dispute->loadMissing('wooviAccount'));

        return $dispute;
    }
}
