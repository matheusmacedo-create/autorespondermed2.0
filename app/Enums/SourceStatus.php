<?php

namespace App\Enums;

/**
 * Situação de uma fonte do dossiê. `not_found` é resposta definitiva (a fonte não
 * tem o dado) e não se repete. `failed`, `skipped` (dependia de outra fonte que
 * não veio) e `not_configured` são reavaliados a cada montagem - o que dependia
 * da transação passa a ser buscado quando ela chega, e o que dependia de
 * configuração, quando ela é feita. Reavaliar sem a dependência não custa chamada.
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
        return in_array($this, [self::FAILED, self::SKIPPED, self::NOT_CONFIGURED], true);
    }
}
