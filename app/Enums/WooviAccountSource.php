<?php

namespace App\Enums;

/**
 * De onde veio o AppID da conta Woovi.
 */
enum WooviAccountSource: string
{
    // Cadastrado à mão no painel.
    case MANUAL = 'manual';
    // Enviado pelo unico-api (PUT /api/woovi-accounts), que guarda a chave da
    // subconta em acquirer_recipient_data.
    case UNICO_API = 'unico_api';
    // Autorizado pelo seller via WooviApp (OAuth da loja de apps da Woovi).
    case WOOVI_APP = 'woovi_app';

    public function label(): string
    {
        return match ($this) {
            self::MANUAL => 'Manual',
            self::UNICO_API => 'unico-api',
            self::WOOVI_APP => 'WooviApp',
        };
    }
}
