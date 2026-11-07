<?php

namespace App\Services;

use App\Enums\DisputeEventType;
use App\Mail\DisputeClosedMail;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Avisa o seller do MED: e-mail de contato da conta Woovi e, com a Kapso e o
 * template configurados, WhatsApp oficial ({@see WhatsAppNotifier}). Cada tentativa
 * (enviada ou não) vira evento na linha do tempo: o analista precisa saber se o
 * seller foi avisado antes de cobrar a defesa.
 */
class SellerNotifier
{
    public function __construct(
        private readonly DisputeEventRecorder $events,
        private readonly WhatsAppNotifier $whatsapp,
    ) {}

    /**
     * Devolve true quando o seller foi avisado por pelo menos um canal.
     */
    public function disputeOpened(Dispute $dispute, ?string $complementRequest = null): bool
    {
        if (! self::enabled()) {
            return false;
        }

        // WhatsApp não decide o e-mail nem o contrário: um canal falhar não segura o outro.
        $byWhatsApp = rescue(fn (): bool => $this->whatsapp->notify($dispute, $complementRequest), false);

        return $this->email($dispute, $complementRequest) || $byWhatsApp;
    }

    private function email(Dispute $dispute, ?string $complementRequest): bool
    {
        $email = $dispute->seller()['email'];

        if (! $email) {
            $this->events->record(
                DisputeEventType::SELLER_NOTIFICATION_FAILED,
                $dispute,
                'Lojista sem e-mail de contato (nem na venda nem na conta): avise pelo link da ficha.',
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

    /**
     * Aviso ao lojista ligado (`MED_AVISO_LOJISTA`). Desligado, nada sai - nem
     * e-mail nem WhatsApp - e o caso segue sendo montado normalmente.
     */
    public static function enabled(): bool
    {
        return (bool) config('med.aviso_lojista');
    }

    public function disputeClosed(Dispute $dispute): void
    {
        if (! self::enabled()) {
            return;
        }

        $email = $dispute->seller()['email'];

        if (! $email) {
            return;
        }

        rescue(fn () => Mail::to($email)->send(new DisputeClosedMail($dispute)), report: false);
    }
}
