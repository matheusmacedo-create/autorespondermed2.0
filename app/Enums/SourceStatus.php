<?php

namespace App\Enums;

/**
 * Situação de uma fonte do dossiê. Só `failed` é tentado de novo sozinho:
 * `not_found` é resposta (a fonte não tem o dado) e `not_configured`/`skipped`
 * dependem de configuração ou de outra fonte.
 */
enum SourceStatus: string
{
    case OK = 'ok';
    case NOT_FOUND = 'not_found';
    case FAILED = 'failed';
    case NOT_CONFIGURED = 'not_configured';
    case SKIPPED = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::OK => 'Obtido',
            self::NOT_FOUND => 'Não encontrado',
            self::FAILED => 'Falhou',
            self::NOT_CONFIGURED => 'Não configurado',
            self::SKIPPED => 'Não se aplica',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OK => 'success',
            self::NOT_FOUND => 'warning',
            self::FAILED => 'danger',
            self::NOT_CONFIGURED, self::SKIPPED => 'gray',
        };
    }

    public function shouldRetry(): bool
    {
        return $this === self::FAILED;
    }
}
