<?php

namespace App\Services\Woovi;

use App\Models\WooviAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da API da Woovi para uma conta (AppID). Só os endpoints que o MED usa:
 * disputas, arquivos e webhooks. Contratos conferidos na doc pública e no
 * OpenAPI do repo Open-Pix/woovi-developers em 12/10/2026.
 */
class WooviClient
{
    public function __construct(private readonly WooviAccount $account) {}

    public static function for(WooviAccount $account): self
    {
        return new self($account);
    }

    /**
     * GET /api/v1/dispute/{id} - o id é o endToEndId da transação.
     *
     * @return array<string, mixed>
     */
    public function getDispute(string $endToEndId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/api/v1/dispute/'.rawurlencode($endToEndId)), 'a consulta da disputa');

        return (array) ($response->json('dispute') ?? []);
    }

    /**
     * GET /api/v1/dispute - disputas da conta no intervalo, todas as páginas.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listDisputes(?string $start = null, ?string $end = null, int $limit = 100): array
    {
        $disputes = [];
        $skip = 0;

        do {
            $query = array_filter([
                'start' => $start,
                'end' => $end,
                'skip' => $skip,
                'limit' => $limit,
            ], fn ($value) => $value !== null);

            $response = $this->send(fn (PendingRequest $http) => $http->get('/api/v1/dispute', $query), 'a listagem de disputas');

            $page = (array) ($response->json('disputes') ?? []);
            $disputes = array_merge($disputes, $page);
            $skip += $limit;

            // Teto defensivo: uma pageInfo quebrada não pode virar laço infinito.
        } while ($response->json('pageInfo.hasNextPage') === true && $page !== [] && $skip < 10000);

        return $disputes;
    }

    /**
     * POST /api/v1/files (multipart) com purpose DISPUTE_EVIDENCE. Reenviar com o
     * mesmo correlationID devolve o arquivo já guardado (idempotente).
     *
     * @return array<string, mixed> o objeto `file` (id, url, urlExpiresAt...)
     */
    public function uploadEvidenceFile(string $contents, string $fileName, string $mimeType, string $correlationId): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http
                ->attach('file', $contents, $fileName, ['Content-Type' => $mimeType])
                ->post('/api/v1/files', [
                    'purpose' => 'DISPUTE_EVIDENCE',
                    'correlationID' => $correlationId,
                ]),
            'o upload do arquivo',
        );

        return (array) ($response->json('file') ?? []);
    }

    /**
     * POST /api/v1/dispute/{id}/evidence. Cada documento leva `fileId` OU `url`,
     * nunca os dois, mais `description` e `correlationID`.
     *
     * @param  array<int, array<string, string>>  $documents
     * @return array<int, array<string, mixed>>
     */
    public function sendEvidence(string $disputeId, array $documents): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->post('/api/v1/dispute/'.rawurlencode($disputeId).'/evidence', [
                'documents' => array_values($documents),
            ]),
            'o envio das evidências',
        );

        return (array) ($response->json('documents') ?? []);
    }

    /**
     * GET /api/v1/webhook?url= - webhooks já cadastrados para a URL.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWebhooks(?string $url = null): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get('/api/v1/webhook', array_filter(['url' => $url])),
            'a listagem de webhooks',
        );

        return (array) ($response->json('webhooks') ?? []);
    }

    /**
     * POST /api/v1/webhook - um webhook por evento.
     *
     * @return array<string, mixed>
     */
    public function createWebhook(string $name, string $event, string $url): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->post('/api/v1/webhook', [
                'webhook' => [
                    'name' => $name,
                    'event' => $event,
                    'url' => $url,
                    'isActive' => true,
                ],
            ]),
            'o cadastro do webhook',
        );

        return (array) ($response->json('webhook') ?? []);
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $action): Response
    {
        try {
            $response = $call($this->http());
        } catch (ConnectionException $e) {
            throw new WooviException("Woovi não respondeu {$action}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw WooviException::fromResponse($response, $action);
        }

        return $response;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.woovi.base_url'), '/'))
            ->withHeaders(['Authorization' => (string) $this->account->app_id])
            ->acceptJson()
            ->timeout((int) config('services.woovi.timeout', 20));
    }
}
