<?php

namespace App\Services\Kapso;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Cliente da Kapso (WhatsApp oficial da ÚnicoPag pela Cloud API da Meta). Mesmo
 * número, mesma chave e mesmas regras do KapsoService do KYC (KB do KYC 8.23):
 *
 *  - conversa iniciada pela empresa só com TEMPLATE aprovado pela Meta;
 *  - texto livre (o link) só chega a quem falou com a ÚnicoPag nas últimas 24h.
 */
class KapsoClient
{
    public function isConfigured(): bool
    {
        return filled(config('services.kapso.api_key')) && filled(config('services.kapso.phone_number_id'));
    }

    /**
     * Normaliza para o `to` da Cloud API: só dígitos, com 55 (5521998765432).
     * Número que não parece brasileiro é recusado (mesmo critério do KYC).
     */
    public static function recipient(?string $phone): ?string
    {
        $raw = trim((string) $phone);
        $hasCountryCode = str_starts_with($raw, '+');
        $digits = (string) preg_replace('/\D/', '', $raw);

        if (! $hasCountryCode) {
            $digits = ltrim($digits, '0');
        }

        if ($digits === '') {
            return null;
        }

        // Sem "+", um número nacional (DDD + fixo/celular) ganha o 55. Com "+" o país já
        // veio: ou é 55, ou não é BR (um +1 de 11 dígitos se parece com DDD + celular).
        if (! $hasCountryCode && self::looksLikeBrNational($digits)) {
            $digits = '55'.$digits;
        }

        if ((strlen($digits) === 12 || strlen($digits) === 13)
            && str_starts_with($digits, '55')
            && self::looksLikeBrNational(substr($digits, 2))) {
            return $digits;
        }

        return null;
    }

