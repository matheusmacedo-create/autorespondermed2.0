<?php

namespace App\Services\Woovi;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Erro devolvido pela API da Woovi, com o status e a mensagem dela - é o que
 * vai para a linha do tempo do caso e para o alarme, então precisa ser legível.
 */
class WooviException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $body = null,
    ) {
        parent::__construct($message, $status);
    }

    public static function fromResponse(Response $response, string $action): self
    {
        $json = $response->json();
        $error = is_array($json) ? (string) ($json['error'] ?? $json['message'] ?? '') : '';
        $errorCode = is_array($json) ? ($json['errorCode'] ?? null) : null;

        $message = sprintf(
            'Woovi recusou %s (HTTP %d)%s',
            $action,
            $response->status(),
            $error !== '' ? ": {$error}" : '',
        );

        return new self($message, $response->status(), $errorCode, mb_substr($response->body(), 0, 2000));
    }

    /**
     * A Woovi não achou o recurso: 404, ou 400 com "not found" (é assim que o GET
     * /transaction/{id} responde a transação inexistente).
     */
    public function isNotFound(): bool
    {
        return $this->status === 404
            || ($this->status === 400 && str_contains(strtolower((string) $this->body), 'not found'));
    }

    /**
     * A conta não tem a feature de evidência por fileId (DISPUTE_EVIDENCE_FILE_ID)
     * ou o AppID não tem o escopo FILE_POST: dá para seguir pelo modo `url`.
     */
    public function isFileIdNotAllowed(): bool
    {
        return $this->errorCode === 'DISPUTE_EVIDENCE_FILE_ID_NOT_ALLOWED'
            || $this->status === 403;
    }
}
