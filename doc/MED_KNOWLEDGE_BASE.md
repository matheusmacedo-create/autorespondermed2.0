# MED - Base de Conhecimento

> **Documento vivo.** Registra como o responder de MED **é** e o que se sabe do contrato da Woovi.
> Última atualização: 2026-10-12 (dossiê automático e envio automático).

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
- **Quatro origens de AppID** (`WooviAccountSource`): cadastro manual no painel, envio pelo unico-api
  (`PUT /api/woovi-accounts`), **importação pela conta mestre** (abaixo) e, no futuro, autorização via
  WooviApp (OAuth). `[código]`
- **Importação automática pela conta mestre** (`med:importar-contas`, de hora em hora, desde 22/10/2026).
  São centenas de sellers: cadastrar um a um não escala. Com um aplicativo da conta mestre/parceira da
  ÚnicoPag criado só para o MED (`WOOVI_MASTER_APP_ID`, escopos `PARTNER_COMPANY_GET_LIST` e
  `PARTNER_APPLICATION_POST`; **não** reaproveitar a chave do unico-api, que tem acesso total) o MED lista as subcontas em
  `GET /partner/affiliate` (paginado por `skip`/`limit`; `company.taxID` vem como string ou como
  `{taxID, type}`) e os pré-cadastros em `GET /partner/company` (trazem o usuário mestre = contato), e para
  cada CNPJ **sem conta aqui** cria um aplicativo na subconta via `POST /partner/application`
  (`application.type = API`, `scopes` só com o que o MED chama) - o AppID é `base64("clientId:clientSecret")`.
  Conta que já existe, de qualquer origem, **nunca ganha chave nova**: a rota não é idempotente e cada chamada
  deixaria mais uma credencial na subconta do seller; só os campos em branco (contato, id da empresa) são
  completados. Pré-cadastro sem `company.id` (cadastro não concluído) é pulado. Travas (revisão de 22/10):
  conta ativa sem CPF/CNPJ nem id da empresa **impede a criação** (não dá para provar que a subconta não está
  aqui - seria duplicar o seller: dois casos por MED, dois avisos); 401/403/429 ou 400 de escopo **param a
  rodada** (é a mesma recusa para todas) com alerta no Discord; **nunca** há aplicativo "sem escopos"; uma
  importação por vez (`Cache::lock`), e cada CNPJ sob a mesma trava que o `PUT /api/woovi-accounts`, que adota
  a conta já importada do mesmo CNPJ em vez de criar outra. Conta ativa sem webhook ou nunca sincronizada é
  religada na rodada seguinte. Os escopos
  vêm do `x-required-scope` do OpenAPI (`api.woovi.com/api/openapi.json`): `DISPUTE_GET`, `DISPUTE_GET_LIST`,
  `DISPUTE_POST`, `FILE_POST`, `WEBHOOK_GET_LIST`, `WEBHOOK_POST`, `TRANSACTION_GET`, `CHARGE_GET`,
  `CHARGE_REFUND_GET_LIST`, `FUNDS_RECOVERY_GET` (o comprovante `/receipt` não exige escopo). As rotas de
  parceiro exigem `PARTNER_COMPANY_GET_LIST` e `PARTNER_APPLICATION_POST` na conta mestre e a feature de
  parceiro ativada pelo suporte da Woovi. `[openapi]` **Em aberto:** se as subcontas criadas pelo unico-api via
  `/account-register` (BaaS) aparecem em `/partner/affiliate` - confirmar na primeira execução real.

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
| Transação (dossiê) | `GET /api/v1/transaction/{id}` - **aceita o endToEndId**; valor, horário, pagador, txid, `charge.correlationID` | `TRANSACTION_GET` | `[openapi]` |
| Cobrança (dossiê) | `GET /api/v1/charge/{id}` - aceita o correlationID; `customer`, `comment`, `additionalInfo`, `createdAt` | `CHARGE_GET` | `[openapi]` |
| Devoluções (dossiê) | `GET /api/v1/charge/{id}/refund` - `{refunds:[{value,status,time}]}` | `CHARGE_REFUND_GET_LIST` | `[openapi]` |
| Comprovante (dossiê) | `GET /api/v1/receipt/pix-in/{endToEndId}` - PDF | nenhum declarado | `[openapi]` `[doc-woovi]` |

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
- **O GET de MED não traz valor, pagador nem horário** (dispute-get-payload: só type, status, endToEndId e
  id). Quem traz é a transação - o dossiê preenche `disputes.value` com ela. `[doc-woovi]` `[código]`
