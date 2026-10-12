<?php

namespace App\Mail;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Aviso ao seller: um Pix que ele recebeu foi contestado (MED) e ele tem até o
 * prazo para mandar a defesa pelo link. Também serve para o pedido de
 * complemento (com a mensagem do analista).
 */
class DisputeOpenedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public Dispute $dispute,
        public ?string $complementRequest = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->complementRequest
            ? 'Precisamos de mais informações sobre uma contestação de Pix'
            : 'Um Pix recebido foi contestado - envie sua defesa';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.dispute-opened',
            with: [
                'dispute' => $this->dispute,
                'complementRequest' => $this->complementRequest,
                'link' => $this->dispute->sellerLink(),
                'deadline' => $this->dispute->respond_by?->timezone('America/Sao_Paulo')->format('d/m/Y \à\s H:i'),
            ],
        );
    }
}
