<?php

namespace App\Console\Commands;

use App\Services\Kapso\KapsoClient;
use App\Services\Kapso\KapsoException;
use Illuminate\Console\Command;

/**
 * Cadastra (ou atualiza) na Kapso o webhook deste sistema para o número oficial.
 * Convive com o webhook do KYC no mesmo número: cada um tem URL e segredo próprios.
 */
class RegisterKapsoWebhookCommand extends Command
{
    protected $signature = 'med:registrar-webhook-kapso';

    protected $description = 'Cadastra na Kapso o webhook de WhatsApp do MED (respostas e entrega)';

    public function handle(KapsoClient $kapso): int
    {
        if (! $kapso->isConfigured() || blank(config('services.kapso.webhook_secret'))) {
            $this->error('Faltam KAPSO_API_KEY, KAPSO_PHONE_NUMBER_ID e/ou KAPSO_WEBHOOK_SECRET.');

            return self::FAILURE;
        }

        try {
            $webhook = $kapso->registerWebhook(
                route('webhooks.kapso'),
                (string) config('services.kapso.webhook_secret'),
                ['whatsapp.message.received', 'whatsapp.message.sent', 'whatsapp.message.delivered', 'whatsapp.message.read', 'whatsapp.message.failed'],
            );
        } catch (KapsoException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Webhook da Kapso cadastrado: '.($webhook['url'] ?? route('webhooks.kapso')));

        return self::SUCCESS;
    }
}
