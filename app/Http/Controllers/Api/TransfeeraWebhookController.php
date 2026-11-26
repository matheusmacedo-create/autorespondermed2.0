<?php

namespace App\Http\Controllers\Api;

use App\Enums\DisputeEventType;
use App\Enums\Provider;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessWooviWebhookCall;
use App\Models\WooviAccount;
use App\Models\WooviWebhookCall;
use App\Services\DisputeEventRecorder;
use App\Services\Transfeera\WebhookSignatureVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * POST /api/webhooks/transfeera/{webhookKey}
 *
 * Mesmo desenho do webhook da Woovi: a chave na URL diz a conta, a assinatura
 * (`Transfeera-Signature`, HMAC do corpo bruto com o segredo do webhook da conta)
 * prova quem chamou, e a chamada é gravada crua antes de ir para a fila. A
 * Transfeera tenta de novo só 2 vezes quando não recebe 2xx - a sincronização de
 * hora em hora cobre o que se perder.
 */
class TransfeeraWebhookController extends Controller
{
    public function __invoke(
        Request $request,
        string $webhookKey,
        WebhookSignatureVerifier $verifier,
        DisputeEventRecorder $events,
    ): JsonResponse {
        $account = WooviAccount::query()
            ->where('webhook_key', $webhookKey)
            ->where('provider', Provider::TRANSFEERA->value)
            ->first();

        if (! $account) {
            return response()->json(['error' => 'Not found'], 404);
        }

        $rawBody = (string) $request->getContent();
        $payload = json_decode($rawBody, true);
        $object = is_array($payload) ? ($payload['object'] ?? null) : null;
        $signatureValid = $verifier->verify($rawBody, $request->header('Transfeera-Signature'), $account->webhook_secret);

        $call = WooviWebhookCall::create([
            'woovi_account_id' => $account->id,
            'event' => is_string($object) ? Str::limit($object, 60, '') : null,
            'signature_valid' => $signatureValid,
            'payload' => $rawBody,
            'headers' => array_filter([
                'transfeera-signature' => $request->header('Transfeera-Signature'),
                'user-agent' => $request->userAgent(),
                'content-type' => $request->header('content-type'),
                'ip' => $request->ip(),
            ]),
        ]);

        // Outro tipo de objeto (ou chamada de teste): fica só no log, sem processar.
        if ($object !== 'Infraction' || ! is_array($payload['data'] ?? null)) {
            $call->forceFill(['processed_at' => now()])->save();

            return response()->json([]);
        }

        if (! $signatureValid) {
            $reason = blank($account->webhook_secret)
                ? 'Webhook recusado: a conta não tem o segredo do webhook da Transfeera (rode "Cadastrar webhooks").'
                : 'Webhook recusado: assinatura inválida.';

            $call->forceFill(['error' => $reason])->save();
            $events->record(
                DisputeEventType::WEBHOOK_REJECTED,
                null,
                $reason,
                ['webhook_call_id' => $call->id, 'event' => $object],
                level: 'warning',
                source: 'transfeera',
                account: $account,
            );

            return response()->json(['error' => 'Invalid signature'], 401);
        }

        ProcessWooviWebhookCall::dispatch($call->id);

        return response()->json(['received' => true]);
    }
}
