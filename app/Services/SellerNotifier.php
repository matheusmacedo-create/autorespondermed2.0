<?php

namespace App\Services;

use App\Enums\DisputeEventType;
use App\Mail\DisputeClosedMail;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa o seller do MED pelo e-mail de contato da conta Woovi. Cada tentativa
 * (enviada ou não) vira evento na linha do tempo: o analista precisa saber se o
 * seller foi avisado antes de cobrar a defesa.
 *
 * WhatsApp (Kapso, como no KYC) entra aqui quando houver template de MED
 * aprovado na Meta - o link não pode ir no template, então segue o mesmo
 * desenho de dois passos do KYC (KB 8.23 de lá).
 */
class SellerNotifier
{
    public function __construct(private readonly DisputeEventRecorder $events) {}

    public function disputeOpened(Dispute $dispute, ?string $complementRequest = null): bool
    {
        $email = $dispute->wooviAccount->contact_email;

        if (! $email) {
            $this->events->record(
                DisputeEventType::SELLER_NOTIFICATION_FAILED,
                $dispute,
                'Conta sem e-mail de contato: avise o seller pelo link da ficha.',
                level: 'warning',
            );

            return false;
        }

        try {
            Mail::to($email)->send(new DisputeOpenedMail($dispute, $complementRequest));
        } catch (Throwable $e) {
            $this->events->record(
                DisputeEventType::SELLER_NOTIFICATION_FAILED,
                $dispute,
                "Falha ao enviar o e-mail para {$email}.",
                ['error' => $e->getMessage()],
                level: 'error',
            );

            return false;
        }

        $dispute->forceFill(['seller_notified_at' => now()])->save();

        $this->events->record(
            DisputeEventType::SELLER_NOTIFIED,
            $dispute,
            $complementRequest ? "Pedido de complemento enviado para {$email}." : "Aviso do MED enviado para {$email}.",
            ['channel' => 'email', 'to' => $email],
        );

        return true;
    }

    public function disputeClosed(Dispute $dispute): void
    {
        $email = $dispute->wooviAccount->contact_email;

        if (! $email) {
            return;
        }

        rescue(fn () => Mail::to($email)->send(new DisputeClosedMail($dispute)), report: false);
    }
}
