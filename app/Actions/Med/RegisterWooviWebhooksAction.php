<?php

namespace App\Actions\Med;

use App\Models\WooviAccount;
use App\Services\Woovi\WooviClient;

/**
 * Cadastra na conta Woovi um webhook por evento de disputa apontando para a URL
 * desta conta. Idempotente: evento que já tem webhook na mesma URL é pulado.
 */
class RegisterWooviWebhooksAction
{
    public const EVENTS = [
        'OPENPIX:DISPUTE_CREATED',
        'OPENPIX:DISPUTE_ACCEPTED',
        'OPENPIX:DISPUTE_REJECTED',
        'OPENPIX:DISPUTE_CANCELED',
    ];

    /**
     * @return array<int, string> eventos cadastrados agora
     */
    public function handle(WooviAccount $account): array
    {
        $client = WooviClient::for($account);
        $url = $account->webhookUrl();

        $existing = collect($client->listWebhooks($url))
            ->filter(fn (array $webhook): bool => ($webhook['url'] ?? null) === $url)
            ->pluck('event')
            ->all();

        $created = [];

        foreach (self::EVENTS as $event) {
            if (in_array($event, $existing, true)) {
                continue;
            }

            $client->createWebhook('UnicoPag MED - '.str_replace('OPENPIX:', '', $event), $event, $url);
            $created[] = $event;
        }

        $account->forceFill(['webhooks_registered_at' => now()])->save();

        return $created;
    }
}