- `GET /transaction/{id}` responde transação inexistente com **400 "Transaction not found"**, não 404;
  `WooviException::isNotFound()` trata os dois. `[doc-woovi]`
- Pix avulso (pago direto na chave) vem com `charge: null`: sem cobrança, sem correlationID, sem
  `additionalInfo` e sem como ligar à venda na plataforma. `[doc-woovi]`
- `debitParty`/`creditParty` aparecem só no **exemplo** do GET de transação, com os papéis aparentemente
  trocados para um Pix recebido - não são usados até uma chamada real confirmar. `[openapi]`

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
6. **Se o AppID das subcontas (account-register) tem `TRANSACTION_GET`, `CHARGE_GET` e
   `CHARGE_REFUND_GET_LIST`.** Sem eles o dossiê fica só com o que a plataforma e o KYC dão. Confirmar com
   uma chamada real antes de ligar `MED_DOSSIE_HABILITADO`.
7. Se a Woovi aceita (e considera) um **segundo** `POST /dispute/{id}/evidence` no mesmo caso. Por isso o
   envio é sempre único e consolidado: dossiê + defesa do seller juntos.
8. Se o comprovante (`/receipt/pix-in`) sai para transação com saldo bloqueado por MED, e se
   `funds-recovery/{e2e}/disputes` (feature "MED API") responde para o recebedor.

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

## 6. WhatsApp oficial (Kapso) `[código]`

- **Mesmo número e mesma chave de projeto do KYC** (+55 21 92367-4860, KB do KYC 8.23), com webhook e segredo
  próprios. Uma conta Kapso aceita mais de um webhook por número; os dois sistemas recebem tudo.
- **Dois passos**, pela regra da Meta: a empresa só inicia conversa com template aprovado, e texto livre (o
  link) só chega a quem falou com a ÚnicoPag nas últimas 24h. Sai o template (`MED_WHATSAPP_TEMPLATE`,
  `{{1}}` = primeiro nome); a resposta do seller chega pelo webhook e o link vai como texto. Se o seller
  respondeu um template do MED nas últimas 24h, o link vai direto.
- **O MED só responde ao que é dele** (`whatsapp_messages`): resposta que cita um template do MED, ou, sem
  citação, templates do mesmo telefone ainda esperando resposta (7 dias). Resposta que cita mensagem de outro
  sistema é ignorada. `[decisão]`
