<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Woovi (OpenPix). Cada conta Woovi (subconta do seller) tem o próprio AppID,
    // guardado criptografado em woovi_accounts - aqui só o que é comum a todas.
    'woovi' => [
        'base_url' => env('WOOVI_BASE_URL', 'https://api.woovi.com'),
        'timeout' => (int) env('WOOVI_TIMEOUT', 20),

        // Como a evidência chega à Woovi em POST /api/v1/dispute/{id}/evidence:
        // - file_id: sobe o arquivo antes em POST /api/v1/files (purpose
        //   DISPUTE_EVIDENCE) e manda o id. Recomendado pela Woovi, mas exige a
        //   feature DISPUTE_EVIDENCE_FILE_ID e o escopo FILE_POST na conta - sem
        //   eles a Woovi devolve 403 e o envio cai sozinho para `url`.
        // - url: manda um link temporário assinado deste sistema, de onde a
        //   Woovi baixa o arquivo. Exige APP_URL público.
        'evidence_mode' => env('WOOVI_EVIDENCE_MODE', 'file_id'),

        // Validade do link assinado do modo `url` (a Woovi baixa e re-hospeda).
        'evidence_url_ttl_minutes' => (int) env('WOOVI_EVIDENCE_URL_TTL_MINUTES', 1440),

        // Assinatura dos webhooks (x-webhook-signature, RSA-SHA256 com a chave
        // privada da Woovi). Desligar só em ambiente local.
        'webhook' => [
            'verify_signature' => (bool) env('WOOVI_WEBHOOK_VERIFY_SIGNATURE', true),
            'public_keys_url' => env('WOOVI_WEBHOOK_PUBLIC_KEYS_URL', 'https://api.woovi.com/api/v1/webhook/public-keys'),
            // Chave publicada na doc (12/10/2026). Fallback quando o endpoint de
            // chaves não responde - a Woovi recomenda buscar, não fixar.
            'fallback_public_key' => env('WOOVI_WEBHOOK_PUBLIC_KEY', "-----BEGIN PUBLIC KEY-----\nMIGfMA0GCSqGSIb3DQEBAQUAA4GNADCBiQKBgQC/+NtIkjzevvqD+I3MMv3bLXDt\npvxBjY4BsRrSdca3rtAwMcRYYvxSnd7jagVLpctMiOxQO8ieUCKLSWHpsMAjO/zZ\nWMKbqoG8MNpi/u3fp6zz0mcHCOSqYsPUUG19buW8bis5ZZ2IZgBObWSpTvJ0cnj6\nHKBAA82Jln+lGwS1MwIDAQAB\n-----END PUBLIC KEY-----\n"),
            'public_keys_cache_minutes' => (int) env('WOOVI_WEBHOOK_PUBLIC_KEYS_CACHE_MINUTES', 60),
        ],
    ],

    // Segredo compartilhado com o unico-api (header X-API-Key) para as rotas
    // server-to-server: o unico-api cadastra/atualiza as contas Woovi dos sellers
    // e consulta os MEDs de uma conta. Sem valor = rotas negam (fail-closed).
    'api' => [
        'key' => env('API_KEY'),
    ],

    'discord' => [
        // Canal da operação de MED: caso novo, defesa enviada pelo seller, prazo
        // vencendo, falha de envio à Woovi. Vazio = só log.
        'med_webhook_url' => env('DISCORD_MED_WEBHOOK_URL'),
        'med_role_id' => env('DISCORD_MED_ROLE_ID'),
    ],

];
