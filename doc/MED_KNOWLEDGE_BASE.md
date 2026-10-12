# MED - Base de Conhecimento

> **Documento vivo.** Registra como o responder de MED **é** e o que se sabe do contrato da Woovi.
> Última atualização: 2026-10-12 (papéis e permissões).

Mesma regra do KYC: comportamento e decisão vêm para cá; o que falta fazer vai para o card. Cada fato diz
como foi apurado - `[doc-woovi]` documentação pública da Woovi, `[openapi]` spec do repo
`Open-Pix/woovi-developers`, `[código]` lido no fonte deste repo, `[kyc]` herdado da base do KYC,
`[inferido]` dedução não confirmada, `[decisão]` acordado com o negócio.

---

## 1. A arquitetura em um minuto

```
Woovi (conta do seller) --webhook OPENPIX:DISPUTE_*--> /api/webhooks/woovi/{webhook_key}
                         <--GET /dispute (sync de hora em hora)--
        |
        v
   disputes (caso) --e-mail com link--> seller abre /med/{token} e manda relato + comprovantes
        |
        v
   fila no painel (/admin/disputes) --analista revisa, pede complemento ou envia-->
        |
        v
   POST /files (DISPUTE_EVIDENCE) + POST /dispute/{id}/evidence  -->  Woovi / Bacen decide
        |
        v
   webhook ACCEPTED / REJECTED / CANCELED  -->  caso encerrado, seller avisado
```

- **Uma conta Woovi por seller.** O unico-api cria a subconta via `POST /account-register` e guarda o AppID
  dela em `acquirer_recipient_data` `[kyc]` (KB do KYC 11.4 e 11.8). O MED cai na conta do seller, e só o
  AppID daquela conta lê a disputa e envia evidência. Por isso `woovi_accounts` guarda um AppID por conta,
  criptografado com a `APP_KEY` `[código]`.
- **Três origens de AppID** (`WooviAccountSource`): cadastro manual no painel, envio pelo unico-api
  (`PUT /api/woovi-accounts`) e, no futuro, autorização via WooviApp (OAuth). `[código]`

## 2. O contrato da Woovi (conferido em 12/10/2026)

### 2.1 Endpoints usados

| Uso | Endpoint | Escopo | Fonte |
|---|---|---|---|
| Ler um caso | `GET /api/v1/dispute/{id}` - **o id é o endToEndId** | `DISPUTE_GET` | `[openapi]` |
| Listar casos | `GET /api/v1/dispute?start&end` (paginado, `pageInfo.hasNextPage`) | `DISPUTE_GET_LIST` | `[openapi]` |
| Subir arquivo | `POST /api/v1/files` multipart, `purpose=DISPUTE_EVIDENCE`, `correlationID` | `FILE_POST` | `[doc-woovi]` |
| Enviar evidência | `POST /api/v1/dispute/{id}/evidence` `{documents:[{fileId\|url, description, correlationID}]}` | `DISPUTE_POST` | `[doc-woovi]` `[openapi]` |
| Cadastrar webhook | `POST /api/v1/webhook` `{webhook:{name, event, url, isActive}}` | webhook | `[openapi]` |
| Chave do webhook | `GET /api/v1/webhook/public-keys` (aberto, sem auth) | - | `[doc-woovi]` |

- Autenticação: header `Authorization: <AppID>` (sem `Bearer`). `[doc-woovi]`
- Arquivo: até 10 MiB, PDF/PNG/JPEG/WEBP, os bytes iniciais têm de bater com o content type. Reenviar com o
  mesmo `correlationID` + `purpose` devolve o arquivo já guardado (200) - é o que torna o reenvio seguro.
  `[doc-woovi]`
- `fileId` exige a feature `DISPUTE_EVIDENCE_FILE_ID` na conta; sem ela a Woovi devolve 403
  `DISPUTE_EVIDENCE_FILE_ID_NOT_ALLOWED`. O envio então cai sozinho para `url` (link assinado deste
  sistema, que a Woovi baixa e re-hospeda). `[doc-woovi]` `[código]`
- Não há campo de texto livre na evidência: só `description` por documento. Por isso o relato do seller
  vai como **carta de defesa em PDF** gerada pelo sistema. `[doc-woovi]` `[decisão]`
- Erro documentado: `400 "You cannot send a evidence for a accepted dispute"`. `[openapi]`

### 2.2 Webhook

- Eventos: `OPENPIX:DISPUTE_CREATED`, `_ACCEPTED`, `_REJECTED`, `_CANCELED`. `[openapi]`
- Payload: `{event, dispute:{status, endToEndId, name, email, phoneNumber, value, disputeReason}}`. O exemplo
  da doc **não traz `id` nem identificação da conta**. `[doc-woovi]` Por isso cada conta tem uma URL própria
  (`/api/webhooks/woovi/{webhook_key}`). `[decisão]`
- Assinatura: header `x-webhook-signature` = base64(RSA-SHA256(corpo bruto, chave privada da Woovi)).
  Qualquer chave publicada vale (rotação). O teste `WebhookSignatureVerifierTest` usa o exemplo **real** da
  doc - payload, assinatura e chave - e passa. `[doc-woovi]` `[código]`
- Existe também HMAC por webhook (`X-OpenPix-Signature`); não é usado. `[doc-woovi]`

### 2.3 Status

