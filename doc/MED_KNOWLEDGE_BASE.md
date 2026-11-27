# MED - Base de Conhecimento

> **Documento vivo.** Registra como o responder de MED **é** e o que se sabe do contrato da Woovi.
> Última atualização: 2026-11-26 (Transfeera, seção 12).

Mesma regra do KYC: comportamento e decisão vêm para cá; o que falta fazer vai para o card. Cada fato diz
como foi apurado - `[doc-woovi]` documentação pública da Woovi, `[openapi]` spec do repo
`Open-Pix/woovi-developers`, `[código]` lido no fonte deste repo, `[kyc]` herdado da base do KYC,
`[inferido]` dedução não confirmada, `[decisão]` acordado com o negócio, `[doc-transfeera]` documentação pública
da Transfeera (docs.transfeera.dev).

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
| Ler um caso | `GET /api/v1/dispute/{id}` - **só aceita o endToEndId** (o id interno dá 400 "Disputa não encontrada"); Pix com mais de uma disputa devolve a **mais antiga** (2.5) | `DISPUTE_GET` | `[openapi]` `[produção]` |
| Listar casos | `GET /api/v1/dispute?start&end` (paginado, `pageInfo.hasNextPage`); `start`/`end` filtram pela criação (`createdAt`), em ordem crescente; cada item traz `id`, `type`, `status`, `endToEndId`, `value`, `disputeReason`, `createdAt` | `DISPUTE_GET_LIST` | `[openapi]` `[produção]` |
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
- **Na prática o webhook traz `id` e `type`**: das 60 chamadas reais até 29/10/2026, 59 vieram como
  `{status, value, disputeReason, endToEndId, type, id}` (sem nome, e-mail nem telefone do pagador) e uma com os
  campos da doc mais `id` e `type`. `[produção]`
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

- A página de evidência fala em "id da disputa recebido pelo webhook", e o exemplo do webhook não traz `id` (o
  webhook real traz, 2.2). Enviamos pelo `id` interno (`Dispute::wooviIdentifier()`; todos os casos o têm desde a
  listagem; aceito nos 59 envios até 29/10) - por endToEndId a evidência poderia cair na outra disputa do mesmo
  Pix (2.5). `[produção]`
- O status `ACCEPTED`/`REJECTED` do evento pode faltar no campo; o nome do evento decide. `[código]`

### 2.5 Um Pix, mais de uma disputa (29/10/2026) `[produção]`

- O mesmo endToEndId pode ter várias disputas na Woovi: das 2.015 da conta mestre, 22 Pix têm duas - MED e
  chargeback, MED cancelado e outro aberto depois, ou dois MEDs cancelados com valores diferentes.
- **Um caso aqui é uma disputa da Woovi**: o índice único é `(woovi_account_id, woovi_dispute_id)` e o endToEndId
  só indexado (migração `2026_10_29_000001`). `IngestDisputeAction` acha o caso pelo `id`; sem `id` (exemplo da
  doc), só se o Pix tiver um caso daquele tipo - com dois, a chamada do webhook fica com o erro e a listagem resolve.
- `SyncDisputeAction`: se o `GET /dispute/{endToEndId}` devolver outra disputa (id diferente), a releitura vem
  da listagem de um dia antes a um dia depois da abertura do caso; a outra disputa não é aplicada a este caso.
- O que aconteceu antes da correção: a ingestão casava só pelo endToEndId e as duas disputas se sobrescreviam a
  cada sincronização. O #273 (MED ganho em 26/10, com chargeback aberto no mesmo Pix) acumulou 146 trocas de status
  em três dias; o #354 (MED novo de 29/10, num Pix com chargeback de 06/10) virava "CHARGEBACK" a cada releitura e
  a resposta automática o pulava ("não é MED"). Os dois foram corrigidos à mão no servidor no mesmo dia.

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
- Na prática a produção roda em SQLite (`storage/med.sqlite`), com web, worker e agendador escrevendo no mesmo
  arquivo. Sem espera configurada, a reserva de job da fila caía em `database is locked` (25/11/2026, 2x por
  dia). Desde 26/11 a conexão abre com WAL, `busy_timeout` de 10s e transação `IMMEDIATE`
  (`DB_JOURNAL_MODE`, `DB_BUSY_TIMEOUT`, `DB_TRANSACTION_MODE`). Com WAL o banco tem os arquivos `-wal` e `-shm`
  ao lado: backup por cópia precisa levar os três, ou usar `sqlite3 storage/med.sqlite ".backup destino"`.

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
| Venda | unico-api `GET /api/med/sales/lookup` (abaixo, no ar desde 26/10/2026) | correlationID/txid/e2e (sem conta) |
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