- **Qualquer resposta que não seja pedido para parar libera o link** - inclusive "não": o caso é sobre o
  dinheiro do seller e o link é a única ação útil. Pedido para parar ("pare", "sair da lista", "não quero
  receber"...) bloqueia o telefone no WhatsApp para sempre (`whatsapp_opt_outs`); o e-mail continua. `[decisão]`
- Falha da Kapso não segura o e-mail (e vice-versa); fica na linha do tempo com o motivo traduzido.

**Template sugerido para submeter à Meta** (categoria Utilidade, `pt_BR`, um botão de resposta rápida - um
botão de "agora não" seria lido como "pode mandar"):

> Olá, {{1}}! Aqui é da ÚnicoPag. Um Pix recebido na sua conta foi contestado pelo pagador e precisamos da sua
> defesa para tentar manter o valor. Posso te enviar os detalhes e o link agora?
>
> [Pode enviar]

## 7. Produção (Forge) `[decisão]`

- Mesmo servidor e esquema do KYC: Forge, PHP 8.4, deploy sem downtime, schema próprio no RDS. Roteiro em
  `doc/DEPLOY_FORGE.md`.
- O agendador grava um batimento (`med:scheduler:heartbeat`) a cada minuto; o `med:diagnostico` falha se
  ele não existir ou estiver parado há mais de 5 min. Existe por causa do KYC 8.4: o cron nunca foi criado
  e as rotinas agendadas ficaram semanas sem rodar sem ninguém perceber.
- A `APP_KEY` de produção não pode ser trocada: os AppIDs das contas Woovi são criptografados com ela.

## 8. Dossiê automático e envio automático `[código]` `[decisão]`

**O que é.** Ao receber o MED, o sistema monta sozinho o "dossiê" do caso, antes de o seller abrir o link.
Quando o seller responde, cruza o que ele mandou com o dossiê, completa o que faltava e - se a opção e as
travas deixarem - envia sozinho. Pedido do Matheus em 12/10/2026. Desligado por padrão
(`MED_DOSSIE_HABILITADO`, `MED_ENVIO_AUTOMATICO`).

**Fontes, na ordem** (`dispute_sources`, uma linha por fonte, payload criptografado):

| Fonte | De onde | Depende de |
|---|---|---|
| Transação | Woovi `GET /transaction/{e2e}` | - |
| Cobrança | Woovi `GET /charge/{correlationID}` | `transaction.charge.correlationID` |
| Devoluções | Woovi `GET /charge/{correlationID}/refund` | idem |
| Comprovante | Woovi `GET /receipt/pix-in/{e2e}` (vira evidência PDF) | transação |
| Alegação | Woovi `GET /funds-recovery/{e2e}/disputes` - `situationType` (SCAM, ACCOUNT_TAKEOVER...) | feature "MED API" (403 = não configurado) |
| Venda | unico-api, rota **a criar** (abaixo) | `unicopag_account_id` + correlationID/txid/e2e |
| Cadastro | unico-api `GET /api/kyc-panel/accounts/{account_id}/register` (existe, KB do KYC 8.22) | `unicopag_account_id` |
| KYC | KYC `GET /api/onboarding/status?account_id=` (pública) | `unicopag_account_id` |

Fonte que falha não derruba as outras. `failed`, `skipped` (esperava a transação) e `not_configured` são
reavaliadas a cada montagem - no job (que volta para a fila se a transação falhar), na sincronização de hora
em hora (que reenfileira caso sem dossiê, `failed`/`partial` ou com fonte que falhou), na defesa do seller e no
botão "Montar dossiê agora"; `not_found` é resposta e fica. **Releitura que falha não apaga dado bom**: fica o
payload anterior, com o erro guardado e o `fetched_at` antigo. Montagem e envio usam a mesma trava por caso
(`DisputeLock`), e a montagem sempre relê o caso do banco antes de começar. A transação vem primeiro porque é ela que liga o e2e à
cobrança - e, por ela, à venda na plataforma: a chave de junção mais provável com o unico-api é o
`correlationID` da cobrança, que a plataforma escolhe ao criar a cobrança com a chave da subconta.
`[inferido]`

**Documentos gerados** (`uploaded_by = system`, `source` = chave de upsert): o comprovante oficial da Woovi,
o "Registro da venda" (cobrança, pagamento, pedido, entrega, devoluções) e os documentos que a plataforma
já tem (nota fiscal etc., baixados das URLs que o unico-api devolver - só https, sem redirect, tipo pelos
bytes, PDF/PNG/JPEG/WEBP, até 10 MB). Documento da plataforma só entra quando o valor da venda bate com o do
Pix: pedido errado traria a nota (e os dados) de outro comprador. A identidade do documento entre montagens é o
`id` dele, ou tipo + URL sem a query (URL pré-assinada muda a cada resposta); conteúdo idêntico a uma evidência
do caso nunca vira outra linha. O analista pode **descartar** evidência ainda não enviada (soft delete: some do
envio, e a remontagem não a traz de volta; o seller reenviando o mesmo arquivo também não - só o analista
re-anexando restaura). O descarte roda sob a trava do caso e refaz a conferência, e o envio refaz a conferência
antes de gerar a carta: a carta nunca afirma um documento que saiu do caso. Documento gerado que muda antes de ser enviado troca de arquivo **e de correlationID** (o upload da
Woovi é idempotente por correlationID: com o antigo, ela devolveria o arquivo velho); depois de enviado,
nunca muda - nasce outro. A "impressão digital" de PDF gerado é o hash dos dados, não dos bytes (o dompdf
embute a data).

**Conferência** (`disputes.dossier_match`): transação localizada, valor contestado ≤ valor do Pix, Pix pagou
uma cobrança do recebedor, cobrança criada antes do pagamento, CPF do pagador = CPF do cliente **da cobrança**
(nunca `transaction.customer`, que a Woovi cria a partir do próprio pagador - compará-lo "bateria" sempre),
nenhuma devolução, venda localizada, valor do pedido = valor do Pix, entrega comprovada (plataforma,
rastreio do seller ou comprovante anexado), KYC aprovado, relato e comprovantes do seller. Cada item: ok,
divergente ou sem dado. **Só os itens ok vão para a carta** (como fatos); divergência fica no painel. O que só
o seller declarou (rastreio digitado, comprovante enviado por ele) fica ok na conferência, mas **não vira fato
"conferido pela ÚnicoPag"**. "Pronto"
= transação localizada + nenhuma divergência que bloqueia (valor, pagador, devolução, valor do pedido, KYC) +
alguma prova para mandar.

**MED 2.0:** a disputa pode vir de um pagamento que só foi "parte do caminho do dinheiro"; o pagador pode não
ser a vítima. Por isso a carta afirma fatos ("o CPF de quem pagou é o mesmo do cliente da cobrança"), nunca
"o reclamante é o comprador". `[doc-woovi]`

**Do lado do seller:** o link mostra o que a ÚnicoPag já reuniu (só o tipo do documento - o link circula e
não pode expor o pagador), pede só o que o dossiê não achou (nº do pedido, rastreio) e deixa o arquivo
opcional quando já há comprovante. Arquivo idêntico (sha256) a uma evidência do caso é ignorado.

**Envio automático** - só com TODAS as travas, senão vai para o analista com o motivo na linha do tempo. Na
dúvida, bloqueia (dado que falta ou não foi relido é trava, não passe):
- opção ligada e opt-in na conta (`woovi_accounts.auto_send`); caso em "Defesa recebida";
- **o analista nunca pediu complemento** no caso (a resposta ao que ele pediu é ele que vê);
- conferência pronta; devoluções e KYC **relidos depois da defesa** (`ProcessSellerDefense` relê transação,
  devoluções e KYC) - devolução já feita ou KYC não aprovado bloqueiam, releitura que falhou também;
  conta sem `unicopag_account_id` com o KYC ligado fica "KYC não conferido" e bloqueia (só o KYC desligado no
  ambiente deixa passar);
- valor até o teto (`MED_ENVIO_AUTOMATICO_TETO_CENTAVOS`);
- **alegação na lista branca** (`situationType`, só pela rota de recuperação de fundos;
  `MED_ENVIO_AUTOMATICO_SITUACOES_PERMITIDAS`, padrão só `SCAM`). Conta invadida, coação, `UNKNOWN`, `OTHER` e
  qualquer valor novo da Woovi vão ao analista; sem alegação, não envia (`MED_ENVIO_AUTOMATICO_EXIGIR_SITUACAO`);
- motivo sem sinal de conta invadida/coação, no texto da disputa **e** no `disputeReason` da recuperação de
  fundos (`MED_ENVIO_AUTOMATICO_MOTIVOS_BLOQUEADOS` - **entre aspas no .env**: tem espaço, e sem aspas o Dotenv
  derruba o boot).

Conta invadida ou coação: o "seller" pode ser o golpista - defender sozinho é risco de PLD. **Na prática, sem a
feature "MED API" na conta a Woovi não informa a alegação e o envio automático não acontece** - de propósito.
Revisão adversarial de 12/10/2026 (5 lentes, 3 céticos por achado): 13 problemas confirmados, todos corrigidos
com teste de regressão (`tests/Feature/Dossier/ReviewRegressionTest.php`). Segunda rodada, só sobre as
correções: 12 confirmados (descarte sem trava e sem reconferência, lista negra da alegação, KYC pulado em conta
sem vínculo, `.env.example` que não subia, URL pré-assinada na linha do tempo...), corrigidos em
`ReviewRound2Test.php`.

**Reenfileiramento:** `med:sincronizar` pede de novo o dossiê que falhou de hora em hora; o "partial" (que pode
ser permanente: rota da venda ainda não existe, conta sem "MED API") só a cada 6h. Fonte ok com erro guardado
(releitura que falhou) é relida na próxima montagem. Erro gravado em fonte ou evento passa por `Mask::urls`
(fica só o host). Caso ocupado (trava): o botão avisa em 5s, o `med:montar-dossie` pula e segue o lote.
Um envio por caso de cada vez (lock), um envio consolidado (dossiê + defesa juntos), e 400 da Woovi no
automático relê o caso. **Não há envio sem o seller:** sem endpoint para aceitar a devolução, não enviar é o
único jeito de aceitar, e enviar fecha o link.

**LGPD:** payload das fontes criptografado; nos PDFs, CPF/CNPJ, e-mail, telefone e IP mascarados; documento
do KYC (e a selfie, dado biométrico) nunca vira evidência - do KYC só o status; nada do dossiê vai ao
Discord. Retenção/anonimização depois do encerramento: decisão do jurídico, ainda não tomada.

### 8.1 Contrato pedido ao unico-api: venda pelo identificador da Woovi

Rota que **não existe hoje** (nenhuma rota de leitura de transação/pedido é conhecida, KB do KYC 8.20/11.13).
Sem ela o dossiê funciona só com a Woovi. Sugestão:

```
GET /api/med/sales/lookup?account_id=&correlation_id=&txid=&end_to_end_id=
X-API-Key: <integração "med", só leitura>     (não reaproveitar a chave "kyc", que escreve)
```

- Pelo menos um identificador; o unico-api casa pelo que tiver e diz qual em `matched_by`.
- **404** quando não achar (nunca 500); **409** se a venda for de outra conta que não `account_id`.
- 200 (pode vir dentro de `data`):

```json
{
  "matched_by": "correlation_id",
  "account_id": "4321",
  "transaction_id": 123,
  "amount_total": 25000,
  "payment_status": "paid",
  "created_at": "2026-10-10T15:19:00Z",
  "paid_at": "2026-10-10T15:30:00Z",
  "order": {"id": "42", "product_type": "physical|digital|service", "items": [{"name": "...", "quantity": 1, "unit_amount": 25000}]},
  "customer": {"name": "...", "document": "...", "email": "...", "phone": "..."},
  "checkout": {"ip": "189.10.20.30", "user_agent": "...", "created_at": "..."},
  "fulfillment": {"status": "delivered|shipped|pending|not_applicable", "carrier": "Correios", "tracking_code": "QB...BR", "delivered_at": "..."},
  "documents": [{"kind": "invoice|delivery_proof|service_proof|contract", "url": "https://...", "description": "Nota fiscal 42"}]
}
```

Valores em centavos. Configurar no MED com `UNICOPAG_SALE_ENDPOINT` (placeholders `{account_id}`,
`{correlation_id}`, `{txid}`, `{end_to_end_id}`).

## 9. Contratos com o unico-api (entrada)

Mesma `X-API-Key` (`API_KEY`), fail-closed: sem chave configurada, nega.

- `PUT /api/woovi-accounts` - `{account_id, name, app_id, tax_id?, woovi_company_id?, contact_name?,
  contact_email?, contact_phone?, is_active?}`. Upsert por `account_id` (accounts.id). Devolve
  `webhook_url`, que é a URL a cadastrar na Woovi (ou rodar `med:registrar-webhooks`). A `webhook_key` não
  muda em atualizações.
- `GET /api/woovi-accounts/{account_id}/disputes` - últimos 100 casos da conta, com `seller_link` enquanto o
  caso aceita defesa (para o dashboard do seller mostrar o botão).

## Histórico de atualizações

- **2026-10-22** - importação automática das subcontas pela conta mestre (`med:importar-contas`, API de
  parceiro: `/partner/affiliate`, `/partner/company`, `/partner/application`); escopos conferidos no OpenAPI.
- **2026-10-12** - segunda revisão do dossiê: lista branca da alegação, descarte sob trava com reconferência,
  KYC sem vínculo bloqueia o automático, partial reenfileirado a cada 6h.
- **2026-10-12** - dossiê automático, conferência e envio automático (seção 8); contrato da rota de venda
  pedido ao unico-api (8.1); endpoints de transação/cobrança/devolução/comprovante da Woovi (2.1).
- **2026-10-12** - WhatsApp oficial pela Kapso (seção 6).
- **2026-10-12** - produção no Forge e `med:diagnostico` (seção 7).
- **2026-10-12** - papéis e permissões (seção 5).
- **2026-10-12** - criação: contrato da Woovi conferido na doc pública e no OpenAPI, primeira versão do fluxo.
