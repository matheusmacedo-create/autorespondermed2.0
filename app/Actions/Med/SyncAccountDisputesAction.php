<?php

namespace App\Actions\Med;

use App\Models\WooviAccount;
use App\Services\Transfeera\TransfeeraClient;
use App\Services\Woovi\WooviClient;

/**
 * Varre as disputas de uma conta na instituição (Woovi: GET /dispute; Transfeera:
 * GET /med/infractions) e passa cada uma pela
 * ingestão. É a rede de segurança do webhook: MED que o webhook perdeu (conta
 * sem webhook cadastrado, entrega que falhou) entra por aqui.
 */
class SyncAccountDisputesAction
{
    public function __construct(
        private readonly IngestDisputeAction $ingest,
        private readonly IngestTransfeeraInfractionAction $ingestTransfeera,
    ) {}

    /**
     * @return array{seen: int, created: int}
     */
    public function handle(WooviAccount $account, int $days = 30): array
    {
        if ($account->isTransfeera()) {
            return $this->transfeera($account, $days);
        }

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

    /**
     * @return array{seen: int, created: int}
     */
    private function transfeera(WooviAccount $account, int $days): array
    {
        $infractions = TransfeeraClient::for($account)->listInfractions(now()->subDays($days)->toIso8601String());
        $created = 0;

        foreach ($infractions as $infraction) {
            if (blank($infraction['id'] ?? null) || blank($infraction['transaction_id'] ?? null)) {
                continue;
            }

            $dispute = $this->ingestTransfeera->handle($account, $infraction, 'sync');
            $created += $dispute->wasRecentlyCreated ? 1 : 0;
        }

        $account->forceFill(['last_synced_at' => now()])->save();

        return ['seen' => count($infractions), 'created' => $created];
    }
}
