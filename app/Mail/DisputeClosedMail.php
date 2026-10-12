<?php

namespace App\Mail;

use App\Models\Dispute;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Resultado do MED para o seller.
 */
class DisputeClosedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Dispute $dispute) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Resultado da contestação de Pix: '.$this->dispute->status->label());
    }

    public function content(): Content
    {
        return new Content(view: 'mail.dispute-closed', with: ['dispute' => $this->dispute]);
    }
}
