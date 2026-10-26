<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Models\Dispute;
use App\Services\DisputeEventRecorder;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;

/**
 * Relê um caso na Woovi (GET /dispute/{endToEndId}) e aplica o que mudou.
 */
class SyncDisputeAction
{
    public function __construct(
        private readonly IngestDisputeAction $ingest,
        private readonly DisputeEventRecorder $events,
    ) {}

    public function handle(Dispute $dispute): Dispute
    {
        $dispute->loadMissing('wooviAccount');

        try {
            $payload = WooviClient::for($dispute->wooviAccount)->getDispute($dispute->end_to_end_id);
        } catch (WooviException $e) {
            $this->events->record(DisputeEventType::SYNC_FAILED, $dispute, $e->getMessage(), ['status' => $e->status], level: 'error');

            throw $e;
        }

        if ($payload === []) {
            return $dispute;
        }

        $payload['endToEndId'] ??= $dispute->end_to_end_id;

        // Releitura sem novidade não vira evento (seria um por hora na linha do tempo):
        // o que mudou a ingestão já registra (status na Woovi, encerramento).
        return $this->ingest->handle($dispute->wooviAccount, $payload, 'sync');
    }
}
