<?php

namespace App\Actions\Med;

use App\Models\WooviAccount;
use App\Services\Transfeera\TransfeeraClient;
use App\Services\Woovi\WooviClient;
use RuntimeException;

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

    public const TRANSFEERA_OBJECT = 'Infraction';

    /**
     * @return array<int, string> eventos cadastrados agora
     */
    public function handle(WooviAccount $account): array
    {
        if ($account->isTransfeera()) {
            return $this->transfeera($account);
        }

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

    /**
     * Transfeera: um webhook só, com o tipo de objeto `Infraction`. O cadastro (e a
     * listagem) devolve o `signature_secret` que assina as entregas - sem ele
     * guardado, o webhook é recusado aqui.
     *
     * @return array<int, string>
     */
    private function transfeera(WooviAccount $account): array
    {
        $client = TransfeeraClient::for($account);
        $url = $account->webhookUrl();
        $existing = collect($client->listWebhooks())
            ->first(fn (array $webhook): bool => ($webhook['url'] ?? null) === $url && blank($webhook['deleted_at'] ?? null));
        $created = [];

        if ($existing === null) {
            $existing = $client->createWebhook($url, [self::TRANSFEERA_OBJECT]);
            $created[] = self::TRANSFEERA_OBJECT;
        } elseif (! in_array(self::TRANSFEERA_OBJECT, (array) ($existing['object_types'] ?? []), true)) {
            $client->updateWebhook((string) $existing['id'], $url, array_values(array_unique([...(array) ($existing['object_types'] ?? []), self::TRANSFEERA_OBJECT])));
            $created[] = self::TRANSFEERA_OBJECT;
        }

        // A listagem não devolve o segredo (conferido em produção): ele só vem na
        // resposta do cadastro. Webhook que já existe na URL e segredo que não está
        // aqui = não há como conferir a assinatura.
        if (blank($existing['signature_secret'] ?? null) && blank($account->webhook_secret)) {
            throw new RuntimeException('A Transfeera já tem um webhook nesta URL, mas o segredo dele não está guardado aqui (ela só o informa na criação). Apague esse webhook na Transfeera e rode de novo.');
        }

        $account->forceFill(array_filter([
            'webhook_secret' => filled($existing['signature_secret'] ?? null) ? (string) $existing['signature_secret'] : null,
            'webhooks_registered_at' => now(),
        ]))->save();

        return $created;
    }
}
