<?php

namespace App\Services;

use App\Enums\DisputeEventType;
use App\Models\Dispute;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Services\Kapso\KapsoClient;
use App\Services\Kapso\KapsoException;
use Illuminate\Support\Str;

/**
 * Aviso de MED pelo WhatsApp oficial (Kapso), em dois passos:
 *
 *  1. sai o template aprovado (abre a conversa, sem link);
 *  2. quando o seller responde, o webhook da Kapso chama {@see sendLink()} e o link da
 *     defesa vai como texto - a resposta abriu a janela de 24h da Meta.
 *
 * Se o seller falou com a ÚnicoPag nas últimas 24h, a janela já está aberta e o link
 * vai direto, sem template. Telefone que pediu para parar não recebe nada.
 */
class WhatsAppNotifier
{
    public function __construct(
        private readonly KapsoClient $kapso,
        private readonly DisputeEventRecorder $events,
    ) {}

    public function isEnabled(): bool
    {
        return $this->kapso->isConfigured() && filled(config('med.whatsapp.template'));
    }

    /**
     * Avisa o seller do caso. Devolve true quando alguma mensagem saiu.
     */
    public function notify(Dispute $dispute, ?string $complementRequest = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $recipient = KapsoClient::recipient($dispute->seller()['phone']);

        if ($recipient === null) {
            $this->events->record(DisputeEventType::SELLER_NOTIFICATION_FAILED, $dispute, 'WhatsApp não enviado: conta sem celular brasileiro válido.', ['channel' => 'whatsapp'], level: 'warning');

            return false;
        }

        if (WhatsAppOptOut::blocks($recipient)) {
            $this->events->record(DisputeEventType::SELLER_NOTIFICATION_FAILED, $dispute, 'WhatsApp não enviado: o seller pediu para parar de receber. Segue só o e-mail.', ['channel' => 'whatsapp'], level: 'warning');

            return false;
        }

        if ($this->windowIsOpen($recipient)) {
            return $this->sendLink($dispute, $recipient, $complementRequest) !== null;
        }

        return $this->sendTemplate($dispute, $recipient);
    }

    /**
     * Texto com o link da defesa (só entrega dentro da janela de 24h).
     */
    public function sendLink(Dispute $dispute, string $recipient, ?string $complementRequest = null, ?WhatsAppMessage $inReplyTo = null): ?WhatsAppMessage
    {
        $dispute->loadMissing('wooviAccount');

        try {
            $wamid = $this->kapso->sendText($recipient, $this->linkText($dispute, $complementRequest));
        } catch (KapsoException $e) {
            $this->fail($dispute, $recipient, WhatsAppMessage::TYPE_LINK, $e);

            return null;
        }

        $message = $dispute->whatsappMessages()->create([
            'type' => WhatsAppMessage::TYPE_LINK,
            'recipient' => $recipient,
            'wamid' => $wamid,
            'status' => 'sent',
        ]);

        $inReplyTo?->forceFill(['link_sent_at' => now()])->save();
        $dispute->forceFill(['seller_notified_at' => now()])->save();

        $this->events->record(DisputeEventType::SELLER_NOTIFIED, $dispute, "Link da defesa enviado por WhatsApp para +{$recipient}.", ['channel' => 'whatsapp', 'wamid' => $wamid]);

        return $message;
    }

    /**
     * O seller falou com o número nas últimas 24h (respondeu um template do MED)?
     */
    public function windowIsOpen(string $recipient): bool
    {
        return WhatsAppMessage::query()
            ->where('recipient', $recipient)
            ->where('replied_at', '>=', now()->subHours(24))
            ->exists();
    }

    private function sendTemplate(Dispute $dispute, string $recipient): bool
    {
        $firstName = Str::of((string) ($dispute->seller()['contact_name'] ?: $dispute->seller()['name']))->trim()->before(' ')->title()->value();

        try {
            $wamid = $this->kapso->sendTemplate(
                $recipient,
                (string) config('med.whatsapp.template'),
                (string) config('med.whatsapp.template_language'),
                [$firstName !== '' ? $firstName : 'tudo bem'],
            );
        } catch (KapsoException $e) {
            $this->fail($dispute, $recipient, WhatsAppMessage::TYPE_TEMPLATE, $e);

            return false;
        }

        $dispute->whatsappMessages()->create([
            'type' => WhatsAppMessage::TYPE_TEMPLATE,
            'recipient' => $recipient,
            'wamid' => $wamid,
            'status' => 'sent',
        ]);

        $dispute->forceFill(['seller_notified_at' => now()])->save();

        $this->events->record(DisputeEventType::SELLER_NOTIFIED, $dispute, "Template de WhatsApp enviado para +{$recipient}: o link vai quando o seller responder.", ['channel' => 'whatsapp', 'wamid' => $wamid]);

        return true;
    }

    private function linkText(Dispute $dispute, ?string $complementRequest): string
    {
        $deadline = $dispute->respond_by?->timezone('America/Sao_Paulo')->format('d/m \à\s H:i');

        $lines = $complementRequest
            ? ["Nossa equipe precisa de mais informações sobre a contestação do Pix de {$dispute->formattedValue()} recebido pela conta {$dispute->wooviAccount->name}:", '', $complementRequest]
            : ["O pagador de um Pix de {$dispute->formattedValue()} recebido pela conta {$dispute->wooviAccount->name} pediu a devolução pelo MED (Mecanismo Especial de Devolução do Banco Central)."];

        return implode("\n", array_filter([
            ...$lines,
            '',
            'Se a venda foi legítima, envie seu relato e os comprovantes por este link'.($deadline ? " até {$deadline}" : '').':',
            $dispute->sellerLink(),
        ], fn (?string $line): bool => $line !== null));
    }

    private function fail(Dispute $dispute, string $recipient, string $type, KapsoException $e): void
    {
        $dispute->whatsappMessages()->create([
            'type' => $type,
            'recipient' => $recipient,
            'status' => 'failed',
            'error' => mb_substr($e->getMessage(), 0, 2000),
        ]);

        $this->events->record(DisputeEventType::SELLER_NOTIFICATION_FAILED, $dispute, 'WhatsApp não enviado: '.$e->getMessage(), ['channel' => 'whatsapp', 'code' => $e->codigo], level: 'error');
    }
}
