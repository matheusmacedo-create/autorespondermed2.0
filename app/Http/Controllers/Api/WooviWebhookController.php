<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeEventType;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooviWebhookCall;
use App\Models\WooviAccount;
use App\Models\WooviWebhookCall;
use App\Services\DisputeEventRecorder;
use App\Services\Woovi\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /api/webhooks/woovi/{webhookKey}
 *
 * A URL identifica a conta (o payload de disputa da Woovi não diz de onde veio)
 * e a assinatura x-webhook-signature prova que quem chamou foi a Woovi. A
 * chamada é gravada crua antes de tudo e processada na fila: o webhook responde
 * rápido e, se o processamento falhar, o corpo fica para reprocessar.
 */
class WooviWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $webhookKey,
        WebhookSignatureVerifier $verifier,
        DisputeEventRecorder $events,
    ): JsonResponse {
        $account = WooviAccount::query()->where('webhook_key', $webhookKey)->first();

        if (! $account) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $rawBody = (string) $request->getContent();
        $payload = json_decode($rawBody, true);
        $event = is_array($payload) ? ($payload['event'] ?? $payload['evento'] ?? null) : null;
        $signatureValid = $verifier->verify($rawBody, $request->header('x-webhook-signature'));

        $call = WooviWebhookCall::create([
            'woovi_account_id' => $account->id,
            'event' => is_string($event) ? Str::limit($event, 60, '') : null,
            'signature_valid' => $signatureValid,
            'payload' => $rawBody,
            'headers' => array_filter([
                'x-webhook-signature' => $request->header('x-webhook-signature'),
                'x-openpix-signature' => $request->header('x-openpix-signature'),
                'user-agent' => $request->userAgent(),
                'content-type' => $request->header('content-type'),
                'ip' => $request->ip(),
            ]),
        ]);

        // Teste de cadastro do webhook: a Woovi manda `{data_criacao, event}` com o
        // evento escolhido (inclusive OPENPIX:DISPUTE_*) e sem o objeto `dispute`, e
        // espera 200. Sem dado nenhum, não dispara nada - por isso responde 200 mesmo
        // sem assinatura válida: recusar aqui poderia impedir o cadastro do webhook.
        if (! is_array($payload) || ! isset($payload['dispute'])) {
            $call->forceFill([
                'processed_at' => now(),
                'error' => $signatureValid ? null : 'Chamada sem disputa e sem assinatura válida (ignorada).',
            ])->save();

            return response()->json([]);
        }

        if (! $signatureValid) {
            $call->forceFill(['error' => 'Assinatura x-webhook-signature inválida ou ausente.'])->save();
            $events->record(
                DisputeEventType::WEBHOOK_REJECTED,
                null,
                'Webhook recusado: assinatura inválida.',
                ['webhook_call_id' => $call->id, 'event' => $event],
                level: 'warning',
                source: 'woovi',
                account: $account,
            );

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        if (is_string($event) && str_starts_with($event, 'OPENPIX:DISPUTE_')) {
            ProcessWooviWebhookCall::dispatch($call->id);
        } else {
            // Evento que não é de disputa: só fica no log.
            $call->forceFill(['processed_at' => now()])->save();
        }

        return response()->json(['received' => true]);
    }
}
