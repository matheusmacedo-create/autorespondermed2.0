<?php

namespace App\Services\Transfeera;

use App\Services\Woovi\WooviException;
use Illuminate\Http\Client\Response;

/**
 * Erro da API da Transfeera, com a mensagem pronta para a linha do tempo.
 *
 * Estende a WooviException de propósito: o envio automático, a ficha e os comandos
 * já tratam a exceção da instituição por ela (400 = relê o caso, 404 = não achou).
 */
class TransfeeraException extends WooviException
{
    public static function fromResponse(Response $response, string $action): self
    {
        $json = $response->json();
        $json = is_array($json) ? $json : [];

        // Erro de validação: {code, message, errors: [{message, field}]}; os demais:
        // {statusCode, error, message}.
        $details = collect((array) ($json['errors'] ?? []))
            ->map(fn ($error): string => is_array($error) ? trim((string) ($error['message'] ?? '')) : '')
            ->filter()
            ->implode('; ');
        $error = $details !== '' ? $details : trim((string) ($json['message'] ?? $json['error'] ?? ''));
        $code = $json['code'] ?? $json['error_code'] ?? null;

        return new self(
            sprintf('Transfeera recusou %s (HTTP %d)%s', $action, $response->status(), $error !== '' ? ": {$error}" : ''),
            $response->status(),
            is_scalar($code) ? (string) $code : null,
            mb_substr($response->body(), 0, 2000),
        );
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }

    /** Não existe evidência por fileId na Transfeera. */
    public function isFileIdNotAllowed(): bool
    {
        return false;
    }

    /**
     * A infração já tem análise registrada: "Only infraction review with
     * analysis_status 'pending' can be updated".
     */
    public function isAlreadyAnalyzed(): bool
    {
        return $this->status === 400 && str_contains(strtolower((string) $this->body), "analysis_status 'pending'");
    }
}