    /**
     * @return string id da mensagem na Meta (wamid)
     */
    public function sendText(string $to, string $text): string
    {
        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'text',
            'text' => ['body' => $text, 'preview_url' => true],
        ]);
    }

    /**
     * @param  array<int, string>  $bodyParameters  {{1}}, {{2}}... do corpo do template, em ordem
     * @return string id da mensagem na Meta (wamid)
     */
    public function sendTemplate(string $to, string $name, string $language, array $bodyParameters = []): string
    {
        $template = ['name' => $name, 'language' => ['code' => $language]];

        if ($bodyParameters !== []) {
            $template['components'] = [[
                'type' => 'body',
                'parameters' => array_map(fn (string $value): array => ['type' => 'text', 'text' => $value], array_values($bodyParameters)),
            ]];
        }

        return $this->send([
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $to,
            'type' => 'template',
            'template' => $template,
        ]);
    }

    /**
     * Cria (ou atualiza, se já houver um para a mesma URL) o webhook do número.
     *
     * @param  array<int, string>  $events
     * @return array<string, mixed>
     */
    public function registerWebhook(string $url, string $secret, array $events): array
    {
        $base = '/whatsapp/phone_numbers/'.config('services.kapso.phone_number_id').'/webhooks';
        $body = ['whatsapp_webhook' => [
            'kind' => 'kapso',
            'url' => $url,
            'events' => $events,
            'secret_key' => $secret,
            'active' => true,
        ]];

        $existing = collect((array) $this->call(fn () => $this->platform()->get($base))->json('data'))
            ->first(fn ($webhook): bool => is_array($webhook) && ($webhook['url'] ?? null) === $url);

        $response = $this->call(fn () => isset($existing['id'])
            ? $this->platform()->patch($base.'/'.$existing['id'], $body)
            : $this->platform()->post($base, $body));

        return (array) ($response->json('data') ?? []);
    }

    /**
     * Assinatura do webhook da Kapso: HMAC-SHA256 (hex) do corpo cru com o segredo.
     */
    public static function validSignature(string $rawBody, ?string $signature, ?string $secret): bool
    {
        if (blank($secret) || blank($signature)) {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, (string) $secret), strtolower(trim((string) $signature)));
    }

    /**
     * O que o operador precisa entender de cada recusa conhecida (subconjunto do KYC).
     */
    public static function translate(int $status, string|int|null $code, string $original): string
    {
        $code = (string) $code;

        return match (true) {
            in_array($code, ['131042', '141006'], true) || stripos($original, 'payment') !== false => 'A Meta bloqueou: forma de pagamento da conta WhatsApp com problema (Billing Hub da Meta ou créditos na Kapso).',
            $code === '131047' => 'Fora da janela de 24h: o seller não falou com a ÚnicoPag nas últimas 24h, só template inicia a conversa.',
            $code === '131026' => 'Número sem WhatsApp ou inalcançável.',
            $code === '132001' => 'Template não existe na Meta (ou não neste idioma): confira MED_WHATSAPP_TEMPLATE.',
            $code === '132000' => 'Quantidade de variáveis não bate com o template aprovado.',
            in_array($code, ['130429', '131048'], true) => 'A Meta limitou o envio deste número: espere antes de mandar mais.',
            $status === 401 => 'Chave da Kapso recusada: KAPSO_API_KEY inválida ou revogada.',
            $status === 402 => 'Saldo da Kapso não cobre a taxa da Meta: adicione créditos na Kapso.',
            $status === 404 => 'Número não encontrado na Kapso: confira KAPSO_PHONE_NUMBER_ID.',
            $status === 429 => 'Limite de requisições da Kapso: tente de novo em instantes.',
            default => 'A Kapso recusou.',
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(array $payload): string
    {
        if (! $this->isConfigured()) {
            throw new KapsoException('Kapso não configurada: faltam KAPSO_API_KEY e/ou KAPSO_PHONE_NUMBER_ID.', 0, 'nao_configurada');
        }

        $response = $this->call(fn () => $this->meta()->post('/'.config('services.kapso.phone_number_id').'/messages', $payload));
        $wamid = $response->json('messages.0.id');

        if (! is_string($wamid) || $wamid === '') {
            throw new KapsoException('A Kapso aceitou o envio mas não devolveu o id da mensagem.', $response->status());
        }

        return $wamid;
    }

    /**
     * @param  callable(): Response  $request
     */
    private function call(callable $request): Response
    {
        try {
            $response = $request();
        } catch (ConnectionException $e) {
            // Sem resposta não prova que nada saiu: quem chama não reenvia às cegas.
            throw new KapsoException('A Kapso não respondeu: a mensagem pode ou não ter saído. Confira a conversa na Kapso antes de reenviar.', 0, 'timeout');
        }

        if ($response->successful()) {
            return $response;
        }

        $error = $response->json('error');
        $code = is_array($error) ? ($error['error_subcode'] ?? $error['code'] ?? null) : $response->json('code');
        $original = is_array($error) ? (string) ($error['message'] ?? '') : (is_string($error) ? $error : mb_substr($response->body(), 0, 300));
        $code = is_string($code) || is_int($code) ? $code : null;

        throw new KapsoException(
            trim(self::translate($response->status(), $code, $original).sprintf(' [Kapso HTTP %d%s]', $response->status(), $original !== '' ? ' - '.$original : '')),
            $response->status(),
            $code,
        );
    }

    private static function looksLikeBrNational(string $national): bool
    {
        return preg_match('/^[1-9][1-9](?:[2-5]\d{7}|9\d{8})$/', $national) === 1;
    }

    private function meta(): PendingRequest
    {
        return $this->http(rtrim((string) config('services.kapso.base_url'), '/').'/meta/whatsapp/'.config('services.kapso.graph_version'));
    }

    private function platform(): PendingRequest
    {
        return $this->http(rtrim((string) config('services.kapso.base_url'), '/').'/platform/v1');
    }

    private function http(string $baseUrl): PendingRequest
    {
        return Http::baseUrl($baseUrl)
            ->withHeaders(['X-API-Key' => (string) config('services.kapso.api_key')])
            ->acceptJson()
            ->asJson()
            ->timeout(max(3, (int) config('services.kapso.timeout', 15)))
            ->connectTimeout(5);
    }
}
