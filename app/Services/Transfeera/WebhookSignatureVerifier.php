<?php

namespace App\Services\Transfeera;

/**
 * Assinatura do webhook da Transfeera: header `Transfeera-Signature` no formato
 * `t=<timestamp>,v1=<hex>`, onde v1 = HMAC-SHA256("<timestamp>.<corpo bruto>") com o
 * `signature_secret` devolvido no cadastro do webhook (não é o client_secret).
 * Conferido com o vetor de teste da doc (TransfeeraSignatureVerifierTest).
 */
class WebhookSignatureVerifier
{
    public function verify(string $rawBody, ?string $header, ?string $secret): bool
    {
        if (! config('services.transfeera.webhook.verify_signature', true)) {
            return true;
        }

        if (blank($header) || blank($secret)) {
            return false;
        }

        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                // Só o esquema v1 vale; outros são ignorados, como a doc manda.
                $signatures[] = strtolower($value);
            }
        }

        if (blank($timestamp) || $signatures === []) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp.'.'.$rawBody, (string) $secret);

        foreach ($signatures as $signature) {
            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
