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

        // AppID de um aplicativo da conta mestre/parceira da ÚnicoPag criado SÓ para o
        // MED, com os escopos PARTNER_COMPANY_GET_LIST e PARTNER_APPLICATION_POST. Não
        // reaproveitar a chave do unico-api (acesso total). Com ele o
        // `med:importar-contas` lista as subcontas (GET /partner/affiliate e
        // /partner/company) e cria em cada uma um aplicativo só com os escopos do MED
        // (POST /partner/application). Vazio = importação desligada.
        'master_app_id' => env('WOOVI_MASTER_APP_ID'),
        // Desliga só a importação das subcontas (conta sem a função de parceiro da
        // Woovi responde 403); a chave mestre continua valendo para o resto.
        'partner_import' => (bool) env('WOOVI_PARTNER_IMPORT', true),
        // Nome do aplicativo criado em cada subconta (aparece na lista de apps dela).
        'partner_application_name' => env('WOOVI_PARTNER_APPLICATION_NAME', 'UnicoPag MED'),
        // Escopos desse aplicativo: só o que o MED chama (x-required-scope do OpenAPI).
        // Vazio = esta lista padrão. Nunca há modo "sem escopos" (acesso completo).
        'partner_application_scopes' => filled(env('WOOVI_PARTNER_APPLICATION_SCOPES'))
            ? array_values(array_filter(array_map('trim', explode(',', (string) env('WOOVI_PARTNER_APPLICATION_SCOPES')))))
            : ['DISPUTE_GET', 'DISPUTE_GET_LIST', 'DISPUTE_POST', 'FILE_POST', 'WEBHOOK_GET_LIST', 'WEBHOOK_POST', 'TRANSACTION_GET', 'CHARGE_GET', 'CHARGE_REFUND_GET_LIST', 'FUNDS_RECOVERY_GET'],

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

    // unico-api (plataforma ÚnicoPag), chamado PELO MED para montar o dossiê. Mesmo
    // esquema de autenticação do KYC (X-API-Key + Bearer). O ideal é uma integração
    // própria `med`, só de leitura, no unico-api - a chave `kyc` também escreve.
    'unicopag' => [
        'base_url' => env('UNICOPAG_API_BASE_URL', 'https://api.cloud.unicopag.com.br'),
        'key' => env('UNICOPAG_API_KEY'),
        'timeout' => (int) env('UNICOPAG_API_TIMEOUT', 8),
        // Cadastro do seller - rota que já existe (PR #118 do unico-api, KB do KYC 8.22).
        // Vazio desliga. Placeholder: {account_id}.
        'register_endpoint' => env('UNICOPAG_REGISTER_ENDPOINT', '/api/kyc-panel/accounts/{account_id}/register'),
        // Venda pelo identificador da Woovi - /api/med/sales/lookup no unico-api
        // (contrato em doc/MED_KNOWLEDGE_BASE.md 8.1). Vazio desliga. Placeholders:
        // {account_id}, {correlation_id}, {txid}, {end_to_end_id}.
        'sale_endpoint' => env('UNICOPAG_SALE_ENDPOINT'),
    ],

    // KYC (unicopag-kyc): status do onboarding do seller, pela rota pública
    // GET /api/onboarding/status?account_id= (só status, sem documentos - de propósito:
    // documento do KYC, e a selfie mais ainda, não vai para evidência de MED).
    'kyc' => [
        'base_url' => env('KYC_BASE_URL', 'https://kyc.unicopag.com.br'),
        'timeout' => (int) env('KYC_TIMEOUT', 5),
        // Site da loja pelo KYC (rota só de leitura, chave própria do MED). Vazio desliga.
        // Placeholder: {account_id}.
        'store_endpoint' => env('KYC_STORE_ENDPOINT'),
        'med_key' => env('KYC_MED_API_KEY'),
    ],

    // Kapso - WhatsApp oficial da ÚnicoPag (o mesmo número e a mesma chave de projeto do
    // KYC). Sem api_key + phone_number_id o aviso por WhatsApp fica desligado e o seller
    // é avisado só por e-mail.
    'kapso' => [
        'api_key' => env('KAPSO_API_KEY'),
        'base_url' => env('KAPSO_BASE_URL', 'https://api.kapso.ai'),
        'graph_version' => env('KAPSO_GRAPH_VERSION', 'v24.0'),
        'phone_number_id' => env('KAPSO_PHONE_NUMBER_ID'),
        // Segredo do webhook deste sistema (POST /api/webhooks/kapso). Próprio do MED:
        // o KYC tem o dele, e cada um confere só o seu.
        'webhook_secret' => env('KAPSO_WEBHOOK_SECRET'),
        'timeout' => (int) env('KAPSO_TIMEOUT', 15),
    ],

    // Anthropic (Claude): redige o rascunho da defesa do MED a partir do dossiê
    // conferido. Sem chave, o botão "Gerar defesa com IA" fica escondido.
    'anthropic' => [
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('MED_IA_MODEL', 'claude-opus-5-5'),
        // low | medium | high | xhigh | max - redação jurídica pede profundidade.
        'effort' => env('MED_IA_EFFORT', 'high'),
        'timeout' => (float) env('MED_IA_TIMEOUT', 240),
    ],

    'discord' => [
        // Canal da operação de MED: caso novo, defesa enviada pelo seller, prazo
        // vencendo, falha de envio à Woovi. Vazio = só log.
        'med_webhook_url' => env('DISCORD_MED_WEBHOOK_URL'),
        'med_role_id' => env('DISCORD_MED_ROLE_ID'),
    ],

];
