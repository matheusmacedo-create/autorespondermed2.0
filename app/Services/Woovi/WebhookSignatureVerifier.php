<?php

namespace App\Services\Woovi;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Confere o header x-webhook-signature: base64(RSA-SHA256(corpo bruto, chave
 * privada da Woovi)). A chave pública vem de GET /api/v1/webhook/public-keys
 * (em cache) e qualquer chave da lista vale - durante uma rotação a Woovi
 * publica a antiga e a nova, e webhook em retry ainda vem assinado pela antiga.
 */
class WebhookSignatureVerifier
{
    private const CACHE_KEY = 'woovi:webhook:public-keys';

    public function verify(string $rawBody, ?string $signature): bool
    {
        if (! config('services.woovi.webhook.verify_signature')) {
            return true;
        }

        $decoded = base64_decode((string) $signature, true);

        if ($signature === null || $signature === '' || $decoded === false) {
            return false;
        }

        foreach ($this->publicKeys() as $publicKey) {
            if (@openssl_verify($rawBody, $decoded, $publicKey, OPENSSL_ALGO_SHA256) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    public function publicKeys(): array
    {
        $fetched = Cache::get(self::CACHE_KEY);

        if (! is_array($fetched)) {
            $fetched = $this->fetchPublicKeys();

            // Falha na busca não vai para o cache: na próxima chamada tenta de novo.
            if ($fetched !== []) {
                Cache::put(self::CACHE_KEY, $fetched, now()->addMinutes((int) config('services.woovi.webhook.public_keys_cache_minutes', 60)));
            }
        }

        // A chave da doc entra sempre por último: endpoint fora do ar não pode
        // derrubar o recebimento dos MEDs.
        $fallback = (string) config('services.woovi.webhook.fallback_public_key');

        return array_values(array_unique(array_filter([...$fetched, $fallback])));
    }

    /**
     * @return array<int, string>
     */
    private function fetchPublicKeys(): array
    {
        $url = (string) config('services.woovi.webhook.public_keys_url');

        if ($url === '') {
            return [];
        }

        try {
            $response = Http::acceptJson()->timeout(5)->get($url);

            if (! $response->successful()) {
                Log::warning('Woovi: endpoint de chaves públicas do webhook não respondeu 2xx.', ['status' => $response->status()]);

                return [];
            }

            return collect((array) $response->json('public_keys'))
                ->pluck('key')
                ->filter(fn ($key) => is_string($key) && $key !== '')
                ->values()
                ->all();
        } catch (Throwable $e) {
            Log::warning('Woovi: falha ao buscar as chaves públicas do webhook.', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public static function forgetCachedKeys(): void
    {
        Cache::forget(self::CACHE_KEY);
    }
}
