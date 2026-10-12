<?php

namespace App\Jobs;

use App\Actions\Med\IngestDisputeAction;
use App\Models\WooviWebhookCall;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Processa uma chamada de webhook já gravada. Erro fica na própria chamada
 * (woovi_webhook_calls.error) e o job tenta de novo com espera crescente.
 */
class ProcessWooviWebhookCall implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public int $webhookCallId) {}

    public function handle(IngestDisputeAction $ingest): void
    {
        $call = WooviWebhookCall::query()->with('wooviAccount')->find($this->webhookCallId);

        if (! $call || $call->processed_at || ! $call->wooviAccount) {
            return;
        }

        $payload = $call->decodedPayload();
        $dispute = (array) ($payload['dispute'] ?? []);

        try {
            $record = $ingest->handle($call->wooviAccount, $dispute, 'webhook', $call->event);

            $call->forceFill([
                'dispute_id' => $record->id,
                'processed_at' => now(),
                'error' => null,
            ])->save();
        } catch (Throwable $e) {
            $call->forceFill(['error' => mb_substr($e->getMessage(), 0, 2000)])->save();

            throw $e;
        }
    }
}
