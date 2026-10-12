<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeEventType;
use App\Http\Controllers\Controller;
use App\Models\Dispute;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Services\DisputeEventRecorder;
use App\Services\Kapso\KapsoClient;
use App\Services\WhatsAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * POST /api/webhooks/kapso - eventos do número oficial na Kapso.
 *
 * O número é o mesmo do KYC, e a Kapso manda a este webhook TODA mensagem do
 * número. Por isso o MED só age no que é dele: status de mensagem que ele mandou
 * (pelo wamid) e resposta de telefone que recebeu template do MED - de
 * preferência citando o template (context.id), senão um template recente ainda
 * esperando resposta. O resto é ignorado.
 */
class KapsoWebhookController extends Controller
{
    private const EVENT_RECEIVED = 'whatsapp.message.received';

    private const DELIVERY_EVENTS = [
        'whatsapp.message.sent' => 'sent',
        'whatsapp.message.delivered' => 'delivered',
        'whatsapp.message.read' => 'read',
        'whatsapp.message.failed' => 'failed',
    ];

    /**
     * Ordem que a entrega só avança (um "delivered" atrasado não desfaz o "read").
     */
    private const DELIVERY_RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];

    /**
     * Mensagem inteira que é pedido para parar (texto normalizado: minúsculas, sem
     * acento nem pontuação) - o mesmo vocabulário da régua do KYC.
     */
    private const STOP_WORDS = [
        'pare', 'parar', 'parem', 'stop', 'sair', 'descadastrar', 'me descadastra', 'me descadastre',
        'sair da lista', 'me exclui da lista', 'me tira da lista', 'nao quero receber', 'nao quero mais receber',
        'parar de receber', 'quero parar de receber', 'bloquear', 'denunciar',
    ];

    private const STOP_PATTERN = '/\bme (?:exclu|remov|tir|retir)\w* da lista\b'
        .'|\bpar(?:a|e|em|ar) de (?:me )?(?:mandar|enviar) (?:mensage\w*|isso|nada)\b'
        .'|\bnao (?:me )?(?:manda|mande|mandem|envia|envie|enviem) mais (?:nada|mensage\w*|isso)\b/';

    public function __invoke(Request $request, WhatsAppNotifier $notifier, DisputeEventRecorder $events): JsonResponse
    {
        $secret = (string) config('services.kapso.webhook_secret');

        if ($secret === '') {
            return response()->json(['error' => 'webhook da Kapso não configurado'], 503);
        }

        $raw = (string) $request->getContent();

        if (! KapsoClient::validSignature($raw, $request->header('X-Webhook-Signature'), $secret)) {
            return response()->json(['error' => 'assinatura inválida'], 401);
        }

        $body = (array) json_decode($raw, true);
        $event = (string) ($request->header('X-Webhook-Event') ?: ($body['event'] ?? $body['type'] ?? ''));

        // Com buffer ligado na Kapso, vários payloads do mesmo evento chegam juntos.
        $payloads = ($body['batch'] ?? false) === true
            ? array_values(array_filter((array) ($body['data'] ?? []), 'is_array'))
            : [$body];

        $handled = 0;

        foreach ($payloads as $payload) {
            $message = (array) ($payload['message'] ?? []);

            $handled += match (true) {
                $event === self::EVENT_RECEIVED => $this->received($message, $notifier, $events),
                isset(self::DELIVERY_EVENTS[$event]) => $this->delivery(self::DELIVERY_EVENTS[$event], $message, $events),
                default => 0,
            };
        }

        return response()->json(['ok' => true, 'processados' => $handled]);
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function delivery(string $status, array $message, DisputeEventRecorder $events): int
    {
        $sent = WhatsAppMessage::query()->with('dispute')->where('wamid', (string) ($message['id'] ?? ''))->first();

        if (! $sent || (self::DELIVERY_RANK[$sent->delivery_status] ?? 0) >= self::DELIVERY_RANK[$status]) {
            return 0;
        }

        $sent->delivery_status = $status;

        if ($status === 'failed') {
            $error = (array) data_get($message, 'kapso.statuses.0.errors.0', data_get($message, 'errors.0', []));
            $sent->status = 'failed';
            $sent->error = 'Não entregue: '.KapsoClient::translate(0, $error['code'] ?? null, (string) ($error['message'] ?? $error['title'] ?? ''));
            $events->record(DisputeEventType::WHATSAPP_DELIVERY_FAILED, $sent->dispute, $sent->error, ['wamid' => $sent->wamid], level: 'warning', source: 'kapso');
        }

        $sent->save();

        return 1;
    }

    /**
     * Resposta do seller: pedido para parar, ou o "pode mandar" que libera o link.
     *
     * @param  array<string, mixed>  $message
     */
    private function received(array $message, WhatsAppNotifier $notifier, DisputeEventRecorder $events): int
    {
        // O tipo do evento vem do header, fora da assinatura: status de envio
        // reapresentado como "received" não passa por resposta do seller.
        $direction = data_get($message, 'kapso.direction');

        if ($direction !== null && $direction !== 'inbound') {
            return 0;
        }

        $recipient = KapsoClient::recipient('+'.preg_replace('/\D/', '', (string) ($message['from'] ?? '')));

        if ($recipient === null) {
            return 0;
        }

        $templates = $this->templatesAnswered($message, $recipient);

        if ($templates->isEmpty()) {
            return 0;
        }

        $text = $this->text($message);

        if ($this->isStopRequest($text)) {
            WhatsAppOptOut::query()->firstOrCreate(['recipient' => $recipient], ['message' => Str::limit($text, 500), 'requested_at' => now()]);

            foreach ($templates as $template) {
                $template->forceFill(['replied_at' => now(), 'link_sent_at' => now()])->save();
                $events->record(DisputeEventType::WHATSAPP_OPT_OUT, $template->dispute, "Seller pediu para parar o WhatsApp (\"{$text}\"). O aviso segue só por e-mail.", source: 'seller');
            }

            return $templates->count();
        }

        foreach ($templates as $template) {
            $template->forceFill(['replied_at' => now()])->save();
            $dispute = $template->dispute;

            $events->record(DisputeEventType::WHATSAPP_REPLIED, $dispute, 'Seller respondeu o template no WhatsApp'.($text !== '' ? ": \"{$text}\"" : '.'), source: 'seller');

            // Caso que já foi respondido, enviado ou encerrado não precisa mais do link.
            if ($dispute->status->acceptsSellerSubmission() && ! $dispute->isTokenExpired()) {
                $notifier->sendLink($dispute, $recipient, $dispute->complement_request, $template);
            } else {
                $template->forceFill(['link_sent_at' => now()])->save();
            }
        }

        return $templates->count();
    }

    /**
     * Templates do MED que esta mensagem responde: o citado (context.id), ou os do
     * telefone que ainda esperam resposta dentro da janela. Nunca templates de caso
     * de outro telefone.
     *
     * @param  array<string, mixed>  $message
     * @return Collection<int, WhatsAppMessage>
     */
    private function templatesAnswered(array $message, string $recipient): Collection
    {
        $pending = WhatsAppMessage::query()
            ->with('dispute.wooviAccount')
            ->where('type', WhatsAppMessage::TYPE_TEMPLATE)
            ->where('recipient', $recipient)
            ->where('status', 'sent')
            ->whereNull('link_sent_at');

        $quoted = (string) data_get($message, 'context.id', '');

        if ($quoted !== '') {
            $cited = (clone $pending)->where('wamid', $quoted)->get();

            // Citou outra mensagem (do KYC ou do atendimento): a conversa não é do MED.
            return $cited->isNotEmpty() || WhatsAppMessage::query()->where('wamid', $quoted)->exists()
                ? $cited
                : collect();
        }

        return $pending
            ->where('created_at', '>=', now()->subDays((int) config('med.whatsapp.janela_resposta_dias')))
            ->get()
            ->filter(fn (WhatsAppMessage $template): bool => $template->dispute instanceof Dispute)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function text(array $message): string
    {
        foreach (['kapso.content', 'text.body', 'button.text', 'interactive.button_reply.title'] as $path) {
            $value = trim((string) data_get($message, $path, ''));

            if ($value !== '') {
                return Str::limit($value, 300);
            }
        }

        return '';
    }

    private function isStopRequest(string $text): bool
    {
        $normal = trim((string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text))));
        $core = trim((string) preg_replace('/^(?:(?:pode|por favor|favor|ok) )+|(?: (?:por favor|obrigad[oa]|valeu|ok))+$/', '', $normal));

        return in_array($normal, self::STOP_WORDS, true)
            || in_array($core, self::STOP_WORDS, true)
            || preg_match(self::STOP_PATTERN, $normal) === 1;
    }
}
