<?php

namespace App\Services\Woovi;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Erro devolvido pela API da Woovi, com o status e a mensagem dela - é o que
 * vai para a linha do tempo do caso e para o alarme, então precisa ser legível.
 */
class WooviException extends RuntimeException
{
    /**
     * Como a Woovi diz "não achei" num 400, em minúsculas: em inglês na doc
     * ("Transaction not found") e em português em produção ("Transação não
     * encontrada"). O radical cobre "encontrada" e "encontrado", com e sem til.
     */
    private const NOT_FOUND_PHRASES = ['not found', 'não encontrad', 'nao encontrad'];

    /**
     * @param  string|null  $error  a mensagem da Woovi já decodificada (`error` ou `message` do JSON)
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?string $errorCode = null,
        public readonly ?string $body = null,
        public readonly ?string $error = null,
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

        return new self($message, $response->status(), $errorCode, mb_substr($response->body(), 0, 2000), $error !== '' ? $error : null);
    }

    /**
     * A Woovi não achou o recurso: 404, ou 400 dizendo "not found" / "não
     * encontrada" (é assim que o GET /transaction/{id} responde a transação
     * inexistente - em inglês na doc, em português em produção).
     */
    public function isNotFound(): bool
    {
        if ($this->status === 404) {
            return true;
        }

        // A mensagem decodificada além do corpo bruto: o JSON pode trazer o acento
        // escapado ("n\u00e3o encontrada"), e aí a frase não aparece no corpo.
        return $this->status === 400
            && Str::contains(mb_strtolower($this->error.' '.$this->body), self::NOT_FOUND_PHRASES);
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
