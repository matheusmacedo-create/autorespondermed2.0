<?php

namespace App\Services;

use App\Enums\DisputeEventType;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\WooviAccount;

/**
 * Grava a linha do tempo do caso (dispute_events). Best-effort por contrato,
 * como o OnboardingEventRecorder do KYC: telemetria nunca derruba o fluxo -
 * nem o recebimento do webhook, nem a defesa do seller.
 */
class DisputeEventRecorder
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function record(
        DisputeEventType $event,
        ?Dispute $dispute,
        string $message,
        array $context = [],
        string $level = 'info',
        string $source = 'system',
        ?WooviAccount $account = null,
    ): void {
        rescue(function () use ($event, $dispute, $message, $context, $level, $source, $account): void {
            DisputeEvent::create([
                'dispute_id' => $dispute?->id,
                'woovi_account_id' => $dispute?->woovi_account_id ?? $account?->id,
                'event' => $event->value,
                'level' => $level,
                'source' => $source,
                'message' => $message,
                'context' => $context,
                'user_id' => auth()->id(),
                'occurred_at' => now(),
            ]);
        }, report: false);
    }
}
