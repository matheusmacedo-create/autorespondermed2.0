<?php

namespace App\Services\Unicopag;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Leitura no unico-api (plataforma ÚnicoPag) para o dossiê automático. Mesma
 * autenticação que o KYC usa com o unico-api (X-API-Key + Bearer, KB do KYC 11.13).
 *
 * - Cadastro do seller: rota que existe (GET /api/kyc-panel/accounts/{account_id}/register).
 * - Venda pelo identificador da Woovi: GET /api/med/sales/lookup (contrato em
 *   doc/MED_KNOWLEDGE_BASE.md 8.1). Sem endpoint configurado,
 *   a fonte fica "não configurada" e o dossiê segue só com a Woovi.
 */
class UnicopagClient
{
    public function registerConfigured(): bool
    {
        return filled(config('services.unicopag.key')) && filled(config('services.unicopag.register_endpoint'));
    }

    public function saleConfigured(): bool
    {
        return filled(config('services.unicopag.key')) && filled(config('services.unicopag.sale_endpoint'));
    }

    /**
     * Cadastro da conta na plataforma. Null quando o unico-api não tem a conta (404).
     *
     * @return array<string, mixed>|null
     */
    public function register(string $accountId): ?array
    {
        $json = $this->get((string) config('services.unicopag.register_endpoint'), ['{account_id}' => $accountId, '{id}' => $accountId]);

        // A rota pode devolver o cadastro dentro de `data` ou `account` (KB do KYC 11.13).
        return $json === null ? null : (array) ($json['data'] ?? $json['account'] ?? $json);
    }

    /**
     * Venda (transação/pedido) na plataforma pelos identificadores da Woovi. Null
     * quando a plataforma não achou a venda (404).
     *
     * @return array<string, mixed>|null
     */
    public function sale(string $accountId, ?string $correlationId, ?string $txid, string $endToEndId): ?array
    {
        $json = $this->get((string) config('services.unicopag.sale_endpoint'), [
            '{account_id}' => $accountId,
            '{correlation_id}' => (string) $correlationId,
            '{txid}' => (string) $txid,
            '{end_to_end_id}' => $endToEndId,
        ]);

        return $json === null ? null : (array) ($json['data'] ?? $json['sale'] ?? $json);
    }

    /**
     * @param  array<string, string>  $placeholders
     * @return array<string, mixed>|null
     */
    private function get(string $endpoint, array $placeholders): ?array
    {
        $key = (string) config('services.unicopag.key');
        $url = $this->url($endpoint, $placeholders);

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => 'Bearer '.$key,
                'X-API-Key' => $key,
            ])->timeout(max(2, (int) config('services.unicopag.timeout', 8)))->get($url);
        } catch (ConnectionException $e) {
            throw new UnicopagException('unico-api não respondeu: '.$e->getMessage());
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new UnicopagException(sprintf('unico-api recusou (HTTP %d)%s', $response->status(), $this->reason($response)));
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new UnicopagException('unico-api respondeu algo que não é JSON.');
        }

        return $json;
    }

    /**
     * Caminho relativo à base, URL absoluta ou query string - como o KYC aceita.
     *
     * @param  array<string, string>  $placeholders
     */
    private function url(string $endpoint, array $placeholders): string
    {
        $endpoint = strtr($endpoint, array_map('rawurlencode', $placeholders));

        if (preg_match('#^https?://#i', $endpoint) === 1) {
            return $endpoint;
        }

        return rtrim((string) config('services.unicopag.base_url'), '/').'/'.ltrim($endpoint, '/');
    }

    private function reason(Response $response): string
    {
        $message = $response->json('message') ?? $response->json('error');

        return is_string($message) && $message !== '' ? ': '.mb_substr($message, 0, 200) : '';
    }
}
