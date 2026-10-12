<?php

namespace Tests\Concerns;

use App\Services\Woovi\WebhookSignatureVerifier;
use Illuminate\Testing\TestResponse;

/**
 * Par de chaves RSA de teste no lugar da chave da Woovi: o teste assina o corpo
 * como a Woovi assinaria e o verificador confere com a pública configurada.
 */
trait SignsWooviWebhooks
{
    private ?\OpenSSLAsymmetricKey $wooviTestPrivateKey = null;

    protected function useWooviTestKeys(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->wooviTestPrivateKey = $key;

        config([
            'services.woovi.webhook.fallback_public_key' => openssl_pkey_get_details($key)['key'],
            'services.woovi.webhook.public_keys_url' => '',
        ]);

        WebhookSignatureVerifier::forgetCachedKeys();
    }

    protected function signWooviBody(string $body): string
    {
        openssl_sign($body, $signature, $this->wooviTestPrivateKey, OPENSSL_ALGO_SHA256);

        return base64_encode($signature);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function postWooviWebhook(string $webhookKey, array $payload, ?string $signature = null): TestResponse
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->call(
            'POST',
            "/api/webhooks/woovi/{$webhookKey}",
            server: [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_WEBHOOK_SIGNATURE' => $signature ?? $this->signWooviBody($body),
            ],
            content: $body,
        );
    }
}
