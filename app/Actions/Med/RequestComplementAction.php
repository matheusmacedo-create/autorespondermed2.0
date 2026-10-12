<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Services\DisputeEventRecorder;
use App\Services\SellerNotifier;
use RuntimeException;

/**
 * Analista devolve o caso ao seller pedindo mais evidência. O link volta a
 * aceitar envio (até o fim do prazo) e o seller recebe a mensagem do analista.
 */
class RequestComplementAction
{
    public function __construct(
        private readonly DisputeEventRecorder $events,
        private readonly SellerNotifier $sellerNotifier,
    ) {}

    public function handle(Dispute $dispute, string $message): Dispute
    {
        if ($dispute->status !== DisputeStatus::SELLER_SUBMITTED && $dispute->status !== DisputeStatus::PENDING_SELLER) {
            throw new RuntimeException('Só dá para pedir complemento antes do envio à Woovi.');
        }

        $deadline = $dispute->respond_by?->copy()->addHours((int) config('med.link.folga_horas'));

        $dispute->fill([
            'status' => DisputeStatus::PENDING_SELLER,
            'complement_request' => trim($message),
            // Reabre o link se ele já tinha expirado, mas nunca além do prazo + folga.
            'token_expires_at' => $deadline && $deadline->isFuture() ? $deadline : now()->addHours(24),
        ])->save();

        $this->events->record(
            DisputeEventType::COMPLEMENT_REQUESTED,
            $dispute,
            'Complemento pedido ao seller: '.$message,
            source: 'analyst',
        );

        $this->sellerNotifier->disputeOpened($dispute, $message);

        return $dispute;
    }
}