**Atualizado em 25/10/2026** (MEDs caem na conta mestre): a busca precisa funcionar **só com o
`correlation_id`** - o MED não sabe de qual lojista é o caso - e a resposta traz o lojista em `seller`
(`name`, `trade_name`, `document`, `contact_name`, `email`, `phone`, `kyc_status`) e a `account_id` dele. O
MED guarda isso no caso (`disputes.merchant`, `merchant_account_id`), usa a `account_id` para o cadastro e o
KYC, e avisa o lojista pelo contato da venda (`Dispute::seller()`, com o da conta Woovi como reserva). Lojista
identificado numa montagem e ninguém avisado ainda = aviso na hora, uma vez. Sem KYC configurado, o
`seller.kyc_status` da venda vale como status do KYC. Endpoint recomendado:
`UNICOPAG_SALE_ENDPOINT=/api/med/sales/lookup?correlation_id={correlation_id}&end_to_end_id={end_to_end_id}&txid={txid}`.
O `charge.correlationID` (UUID) existe em toda cobrança paga da conta mestre conferida; sem subconta nem split.

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

## 10. Defesa com IA (25/10/2026)

- **A Woovi não recebe texto de defesa**: a única resposta a um MED é `POST /dispute/{id}/evidence` com documentos
  (`fileId`/`url` + `description`). Não há rota para aceitar a devolução. "Responder com IA" = a IA redige o texto
  que vai **dentro da carta de defesa** (PDF), enviada como evidência. `[openapi]`
- **Insumo mais valioso**: `GET /funds-recovery/{fundsRecoveryId}/infraction-reports` traz `reportDetails` (o relato
  de quem contestou, registrado no Bacen) e `analysisResult`. Exige a feature "MED API" e `FUNDS_RECOVERY_GET`; o
  `fundsRecoveryId` vem no item de `/funds-recovery/{e2e}/disputes`. Sem a feature (403) a IA segue sem o relato. `[openapi]`
- **Desenho**: `GenerateAiDefenseAction` -> `DefenseWriter` (`ClaudeDefenseWriter`, modelo `MED_IA_MODEL`, padrão
  `claude-opus-5-5`, effort `high`, saída em JSON Schema estrito, `fallbacks: 'default'` com o beta
  `server-side-fallback-2026-07-01` para recusa por política). Os dados do caso vão entre marcações e o system prompt
  diz que instrução dentro deles não vale (relato do seller e da vítima são texto livre). O modelo só afirma o que
  está em "fatos conferidos" (os mesmos `MatchDossierAction::facts`), atribui o resto ao recebedor e recomenda
  `defender` / `defender_com_ressalvas` / `nao_defender`. Recusa, `max_tokens`, JSON inválido e erros da API viram
  `AI_DEFENSE_FAILED` na linha do tempo e aviso no sininho; nada é salvo. `[código]`
- **LGPD**: vão ao modelo o tipo, valor, motivo, alegação, datas, nome do recebedor, fatos (já mascarados),
  pendências, tipo/descrição das evidências, relato do seller, parecer e o `reportDetails`. Não vão nome, CPF,
  e-mail nem telefone de quem pagou. O `reportDetails` é texto livre do Bacen e pode citar dado pessoal - é o
  único ponto sem máscara; avaliar com o jurídico antes de ligar em produção.
