<?php

namespace App\Services\Kapso;

use RuntimeException;

/**
 * Recusa da Kapso/Meta com a mensagem já traduzida para o operador.
 */
class KapsoException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly string|int|null $codigo = null,
    ) {
        parent::__construct($message);
    }
}