- No webhook/GET: `OPENED`, `IN_REVIEW` ("em revisão, ainda não criada"), `CREATED`, `ACCEPTED`,
  `REJECTED`, `CANCELED`; a página de MED cita ainda `PENDING`, `PENDING_DOCUMENTATION`, `ACKNOWLEDGED`,
  `CLOSED`. `[doc-woovi]` `[openapi]`
- **Só `ACCEPTED`, `REJECTED` e `CANCELED` encerram o caso** aqui (`DisputeStatus::fromWooviStatus`). O
  resto só atualiza `woovi_status`. `[código]`
- `ACCEPTED` = a devolução foi aceita: o dinheiro volta ao pagador, **o seller perdeu**. `REJECTED` = a
  contestação foi recusada, **o seller ganhou**. Na criação a Woovi bloqueia o saldo da transação "para que o
  dinheiro possa ser devolvido caso a disputa seja aceita". `[doc-woovi]`
- `value` vem em centavos - número no webhook, string no `GET /dispute/{id}`. `[openapi]`

### 2.4 Inconsistências da doc da Woovi (tratadas no código)

- A página de evidência fala em "id da disputa recebido pelo webhook", mas o webhook não traz `id`. Usamos o
  `id` interno quando a Woovi o devolve e o `endToEndId` nos outros casos (`Dispute::wooviIdentifier()`).
  **Confirmar com a Woovi que o endpoint de evidência aceita o endToEndId.** `[inferido]`
- O status `ACCEPTED`/`REJECTED` do evento pode faltar no campo; o nome do evento decide. `[código]`

## 3. O que a Woovi não documenta (perguntar)

1. **Prazo de resposta.** Nenhum campo de prazo, nenhum texto. Usamos 72h desde a abertura
   (`MED_PRAZO_RESPOSTA_HORAS`) como referência de mercado. `[inferido]`
2. **Aceitar a devolução pela API.** A doc diz que o seller pode "apresentar evidências ou aceitar a
   devolução", mas não há endpoint. Hoje aceitar = não enviar defesa.
3. **Features da conta:** "MED API" (para `/funds-recovery/*`) e `DISPUTE_EVIDENCE_FILE_ID`.
4. **Escopos `DISPUTE_*` em WooviApp.** Não estão na lista de bloqueados, mas o catálogo completo não é
   público. Teste definitivo: criar o app declarando `DISPUTE_POST`.
5. Se o webhook de disputa traz `company`/`account` como os de cobrança (o exemplo da doc não traz).

## 4. Decisões

| Decisão | Por quê |
|---|---|
| URL de webhook por conta | o payload não identifica a conta (2.2) |
| Gravar o webhook cru antes de processar (`woovi_webhook_calls`) | padrão "falha sem rastro" do KYC: nada se perde se o processamento quebrar |
| Sincronização de hora em hora (`med:sincronizar`) | rede de segurança para MED ou decisão que o webhook perdeu |
| Marcar enviado só depois da Woovi confirmar | padrão "escrever local antes de confirmar o remoto" do KYC |
| Carta de defesa em PDF | a API não tem campo de texto (2.1) |
| Seller avisado por e-mail; WhatsApp (Kapso) depois | o link não pode ir em template da Meta; precisa de template de MED aprovado e do fluxo de dois passos do KYC (KB do KYC 8.23) |
| Caso que já chega encerrado não cobra defesa | sincronização de histórico não pode disparar e-mail para caso decidido |

## 5. Papéis e permissões `[código]`

- Seis permissões (`App\Support\Permissions`): ver MEDs, analisar (complemento, parecer, anexo, reler na
  Woovi), enviar à Woovi, ver contas, gerenciar contas (AppID, webhooks, sincronização) e gerenciar usuários.
- Papéis: Super Admin (tudo, e passa no `Gate::before`), Admin (sem usuários), Analista (analisa e envia, só
  vê contas) e Visualizador (só leitura). `[decisão]` - enviar à Woovi ficou com o Analista porque é o
  trabalho dele; o que é sensível de verdade é o AppID, que fica com Admin.
- **Ninguém fica trancado no deploy:** a migração que cria os papéis promove a Super Admin todo usuário que
  já existia (antes dela todos podiam tudo), e o primeiro usuário criado num ambiente novo vira Super Admin.
- Ação escondida também é recusada no servidor (o Filament não monta ação invisível) - há teste que forja a
  chamada direto no Livewire. Download de evidência pelo painel exige `view disputes`.

## 6. Contratos com o unico-api

Mesma `X-API-Key` (`API_KEY`), fail-closed: sem chave configurada, nega.

- `PUT /api/woovi-accounts` - `{account_id, name, app_id, tax_id?, woovi_company_id?, contact_name?,
  contact_email?, contact_phone?, is_active?}`. Upsert por `account_id` (accounts.id). Devolve
  `webhook_url`, que é a URL a cadastrar na Woovi (ou rodar `med:registrar-webhooks`). A `webhook_key` não
  muda em atualizações.
- `GET /api/woovi-accounts/{account_id}/disputes` - últimos 100 casos da conta, com `seller_link` enquanto o
  caso aceita defesa (para o dashboard do seller mostrar o botão).

## Histórico de atualizações

- **2026-10-12** - papéis e permissões (seção 5).
- **2026-10-12** - criação: contrato da Woovi conferido na doc pública e no OpenAPI, primeira versão do fluxo.