- **Conta mestre**: na UNICO PAG os MEDs caem **na própria conta mestre** (1.978 disputas desde 07/2025, 57 abertas em
  25/10), não nas subcontas; a conta não tem a função de parceiro (`/partner/*` = 403 "Empresa não possui
  parceirias"). Por isso a conta mestre é cadastrada como uma `woovi_account` e `WOOVI_PARTNER_IMPORT=false`
  desliga a importação. Cada caso ainda não sabe de qual seller é: ligar pela venda (rota de venda do unico-api).

## 11. Operação na conta mestre (26/10/2026)

- **Rota de venda conferida em produção** (unico-api `vps1.unicopag.com.br`, integração `med`): 3 MEDs abertos
  testados; 2 acharam a venda pelo `correlation_id` (`matched_by=correlation_id`, `amount_total` igual ao valor
  do Pix) e 1 voltou 404. As duas vendas achadas vieram com `payment_status=refunded` - confirmar com o unico-api
  se é o bloqueio cautelar do MED aparecendo como estorno.
- **A chave `med_` não abre o cadastro** (`/api/kyc-panel/accounts/{id}/register` responde 401): a fonte
  "Cadastro" fica falha até o unico-api liberar essa rota para a integração `med`.
- **Comprovante oficial da Woovi** responde 401 "missing the required scope" no AppID da conta mestre: falta o
  escopo do `receipt/pix-in` no aplicativo.
- **Modelo de defesa da operação** (PDF da Compliance, caso de R$ 177,00 de 18/10/2026): venda legítima +
  pedido dentro do prazo de entrega em dias úteis (política das lojas: rastreio em até 3 dias úteis, entrega em
  10 a 15) + "o MED é para fraude, não para atraso ou desacordo comercial" + canais oficiais + pedido de
  indeferimento. Anexos usados: comprovante de pagamento da ÚnicoPag e print da política de entrega do site.
  Virou o formato da carta e o estilo da IA. Os MEDs abertos são quase todos "não recebi": sem esse argumento a
  IA recomendava "não defender" por falta de prova de entrega.
- **Políticas do site da loja** (pedido da operação): print das páginas de entrega, termos e trocas guardado por
  loja e reaproveitado; a IA usa o prazo publicado pela própria loja. O site vem do cadastro da plataforma (rota
  de cadastro, ainda 401 com a chave `med_`), do KYC (`OnboardingBusinessProfile.website`, rota de leitura a
  criar no KYC) ou da venda; sem nenhum, o analista preenche em "Lojas".
- **Site da loja no KYC** (rota `GET /api/med/stores/{account_id}`, unicopag-kyc#142, chave `MED_API_KEY`):
  em 26/10/2026, 2 dos 6 lojistas com MED aberto tinham site (AlliPure e Ze dos Mantos); Mega Store, Loja,
  Alfabonix e 4 Seasons não têm site no KYC nem no cadastro copiado da plataforma - preencher em "Lojas".
- **Loja de página única** (AlliPure): "Envio e Entrega", "Trocas" e "Termos" no rodapé apontam para a própria
  página de venda, sem política nenhuma. A captura recusa a página inicial como política; o caso fica sem
  política da loja e a IA usa a política padrão.
- **Worker do MED no Forge é processo em segundo plano**: o `$RESTART_QUEUES()` do deploy não o reinicia (ele
  seguia com o código antigo). O script de deploy tem `artisan queue:restart` explícito.
- **Envio por fileId na conta mestre**: o `POST /api/v1/files` aceita o arquivo, mas o `POST
  /dispute/{id}/evidence` com `fileId` responde 403 "Your account does not have the permission to send a evidence
  by fileId" (falta a feature DISPUTE_EVIDENCE_FILE_ID). O guia da Woovi diz que, por `url`, ela baixa o
  documento na hora do envio e guarda a cópia dela (a resposta traz o link novo). Então o envio reenvia com o
  `file.url` do próprio upload (S3 da Woovi, `woovi-media-production`, vale 1h; o upload repetido com o mesmo
  correlationID devolve o mesmo arquivo e um link novo - conferido em 27/10/2026). A conta guarda a recusa
  (`evidence_file_id_refused_at`) e vai direto pelo link por uma semana; depois tenta o fileId de novo, então
  quando a Woovi liberar a feature o sistema passa a usá-la sozinho. Pedir a feature ao suporte da Woovi.
- **Primeiro envio real (27/10/2026, MED de R$ 177,00 da Mega Store)**: fileId recusado (403), reenvio pelo link
  da Woovi aceito (200) com carta + registro da venda. A resposta devolve, por documento, o link da cópia que a
  Woovi guardou (outro arquivo, bucket privado: abrir de fora dá AccessDenied, é o esperado). O status do MED na
  Woovi segue `ACKNOWLEDGED` depois da resposta; a decisão chega pelo webhook.
- **Erro corrigido (27/10/2026)**: a IA afirmava "a política de envio informada no site da loja prevê 3 + 10 a 15
  dias úteis" em lojas sem política capturada (Mega Store e Loja sem site; AlliPure sem política no site). Os 33
  rascunhos foram refeitos antes de qualquer envio em lote. O #298 (Mega Store, enviado à mão em 26/10) saiu
  com a frase, mas ela confere: o modelo da Compliance é o caso #308 da Mega Store, e o print da política que veio
  com ele mostra rastreio em até 3 dias úteis e entrega em 10 a 15. Falta o site da Mega Store para capturar.
- **Prazo real da Woovi para responder** (medido em 26/10/2026, ~22:30): MED aberto em 22/10 às 19:08 foi recusado
  ("Você não pode enviar uma evidência após o prazo ter expirado", HTTP 400) e o aberto às 22:57 do mesmo dia foi
  aceito: o prazo fica em ~4 dias (96h) da abertura - com abertura numa quinta, também bate com 2 dias úteis.
  Confirmar a regra com a Woovi antes de mudar `MED_PRAZO_RESPOSTA_HORAS` (hoje 72h, mais conservador que 96h).
- **Primeiro lote (`med:responder --incluir-vencidos`, 26/10/2026)**: 29 MEDs respondidos (Mega Store, Loja, 4
  Seasons), 4 recusados por prazo expirado; ficaram de fora os "não defender" (pagador diferente do cliente da
  cobrança ou venda não localizada) e o único CHARGEBACK.
- **Segundo lote (28/10/2026)**: 16 MEDs abertos em 27-28/10 respondidos, 0 falhas; Ze dos Mantos e Volei Sports
  Brasil (site pelo KYC, 3 políticas capturadas) levaram as políticas como evidência. Os "não defender" que restaram
  têm pagador de fato diferente do cliente da cobrança (2 com CNPJ pagando pedido de CPF) e alegação de golpe; um
  pagador cita ter comprado em "megamastershow.site" - triangulação, não se contesta.
- **Resposta automática ligada (28/10/2026)**, a pedido da operação: `med:responder --automatico` de hora em hora,
  contestando também os "não defender" (`MED_DEFENDER_NAO_RECOMENDADOS=true`) para medir depois o que a Woovi
  decide (`med:resultados`). A regra 9 da IA (RULES_VERSION 3) impede a carta desses casos de afirmar "compra
  legítima" ou "sem indício de fraude" quando o pagador não é o cliente da cobrança.
- Decisão: enquanto a operação se organiza, nenhum aviso sai ao lojista (`MED_AVISO_LOJISTA=false`), o painel
  mostra só os últimos 14 dias e cada caso já chega com a defesa da IA para o analista revisar.

## 12. Transfeera (26/11/2026) `[doc-transfeera]` `[código]`

A ÚnicoPag passou a receber Pix também pela Transfeera, e os MEDs de lá são respondidos por este mesmo sistema.
O contrato foi lido na doc pública (docs.transfeera.dev) e as **leituras foram conferidas em produção em
26/11/2026** (abaixo). **A resposta (`POST .../analysis`) e o cadastro do webhook ainda não foram chamados de
verdade.**

**Conferido em produção (26/11/2026, conta principal da ÚnicoPag, só leitura)** `[produção]`:
- Token sem `scope` na conta principal em `login-api.transfeera.com` (com `scope: account_id:<id da própria
  conta>` o login responde 500 - o escopo é só para subconta). `api.transfeera.com` responde sem mTLS.
- `GET /med/infractions`: 6 infrações desde 24/11 (5 `pending`/`pending`, 1 `canceled`/`canceled`), todas
  `is_first_layer: true`, valores de R$ 129,90 a R$ 193,65. **`infraction_date__gte` só aceita `AAAA-MM-DD`**:
  com data e hora, como a doc mostra, responde 400 ("does not match the 2006-01-02 format").
- **Prazo: `analysis_due_date` = `infraction_date` + 96h exatas** nas 6 (o mesmo prazo medido na Woovi).
- `analysis_status` também assume `canceled` (junto com `status: canceled`). A lista já traz `txid`,
  `contested_at` e `refund.monitoring_end_date`; sem análise, `analysis_description` vem `""` e `user.name` null.
- `GET /pix/cashin/{e2e}`: valor em reais, documento do pagador inteiro, `integration_id` preenchido pelo
  unico-api e **`txid` = `integration_id` + sufixo** (ex.: `ybhgznw0ms` + `N2cnaw1Y9d000000`); `pix_description`
  traz o nome da loja. `GET .../refund` e o comprovante (`receipt_file_url`, PDF público) respondem.
- **A conta já tem dois webhooks do unico-api** (`api.unicopag.com.br`): um de `Transfer`/`TransferRefund` e um
  de `CashIn`, `CashInRefund`, `ChargeReceivable`, `Infraction`, `Account`. O `GET /webhook` **não devolve o
  `signature_secret`** (vem null): o segredo só aparece na resposta do `POST /webhook`. O MED cadastra um webhook
  próprio, em outra URL; o do unico-api não é tocado.

**Desenho.** A conta ganhou `provider` (`woovi` | `transfeera`, enum `Provider`). A tabela e as colunas `woovi_*`
ficaram com o nome antigo de propósito; na Transfeera `app_id` guarda `client_id:client_secret`,
`woovi_company_id` o id da subconta (opcional), `webhook_secret` o segredo do webhook, `woovi_dispute_id` o id da
infração e `woovi_status` o status dela. Fila, link do lojista, dossiê, conferência, IA, carta, `med:responder`,
placar e RiskOps são os mesmos: o `InfractionMapper` traduz a infração e o Pix recebido para os campos que o
fluxo já lia da Woovi.

| Uso | Endpoint | Observação |
|---|---|---|
| Token | `POST login-api.transfeera.com/authorization` `{grant_type: client_credentials, client_id, client_secret, scope?}` | vale 30 min (`expires_in`), em cache por conta; subconta = `scope: "account_id:<id>"` |
| Listar | `GET /med/infractions?infraction_date__gte=AAAA-MM-DD&page_size&page_cursor` | cursor em `meta.next`; filtra também por `analysis_status__in`, `transaction_id`, `payer_tax_id` |
| Ler | `GET /med/infractions/{id}` | pelo id da infração (não pelo endToEndId) |
| Responder | `POST /med/infractions/{id}/analysis` multipart: `analysis` (`rejected` = contesta, `accepted` = concorda), `analysis_description`, `attachments[]` | **um arquivo só** (PDF, zip/rar, JPEG/PNG, até 15 MB); só com `analysis_status = pending`; **não dá para editar nem completar depois** |
| Aceitar em lote | `POST /med/infractions/analysis` `{infraction_ids, analysis: accepted}` | só aceita; não usado |
| Pix recebido | `GET /pix/cashin/{end2endId}` | `value` em **reais**, `txid`, `integration_id`, pagador, `receipt_file_url`; **sem data** |
| Devoluções | `GET /pix/cashin/{end2endId}/refund` | `entries[]`, valor negativo, `return_id` |
| Webhook | `POST /webhook` `{url, object_types: ["Infraction"]}` -> `{id, signature_secret}`; `GET /webhook` devolve o segredo também | um webhook só por conta |

- **Headers**: `Authorization: Bearer <token>` e `User-Agent: Empresa (email)` em toda chamada
  (`TRANSFEERA_USER_AGENT`). A doc cita dois hosts de produção: `api.transfeera.com` (OpenAPI) e
  `api.mtls.transfeera.com` (página de endpoints, com certificado). O cliente aceita os dois
  (`TRANSFEERA_BASE_URL`, `TRANSFEERA_LOGIN_URL`, `TRANSFEERA_MTLS_CERT`/`_KEY`). A produção pede o IP de saída
  liberado pelo suporte.
- **Status**: `status` é a decisão final da Transfeera (`pending`, `agreed`, `disagreed`, `canceled`);
  `analysis_status` é a resposta da empresa (`pending`, `accepted`, `rejected`, `delayed`). **`agreed` = infração
  procedente, o lojista perdeu (interno `accepted`); `disagreed` = o lojista ganhou (interno `rejected`).** Não
  inverter, como na Woovi (2.3).
- **Prazo**: cada infração traz `analysis_due_date` - vira o `respond_by` do caso e é o prazo que o
  `med:responder` respeita ("prazo da Transfeera expirado"). Os exemplos da doc variam de 3 a 6 dias; não há
  número fixo. O prazo de 96h medido na Woovi não se aplica.
- **Valores**: a infração vem em centavos (`amount`), o Pix recebido em reais - o mapper converte.
- **Webhook**: envelope `{id, version, account_id, object: "Infraction", date, data: {...infração, txid}}`, um
  evento para tudo (nova, analisada, decidida). Assinatura no header `Transfeera-Signature: t=<ts>,v1=<hex>`,
  `v1 = HMAC-SHA256("<ts>.<corpo bruto>", signature_secret)`; o teste usa o vetor da doc. Sem 2xx a Transfeera
  tenta só mais 2 vezes - a sincronização de hora em hora cobre. O segredo só chega aqui pela ação "Cadastrar
  webhooks" (ou `med:registrar-webhooks`), na resposta do `POST /webhook`: a listagem não o devolve, então
  webhook criado à mão no painel da Transfeera fica sem segredo aqui e é recusado (401).
- **Resposta** (`SendEvidenceToWooviAction::sendToTransfeera`): relê a infração, e só envia se a análise ainda
  não foi registrada; manda `analysis=rejected`, o texto da defesa (cortado em `TRANSFEERA_DESCRICAO_MAX`, com a
  íntegra na carta) e os anexos, carta primeiro. WEBP e o que passar de 15 MB no total ficam fora, com aviso na
  linha do tempo. Se o envio der erro, relê: análise registrada = enviado (resposta que se perdeu no caminho).
- **Respondida fora daqui**: infração com `analysis_status` `accepted`/`rejected` e caso ainda aberto vai para
  "Resposta enviada" sem `evidence_sent_at` (fora do placar), sem aviso ao lojista nem dossiê.
- **Dossiê**: alegação = a própria infração relida (`situation_type`, `infraction_description` - o relato de quem
  contestou, que vai para a IA -, `is_first_layer` e o CPF/CNPJ inteiro do pagador); transação = o Pix recebido,
  com a hora tirada do endToEndId (`E` + ISPB + `AAAAMMDDHHmm` em UTC), porque o cash-in não tem data; o
  documento do pagador no cash-in pode vir mascarado, então vale o da infração. Não há "cobrança": com `txid`
  o item "Pix pagou uma cobrança" fica ok como QR Code do recebedor, e **"quem pagou é o cliente" compara com o
  cliente do pedido na plataforma** (`sale.customer.document`), não com o cliente da cobrança. A venda é buscada
  no unico-api com `correlation_id = integration_id`, `txid` e o endToEndId.
- **LGPD**: o CPF do pagador não entra em `disputes.raw` (sem criptografia); fica na fonte da alegação, que é
  criptografada.

**Primeira rodada em produção (27/11/2026, 12:20)** `[produção]`: site do Forge passou a publicar a `main`
(antes seguia `claude/trusting-hypatia-98phi7`), conta cadastrada (id 4) e 7 infrações importadas. A resposta
automática tentou as 6 pendentes e a Transfeera recusou todas com **400 "Only one file can be uploaded"**: a
análise aceita **um arquivo só**. Desde então, com mais de um anexo, vão todos num `defesa-e-evidencias.zip` (carta
primeiro); com um só, vai ele mesmo. Nada foi registrado na Transfeera nessa rodada (os casos ficaram abertos, com a
falha na linha do tempo). Se o zip não puder ser montado (servidor sem a extensão zip do PHP, disco cheio, zip
incompleto), **nada é enviado** e a falha vai para a linha do tempo e o alerta (`AttachmentBundler`): mandar só a
carta deixaria as outras evidências de fora para sempre. O `med:diagnostico` confere a extensão. **O unico-api não
achou a venda de nenhum dos 6 Pix** em `/api/med/sales/lookup`, nem
pelo `integration_id`, nem pelo `txid`, nem pelo endToEndId: a rota só conhece as vendas da Woovi. Sem ela o caso
fica sem lojista, produto, política da loja e conferência do pagador - pedir ao unico-api a busca das vendas da
Transfeera (o `integration_id` do Pix é dele).

**Primeiras respostas aceitas (27/11/2026, 12:27-12:28)** `[produção]`: as 6 infrações pendentes foram contestadas
pelo `med:responder` (primeiro a de prazo mais próximo, conferida, depois as outras cinco). O que a API real mostrou:
- O campo do arquivo é mesmo **`attachments`**, e o `.zip` é aceito. A infração passa a trazer
  **`files: [{name, signed_url}]`** (link assinado do S3 da Transfeera): baixando por ele, o zip abriu com os 3 PDFs
  (carta, comprovante, registro da venda). É por esse campo que se confere se o anexo chegou.
- Depois do envio: `analysis_status: rejected`, `analysis_date` preenchida, `analysis_description` idêntica ao
  texto enviado (até 1.439 caracteres foram aceitos) e `user.name` com o usuário dono da credencial da API. O
  `status` continua `pending` até a Transfeera decidir.
- A venda segue sem ser achada no unico-api: as seis saíram só com o Pix localizado e o QR Code de cobrança como
  fatos, sem lojista, produto nem política da loja.
- **Webhook não cadastrado em produção** (decisão pendente): a conta já tem um webhook do unico-api para
  `Infraction`, e a doc não diz se dois webhooks do mesmo tipo convivem. Até lá a infração nova entra pela
  sincronização de hora em hora, que basta para o prazo de 96h.

**A venda de um Pix da Transfeera (27/11/2026)** `[produção]`: a rota de venda do unico-api **acha** a venda da
Transfeera - o que faltava era a chave certa. A plataforma guarda em `transactions.transaction` o **id da cobrança
Pix (QR Code)**, não o txid, o `integration_id` nem o endToEndId; por isso as buscas de antes voltavam 404. O
caminho, conferido nos 7 casos reais (7 de 7 acharam loja, produto e cliente):

1. `GET /pix/qrcode/{txid}` na Transfeera (aceita o id do QR Code ou o txid; o txid vem no Pix recebido e na
   infração) devolve a cobrança: `id`, `qrcode_type` (`COBRANCA_IMEDIATA`), `status` (`CONCLUIDA`), `original_value`
   (reais), `payer_question`, `payer {name, document}`, `created_at` (UTC), `settled_at` (-03:00), `pix_received`.
2. `GET /api/med/sales/lookup?correlation_id=<id da cobrança>` no unico-api devolve a venda completa (lojista,
   pedido, itens, cliente, entrega), como nos casos da Woovi.

- A cobrança vira a fonte "Cobrança Pix" do dossiê (`InfractionMapper::toChargeSource`), nos mesmos campos da
  cobrança da Woovi. Com ela a conferência é a mesma: Pix pagou uma cobrança do recebedor, cobrança criada antes
  do pagamento, e **quem pagou x cliente da cobrança**.
- **`payer` do QR Code é o devedor informado na criação (o cliente do pedido), não quem pagou**: nos 7 casos ele é
  igual ao cliente da venda, e em 2 deles (#556 e #558) quem pagou o Pix é outra pessoa. Não é a armadilha do
  `transaction.customer` da Woovi.
- **A hora que sai do endToEndId só tem o minuto**: nos 7 casos ela bate com a liquidação (`settled_at`), no mesmo
  minuto ou um antes (é a hora da ordem no banco de quem pagou). Serve para datar o pagamento, mas não para comparar
  com a criação da cobrança: no #557 a cobrança foi criada às 12:43:17 e liquidada às 12:43:58, e pelo endToEndId
  (12:43:00) pareceria "criada depois do pagamento". Com a cobrança localizada, a hora do pagamento é o `settled_at`
  (`Dispute::paidAt()`, o registro da venda e o item "cobrança criada antes do pagamento"); sem ela, esse item fica
  sem dado em vez de acusar divergência falsa.
- Só vale a cobrança que o próprio Pix liquidou: `pix_received.end2end_id` igual ao endToEndId do caso (veio assim
  nos 7). QR Code sem pagamento registrado, estático ou pago por outro Pix não é usado - a criação, a liquidação e
  o devedor dele não provam nada sobre o caso. Sem a cobrança (Pix sem txid, QR não localizado), a venda ainda é
  tentada pelo `integration_id` e a conferência do pagador cai para o cliente do pedido.
- As seis respostas de 27/11 saíram antes disso, sem lojista nem produto, e não podem ser completadas.

Como isso foi descoberto: leitura pontual do banco da plataforma (`unicopag-prod`, usuário pessoal só com `SELECT`,
a partir do servidor de produção, em 27/11). **O MED não lê o banco em produção e não guarda credencial dele**; fica
aqui só o que ajuda a entender a plataforma:
- `transactions`: `transaction` = id da cobrança no adquirente (Woovi: correlationID; Transfeera: id do QR Code),
  `acquirer_name` = `unicopag` nas duas, `hash` = id público da transação, `amount_total` em centavos, datas em
  horário de Brasília. `payment_status`: 5 aguardando, 3 pago, 4 devolvido (é o MED: `refund_at` = hora em que a
  infração chegou - por isso a venda de um caso em MED vem como `refunded`), 12 a maioria das não pagas.
- `payment_data` (JSON) nas vendas da Transfeera: `txid`, `integration_id`, `qrcode_id`, `end2end_id`, `cashin_id`;
  nas da Woovi, só o link e o QR Code.
- `transaction_med_pixs`: o registro de MED da própria plataforma (`infraction_id` = id da infração na Transfeera,
  ou o endToEndId na Woovi; `transaction_id`; `status` `without_documentation`, `med_aproved`, `med_reproved`...).
  Tinha 5 das 7 infrações da Transfeera, e em 2 delas (#560 e #562) apontava para **outra venda da mesma loja**,
  criada 12 a 15 minutos depois do Pix, com o txid e o endToEndId do caso gravados no `payment_data` dela e o
  `qrcode_id` de outra cobrança. Não é fonte segura para ligar o caso à venda: a cobrança que a Transfeera devolve
  pelo txid é a que bate com a hora do Pix.
- O usuário não tinha acesso à tabela de clientes; o cliente da venda vem pela rota do unico-api.

**Em aberto (conferir com chamada real ou com o suporte da Transfeera):**
1. Se dois webhooks com o mesmo tipo de objeto (`Infraction`) convivem na mesma conta - perguntar ao suporte antes
   de cadastrar o do MED ao lado do que o unico-api já tem.
2. Tamanho máximo de `analysis_description` (1.439 caracteres passaram; o corte está em 4.000).
3. Se `delayed` ainda aceita análise (hoje o `med:responder` não tenta; o botão da ficha tenta).
4. ~~IP e mTLS~~ - resolvido em 27/11: o servidor de produção leu e respondeu por `api.transfeera.com`, sem mTLS
   e sem liberação de IP.
5. Se um mesmo endToEndId pode ter mais de uma infração (o caso é por id da infração, então não se misturam).
6. ~~Venda de Pix da Transfeera~~ - resolvido em 27/11: a rota do unico-api acha a venda pelo id da cobrança Pix
   (`GET /pix/qrcode/{txid}`), não por `txid`, `integration_id` nem endToEndId.
7. Não há como simular infração no sandbox pela doc: pedir ao suporte.
8. O comprovante `/pub/receipt/...` é público pelo link; não há rota documentada para emiti-lo de novo.

## Histórico de atualizações

- **2026-11-27** - venda de Pix da Transfeera localizada pelo id da cobrança (`/pix/qrcode/{txid}`): dossiê
  completo também na Transfeera; hora do pagamento pela liquidação da cobrança, não pelo endToEndId (seção 12).
- **2026-11-27** - Transfeera em produção (seção 12): site do Forge na `main`, conta cadastrada, um arquivo só por
  análise (zip), 6 infrações contestadas e conferidas pelo campo `files`; placar passa a separar as instituições.
- **2026-11-26** - Transfeera como segunda instituição (seção 12): `provider` na conta, webhook `Infraction`,
  sincronização, dossiê pelo Pix recebido e resposta em uma chamada (`analysis=rejected`).
- **2026-11-26** - SQLite de produção com WAL, busy_timeout e transação IMMEDIATE (seção 7).
- **2026-11-07** - rotas de leitura para o RiskOps do KYC (`GET /api/riskops/disputas` e `/{conta}`, X-API-Key
  `RISKOPS_API_KEY`): o MED continua em SQLite no servidor e o KYC lê por API, não pelo banco. A conta agrupada é
  a do lojista da venda (`merchant_account_id`), com a da subconta Woovi como reserva.
- **2026-11-06** - chargeback passa a ser defendido como o MED (`med:responder`), a pedido da operação: o único
  dinheiro perdido desde 26/10 foi um chargeback sem resposta (Pix do #273, R$ 193,91).
- **2026-10-29** - um caso por disputa da Woovi, não por Pix (2.5); GET só por endToEndId e devolve a mais
  antiga; webhook real traz `id` e `type` (2.2).
- **2026-10-29** - placar das respostas no painel e `med:placar`: ganho/perdido só conta o que foi respondido
  (`evidence_sent_at`); o decidido sem resposta nossa fica à parte, para comparar.
- **2026-10-26** - rota de venda no ar (`vps1.unicopag.com.br/api/med/sales/lookup`, chave `med_`); janela de
  14 dias, `med:podar`, aviso ao lojista desligado, defesa com IA automática e fila pelo lojista (seção 11).
- **2026-10-25** - lojista do caso pela venda (`seller` + `account_id`), busca sem conta, aviso ao lojista da venda (8.1).
- **2026-10-25** - defesa com IA (seção 10); MEDs caem na conta mestre; `WOOVI_PARTNER_IMPORT`.
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
