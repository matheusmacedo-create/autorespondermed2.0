# ÚnicoPag MED - responder de contestações Pix

Liga as contas Woovi dos sellers da ÚnicoPag e responde os MEDs (Mecanismo Especial de Devolução) pela API
da Woovi. É o mesmo desenho do KYC: o seller recebe um link, manda o relato e os comprovantes, o analista
revisa na fila e o sistema envia a defesa à Woovi.

## Fluxo

1. **MED chega**: a Woovi chama `POST /api/webhooks/woovi/{webhook_key}` (um endereço por conta) com
   `OPENPIX:DISPUTE_CREATED`. A assinatura `x-webhook-signature` é conferida, a chamada é gravada crua e o
   caso nasce com prazo (72h por padrão). O `med:sincronizar` de hora em hora pega o que o webhook perder.
2. **Seller é avisado** por e-mail com o link `/med/{token}`.
3. **Seller se defende**: relato + comprovantes (nota fiscal, entrega, conversa...), PDF/JPG/PNG/WEBP até
   10 MB, os mesmos limites da Woovi.
4. **Analista revisa** em `/admin/disputes`: pede complemento, anexa evidência, escreve o parecer.
5. **Enviar à Woovi**: o sistema gera a carta de defesa em PDF, sobe os arquivos (`POST /api/v1/files`) e
   chama `POST /api/v1/dispute/{id}/evidence`.
6. **Decisão**: o webhook `ACCEPTED` / `REJECTED` / `CANCELED` encerra o caso e avisa o seller.

## Rodando

```bash
composer setup                                   # instala, cria .env, migra, builda o front
php artisan make:filament-user                   # usuário do painel (o primeiro vira Super Admin)
php artisan db:seed --class=MedDemoSeeder        # opcional: dados de demo (admin@unicopag.test / password)
composer run dev                                 # http://localhost:8000/admin
```

Produção roda no Laravel Forge, como o KYC: passo a passo em [`doc/DEPLOY_FORGE.md`](doc/DEPLOY_FORGE.md)
(site com https, worker da fila, cron do agendador, variáveis). Depois de qualquer mudança de ambiente:
`php artisan med:diagnostico`.

## Papéis

| Papel | Ver MEDs | Analisar (complemento, parecer, anexo) | Enviar à Woovi | Ver contas | Gerenciar contas e AppIDs | Usuários |
|---|---|---|---|---|---|---|
| Super Admin | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| Admin | ✓ | ✓ | ✓ | ✓ | ✓ | |
| Analista | ✓ | ✓ | ✓ | ✓ | | |
| Visualizador | ✓ | | | ✓ | | |

Usuário sem papel não entra no painel. Papéis são atribuídos em **Sistema > Usuários**.

## Ligando uma conta Woovi

Pelo painel (**Contas Woovi > Novo**) ou pelo unico-api:

```bash
curl -X PUT https://med.unicopag.com.br/api/woovi-accounts \
  -H "X-API-Key: $API_KEY" -H "Content-Type: application/json" \
  -d '{"account_id":"4321","name":"Loja do José","app_id":"<AppID da subconta>","contact_email":"jose@loja.com.br"}'
```

O AppID precisa dos escopos `DISPUTE_GET`, `DISPUTE_GET_LIST`, `DISPUTE_POST`, `FILE_POST` e de webhook.
Depois: **Cadastrar webhooks** na lista (ou `php artisan med:registrar-webhooks --conta=ID`).

## Comandos

| Comando | O que faz | Agenda |
|---|---|---|
| `med:sincronizar [--conta=] [--dias=30]` | busca MEDs novos e relê os abertos na Woovi | de hora em hora |
| `med:alertar-prazos` | lembra o seller (uma vez) e manda ao Discord os prazos vencendo | 9h, 14h e 18h |
| `med:registrar-webhooks [--conta=]` | cadastra os eventos `OPENPIX:DISPUTE_*` na Woovi | manual |
| `med:diagnostico` | confere banco, fila, cron, e-mail, chaves e contas; sai com erro se algo obrigatório falha | manual |

## Testes

```bash
composer test
./vendor/bin/pint --test
```

O contrato da Woovi, as decisões e as perguntas em aberto estão em
[`doc/MED_KNOWLEDGE_BASE.md`](doc/MED_KNOWLEDGE_BASE.md).
