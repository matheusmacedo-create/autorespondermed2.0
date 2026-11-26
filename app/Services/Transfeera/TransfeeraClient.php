<?php

namespace App\Services\Transfeera;

use App\Models\WooviAccount;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da API da Transfeera para uma conta. Só o que o MED usa: infrações
 * (`/med/infractions`), o Pix recebido e as devoluções dele (`/pix/cashin`) e os
 * webhooks. Contrato lido na doc pública (docs.transfeera.dev) em 26/11/2026 - ainda
 * não conferido com chamada real (KB seção 12).
 *
 * Autenticação: `POST /authorization` (client_credentials) devolve um token de 30
 * minutos, guardado em cache por conta. Toda chamada leva o `User-Agent` com o nome
 * da empresa e um e-mail de contato - a Transfeera exige.
 */
class TransfeeraClient
{
    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly ?string $accountId = null,
    ) {}

    /**
     * A credencial da conta fica em `app_id` como "client_id:client_secret"; o id da
     * subconta (Hub de Contas), quando houver, em `woovi_company_id`.
     */
    public static function for(WooviAccount $account): self
    {
        [$clientId, $clientSecret] = array_pad(explode(':', trim((string) $account->app_id), 2), 2, '');

        if ($clientId === '' || $clientSecret === '') {
            throw new TransfeeraException("Conta {$account->name}: a credencial da Transfeera precisa estar no formato client_id:client_secret.");
        }

        return new self($clientId, $clientSecret, filled($account->woovi_company_id) ? (string) $account->woovi_company_id : null);
    }

    /**
     * GET /med/infractions - infrações criadas desde a data (`AAAA-MM-DD`, sem hora:
     * a doc mostra data e hora, mas a API recusa), todas as páginas
     * (paginação por cursor em `meta.next`).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listInfractions(?string $since = null, int $pageSize = 100): array
    {
        $infractions = [];
        $cursor = null;
        $pages = 0;

        do {
            $query = array_filter([
                'infraction_date__gte' => $since,
                'page_size' => $pageSize,
                'page_cursor' => $cursor,
            ], fn ($value) => $value !== null);

            $response = $this->send(fn (PendingRequest $http) => $http->get('/med/infractions', $query), 'a listagem de infrações');

            $page = array_values(array_filter((array) ($response->json('items') ?? []), 'is_array'));
            $infractions = array_merge($infractions, $page);
            $next = $response->json('meta.next');
            $repeated = $next === $cursor;
            $cursor = is_string($next) && $next !== '' ? $next : null;

            // Teto defensivo: cursor quebrado não pode virar laço infinito.
        } while ($cursor !== null && ! $repeated && $page !== [] && ++$pages < 100);

        return $infractions;
    }

    /**
     * GET /med/infractions/{id} - o id é o da infração, não o endToEndId.
     *
     * @return array<string, mixed>
     */
    public function getInfraction(string $id): array
    {
        return (array) $this->send(fn (PendingRequest $http) => $http->get('/med/infractions/'.rawurlencode($id)), 'a consulta da infração')->json();
    }

    /**
     * POST /med/infractions/{id}/analysis (multipart). `rejected` = a empresa rejeita
     * a infração (defende a venda); `accepted` = concorda com ela. Só vale enquanto
     * `analysis_status` é `pending` e não dá para editar depois.
     *
     * @param  array<int, array{contents: string, name: string, mime: string}>  $attachments
     * @return array<string, mixed> a infração atualizada
     */
    public function submitAnalysis(string $id, string $analysis, string $description, array $attachments = []): array
    {
        $response = $this->send(function (PendingRequest $http) use ($id, $analysis, $description, $attachments) {
            // Sem anexo o Laravel mandaria JSON; a rota só documenta multipart.
            $http = $http->asMultipart();

            foreach ($attachments as $file) {
                $http = $http->attach('attachments', $file['contents'], $file['name'], ['Content-Type' => $file['mime']]);
            }

            return $http->post('/med/infractions/'.rawurlencode($id).'/analysis', [
                'analysis' => $analysis,
                'analysis_description' => $description,
            ]);
        }, 'o envio da análise');

        return (array) $response->json();
    }

    /**
     * GET /pix/cashin/{end2endId} - o Pix recebido: valor (em reais), txid, pagador,
     * recebedor e o link do comprovante. Não traz data.
     *
     * @return array<string, mixed>
     */
    public function getCashIn(string $endToEndId): array
    {
        return (array) $this->send(fn (PendingRequest $http) => $http->get('/pix/cashin/'.rawurlencode($endToEndId)), 'a consulta do Pix recebido')->json();
    }

    /**
     * GET /pix/cashin/{end2endId}/refund - devoluções já feitas do Pix.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCashInRefunds(string $endToEndId): array
    {
        $response = $this->send(fn (PendingRequest $http) => $http->get('/pix/cashin/'.rawurlencode($endToEndId).'/refund'), 'a consulta das devoluções');

        return array_values(array_filter((array) ($response->json('entries') ?? []), 'is_array'));
    }

    /**
     * Baixa o comprovante (`receipt_file_url`, link público da própria Transfeera).
     * Só https e só host da Transfeera: a URL vem de resposta de API.
     */
    public function downloadReceipt(string $url): string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || ! ($host === 'transfeera.com' || str_ends_with($host, '.transfeera.com'))) {
            throw new TransfeeraException('Link do comprovante fora do domínio da Transfeera.');
        }

        try {
            $response = Http::withHeaders(['User-Agent' => $this->userAgent()])
                ->withOptions(['allow_redirects' => false])
                ->timeout((int) config('services.transfeera.timeout', 20))
                ->get($url);
        } catch (ConnectionException $e) {
            throw new TransfeeraException("Transfeera não respondeu o comprovante do Pix: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw TransfeeraException::fromResponse($response, 'o comprovante do Pix');
        }

        if (! str_starts_with($response->body(), '%PDF')) {
            throw new TransfeeraException('Transfeera devolveu o comprovante num formato que não é PDF.', $response->status());
        }

        return $response->body();
    }

    /**
     * GET /webhook - webhooks da conta, cada um com o `signature_secret`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listWebhooks(): array
    {
        return array_values(array_filter((array) $this->send(fn (PendingRequest $http) => $http->get('/webhook'), 'a listagem de webhooks')->json(), 'is_array'));
    }

    /**
     * POST /webhook - devolve `id` e o `signature_secret` que assina as entregas.
     *
     * @param  array<int, string>  $objectTypes
     * @return array<string, mixed>
     */
    public function createWebhook(string $url, array $objectTypes): array
    {
        return (array) $this->send(
            fn (PendingRequest $http) => $http->post('/webhook', ['url' => $url, 'object_types' => array_values($objectTypes)]),
            'o cadastro do webhook',
        )->json();
    }

    /**
     * PUT /webhook/{id} - troca a URL e os tipos (responde 204).
     *
     * @param  array<int, string>  $objectTypes
     */
    public function updateWebhook(string $id, string $url, array $objectTypes): void
    {
        $this->send(
            fn (PendingRequest $http) => $http->put('/webhook/'.rawurlencode($id), ['url' => $url, 'object_types' => array_values($objectTypes)]),
            'a atualização do webhook',
        );
    }

    /**
     * @param  callable(PendingRequest): Response  $call
     */
    private function send(callable $call, string $action): Response
    {
        try {
            $response = $call($this->http($this->token()));

            // Token revogado antes da hora: pede outro e tenta uma vez.
            if ($response->status() === 401) {
                Cache::forget($this->tokenCacheKey());
                $response = $call($this->http($this->token()));
            }
        } catch (ConnectionException $e) {
            throw new TransfeeraException("Transfeera não respondeu {$action}: {$e->getMessage()}");
        }

        if (! $response->successful()) {
            throw TransfeeraException::fromResponse($response, $action);
        }

        return $response;
    }

    private function token(): string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        try {
            $response = $this->base((string) config('services.transfeera.login_url'))
                ->asJson()
                ->post('/authorization', array_filter([
                    'grant_type' => 'client_credentials',
                    'client_id' => $this->clientId,
                    'client_secret' => $this->clientSecret,
                    // Subconta do Hub de Contas: credencial da conta principal + escopo.
                    'scope' => $this->accountId !== null ? "account_id:{$this->accountId}" : null,
                ]));
        } catch (ConnectionException $e) {
            throw new TransfeeraException("Transfeera não respondeu a autenticação: {$e->getMessage()}");
        }

        $token = $response->json('access_token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw TransfeeraException::fromResponse($response, 'a autenticação (confira client_id e client_secret)');
        }

        // Guarda com folga: o token vale 30 minutos (expires_in = 1800).
        $ttl = max(60, (int) ($response->json('expires_in') ?? 1800) - 120);
        Cache::put($this->tokenCacheKey(), $token, $ttl);

        return $token;
    }

    private function tokenCacheKey(): string
    {
        return 'transfeera:token:'.hash('sha256', $this->clientId.'|'.$this->clientSecret.'|'.$this->accountId);
    }

    private function http(string $token): PendingRequest
    {
        return $this->base((string) config('services.transfeera.base_url'))->withToken($token);
    }

    private function base(string $url): PendingRequest
    {
        $http = Http::baseUrl(rtrim($url, '/'))
            ->withHeaders(['User-Agent' => $this->userAgent()])
            ->acceptJson()
            ->timeout((int) config('services.transfeera.timeout', 20));

        // mTLS (domínio api.mtls.transfeera.com): certificado emitido pela Transfeera a
        // partir do CSR da empresa. Só quando os dois arquivos estão configurados.
        $cert = (string) config('services.transfeera.mtls.cert');
        $key = (string) config('services.transfeera.mtls.key');

        if ($cert !== '' && $key !== '') {
            $http = $http->withOptions(['cert' => $cert, 'ssl_key' => $key]);
        }

        return $http;
    }

    private function userAgent(): string
    {
        return (string) config('services.transfeera.user_agent');
    }
}
