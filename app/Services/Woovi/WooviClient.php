<?php

namespace App\Services\Woovi;

use App\Models\WooviAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da API da Woovi para uma conta (AppID). Só os endpoints que o MED usa:
 * disputas, arquivos, webhooks e - para o dossiê automático - transação,
 * cobrança, devoluções e comprovante. Contratos conferidos na doc pública e no
 * OpenAPI do repo Open-Pix/woovi-developers em 12/10/2026.
 */
class WooviClient
{
    public function __construct(private readonly string $appId) {}

    public static function for(WooviAccount $account): self
    {
        return new self((string) $account->app_id);
    }

    /**
     * Cliente da conta mestre/parceira da ÚnicoPag (WOOVI_MASTER_APP_ID). Só para as
     * rotas de parceiro: listar as subcontas e criar aplicativo nelas. Disputa,
     * transação e evidência são sempre pelo AppID da subconta.
     */
    public static function master(): self
    {
        $appId = (string) config('services.woovi.master_app_id');

        if ($appId === '') {
            throw new WooviException('WOOVI_MASTER_APP_ID vazio: sem a conta mestre o MED não lista as subcontas.');
        }

        return new self($appId);
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
     * GET /api/v1/transaction/{id} - o id aceita o endToEndId (escopo TRANSACTION_GET).
     * É a fonte do valor, do horário e do pagador: o GET /dispute de um MED não traz
     * nenhum dos três.
     *
     * @return array<string, mixed>
     */
    public function getTransaction(string $endToEndId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/api/v1/transaction/'.rawurlencode($endToEndId)), 'a consulta da transação');

        return (array) ($response->json('transaction') ?? []);
    }

    /**
     * GET /api/v1/charge/{id} - aceita o correlationID (escopo CHARGE_GET).
     *
     * @return array<string, mixed>
     */
    public function getCharge(string $correlationId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/api/v1/charge/'.rawurlencode($correlationId)), 'a consulta da cobrança');

        return (array) ($response->json('charge') ?? []);
    }

    /**
     * GET /api/v1/charge/{id}/refund - devoluções já feitas na cobrança
     * (escopo CHARGE_REFUND_GET_LIST).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getChargeRefunds(string $correlationId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/api/v1/charge/'.rawurlencode($correlationId).'/refund'), 'a consulta das devoluções');

        return array_values(array_filter((array) ($response->json('refunds') ?? []), 'is_array'));
    }

    /**
     * GET /api/v1/receipt/pix-in/{endToEndId} - comprovante oficial do Pix recebido,
     * em PDF (bytes).
     */
    public function getPixInReceipt(string $endToEndId): string
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->accept('application/pdf')->get('/api/v1/receipt/pix-in/'.rawurlencode($endToEndId)),
            'o comprovante do Pix',
        );

        $body = $response->body();

        if (! str_starts_with($body, '%PDF')) {
            throw new WooviException('Woovi devolveu o comprovante num formato que não é PDF.', $response->status());
        }

        return $body;
    }

    /**
     * GET /api/v1/funds-recovery/{endToEndId}/disputes - disputas (MED recebido) da
     * conta ligadas à recuperação de fundos, com o `situationType` (SCAM,
     * ACCOUNT_TAKEOVER...) que o webhook e o GET /dispute não trazem. Exige a
     * feature "MED API" na conta (sem ela, 403). Contrato só na doc (não está no
     * OpenAPI): a resposta é uma lista.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getFundsRecoveryDisputes(string $endToEndId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/api/v1/funds-recovery/'.rawurlencode($endToEndId).'/disputes'), 'a consulta da recuperação de fundos');

        $json = $response->json();
        $list = is_array($json) && array_is_list($json) ? $json : (array) ($json['disputes'] ?? []);

        return array_values(array_filter($list, 'is_array'));
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
     * GET /api/v1/partner/affiliate - subcontas administradas pela conta mestre
     * (escopo PARTNER_COMPANY_GET_LIST). Paginação por skip/limit.
     *
     * @return array{affiliates: array<int, array<string, mixed>>, hasNextPage: bool}
     */
    public function listAffiliates(int $skip = 0, int $limit = 100): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get('/api/v1/partner/affiliate', ['skip' => $skip, 'limit' => $limit]),
            'a listagem das subcontas (parceiro)',
        );

        return [
            'affiliates' => array_values(array_filter((array) ($response->json('affiliates') ?? []), 'is_array')),
            'hasNextPage' => (bool) $response->json('pageInfo.hasNextPage', false),
        ];
    }

    /**
     * GET /api/v1/partner/company - pré-cadastros feitos pela conta mestre (escopo
     * PARTNER_COMPANY_GET_LIST). Traz o usuário mestre de cada empresa (contato).
     *
     * @return array{preRegistrations: array<int, array<string, mixed>>, hasNextPage: bool}
     */
    public function listPreRegistrations(int $skip = 0, int $limit = 100): array
    {
        $response = $this->send(
            fn (PendingRequest $http) => $http->get('/api/v1/partner/company', ['skip' => $skip, 'limit' => $limit]),
            'a listagem dos pré-cadastros (parceiro)',
        );

        return [
            'preRegistrations' => array_values(array_filter((array) ($response->json('preRegistrations') ?? []), 'is_array')),
            'hasNextPage' => (bool) $response->json('pageInfo.hasNextPage', false),
        ];
    }

    /**
     * POST /api/v1/partner/application - cria um aplicativo (API) na subconta do
     * CNPJ (escopo PARTNER_APPLICATION_POST). Devolve clientId e clientSecret; o
     * AppID da subconta é base64("clientId:clientSecret"). NÃO é idempotente: cada
     * chamada cria outra chave na subconta.
     *
     * @param  array<int, string>  $scopes  vazio = sem escopos (acesso completo, modo legado)
     * @return array<string, mixed>
     */
    public function createPartnerApplication(string $taxId, string $name, array $scopes = []): array
    {
        $digits = (string) preg_replace('/\D/', '', $taxId);

        $response = $this->send(
            fn (PendingRequest $http) => $http->post('/api/v1/partner/application', [
                'application' => array_filter(['name' => $name, 'type' => 'API', 'scopes' => $scopes ?: null]),
                'taxID' => ['taxID' => $digits, 'type' => strlen($digits) === 11 ? 'BR:CPF' : 'BR:CNPJ'],
            ]),
            'a criação do aplicativo na subconta',
        );

        return (array) ($response->json('application') ?? []);
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
            ->withHeaders(['Authorization' => $this->appId])
            ->acceptJson()
            ->timeout((int) config('services.woovi.timeout', 20));
    }
}
