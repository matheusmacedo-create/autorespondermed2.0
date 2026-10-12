<?php

return [
    /*
    | Prazo interno para responder um MED, contado da abertura da disputa.
    | A Woovi não publica prazo de resposta na documentação (conferido em
    | 12/10/2026) - 72h é a referência de mercado (Bacen/PSPs). Confirmar com a
    | Woovi e ajustar aqui: é deste número que saem o "Vence em" da fila, a cor
    | da coluna e os lembretes.
    */
    'prazo_resposta_horas' => (int) env('MED_PRAZO_RESPOSTA_HORAS', 72),

    /*
    | Fila de análise: com quantas horas restantes o prazo fica amarelo e
    | vermelho na fila e no painel.
    */
    'fila' => [
        'alerta_horas_restantes' => (int) env('MED_FILA_ALERTA_HORAS', 24),
        'critico_horas_restantes' => (int) env('MED_FILA_CRITICO_HORAS', 8),
    ],

    /*
    | Link público do seller (formulário de defesa). Vale até o fim do prazo de
    | resposta mais esta folga - depois disso o seller fala com o atendimento.
    */
    'link' => [
        'folga_horas' => (int) env('MED_LINK_FOLGA_HORAS', 24),
    ],

    /*
    | Upload de evidências: os mesmos limites da Woovi para `POST /api/v1/files`
    | (10 MiB, PDF/PNG/JPEG/WEBP). Aceitar além disso aqui só adiaria o erro para
    | a hora de enviar à Woovi, quando o prazo já está correndo.
    */
    'evidencias' => [
        'max_kb' => 10240,
        'mimes' => ['pdf', 'png', 'jpg', 'jpeg', 'webp'],
        'max_arquivos' => (int) env('MED_EVIDENCIAS_MAX_ARQUIVOS', 10),
        'disk' => env('MED_EVIDENCIAS_DISK', 'local'),
    ],

    /*
    | Aviso por WhatsApp (Kapso). A Meta só deixa a empresa iniciar conversa com
    | template aprovado, e o template não leva link: sai o template, e quando o
    | seller responde o link vai como texto (mesmo desenho do KYC, KB 8.23 de lá).
    | Sem template configurado, o WhatsApp fica desligado.
    */
    'whatsapp' => [
        // Nome do template aprovado na Meta. {{1}} = primeiro nome do contato.
        'template' => env('MED_WHATSAPP_TEMPLATE'),
        'template_language' => env('MED_WHATSAPP_TEMPLATE_LANGUAGE', 'pt_BR'),
        // Resposta depois disto não é mais tratada como resposta ao template.
        'janela_resposta_dias' => (int) env('MED_WHATSAPP_JANELA_RESPOSTA_DIAS', 7),
    ],

    /*
    | Idioma do painel admin, independente do APP_LOCALE (mesmo racional do KYC).
    */
    'panel_locale' => env('MED_PANEL_LOCALE', 'pt_BR'),
];
