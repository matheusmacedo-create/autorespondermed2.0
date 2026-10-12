<?php

namespace App\Actions\Med;

use App\Models\WooviAccount;
use App\Services\Woovi\WooviClient;

/**
 * Varre as disputas de uma conta na Woovi (GET /dispute) e passa cada uma pela
 * ingestão. É a rede de segurança do webhook: MED que o webhook perdeu (conta
 * sem webhook cadastrado, entrega que falhou) entra por aqui.
 */
class SyncAccountDisputesAction
{
    public function __construct(private readonly IngestDisputeAction $ingest) {}

    /**
     * @return array{seen: int, created: int}
     */
    public function handle(WooviAccount $account, int $days = 30): array
    {
        $disputes = WooviClient::for($account)->listDisputes(
            start: now()->subDays($days)->toIso8601ZuluString(),
            end: now()->toIso8601ZuluString(),
        );

        $created = 0;

        foreach ($disputes as $payload) {
            if (! is_array($payload) || empty($payload['endToEndId'])) {
                continue;
            }

            $dispute = $this->ingest->handle($account, $payload, 'sync');
            $created += $dispute->wasRecentlyCreated ? 1 : 0;
        }

        $account->forceFill(['last_synced_at' => now()])->save();

        return ['seen' => count($disputes), 'created' => $created];
    }
}
