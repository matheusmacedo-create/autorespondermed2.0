# autorespondermed2.0

Responder de MED da Unico.Pag: liga as contas Woovi dos sellers à operação da Unico.Pag e responde as
contestações de Pix (MED, Mecanismo Especial de Devolução) pela API da Woovi. Mesmo desenho do KYC
(`unico-pag/unicopag-kyc`): link público por token para o seller, fila de análise no painel, trilha de
eventos e alertas no Discord. Aqui o "onboarding" é o caso de MED e o "documento" é a evidência.

## Stack
- Laravel 12, PHP ^8.2, Filament 4 (painel em `/admin`), Livewire 3 (formulário público), dompdf.
- Produção: MySQL/MariaDB + fila em banco, como o KYC. Testes: SQLite isolado por processo.

## Comandos
- Setup: `composer setup`. Dev (server + fila + logs + vite): `composer run dev`.
- Testes: `composer test` (`php artisan test`). PHPUnit, sem Pest. Estilo: `./vendor/bin/pint`.
- Usuário do painel: `php artisan make:filament-user` (o primeiro vira Super Admin; os demais ganham papel em
  Sistema > Usuários). Demo local: `php artisan db:seed --class=MedDemoSeeder`.
- Agendados (precisam do cron `schedule:run`): `med:sincronizar` (de hora em hora) e `med:alertar-prazos`.
- `med:registrar-webhooks` cadastra os eventos `OPENPIX:DISPUTE_*` nas contas Woovi.
- `med:importar-contas` (de hora em hora, com `WOOVI_MASTER_APP_ID`): lista as subcontas pela API de parceiro
  da Woovi, cria em cada uma um aplicativo só com os escopos do MED e já liga webhooks e MEDs. Conta que já
  existe nunca ganha chave nova (o `POST /partner/application` não é idempotente).
- `med:diagnostico` confere o ambiente de verdade (worker, cron pelo batimento, e-mail, chaves). Deploy no
  Forge, igual ao KYC: `doc/DEPLOY_FORGE.md` e `deploy/forge-deploy.sh`.

## Estrutura que importa
- `app/Actions/Med/` - as regras do fluxo (ingestão, defesa do seller, complemento, carta de defesa, envio à Woovi, sincronização).
- `app/Services/Woovi/` - cliente da API e verificação da assinatura do webhook.
- `app/Livewire/Med/SellerDefenseForm.php` - formulário público `/med/{token}`.
- `app/Filament/Resources/Disputes` (fila e ficha) e `WooviAccounts` (contas e AppIDs).
- `routes/api.php` - webhook da Woovi (um por conta) e a ponte com o unico-api (X-API-Key).

## Permissões
- spatie/laravel-permission, como o KYC. Catálogo em `app/Support/Permissions.php` (papéis Super Admin, Admin,
  Analista, Visualizador); a migração `2026_10_13_000002` cria tudo em todo deploy. Usuário sem papel não entra.
- Toda ação nova do painel precisa de `->visible(fn () => Permissions::allows(...))`: o Filament recusa no
  servidor chamar ação escondida (`PermissionsTest::test_forced_call_to_a_hidden_action_does_nothing`).

## WhatsApp (Kapso)
- Mesmo número oficial e mesma chave do KYC, webhook e segredo próprios (`/api/webhooks/kapso`). A Kapso manda
  ao webhook TODA mensagem do número: o MED só age em resposta a template dele (citado ou do mesmo telefone,
  ainda esperando). Não relaxe esse filtro - responderia conversas do KYC e do atendimento.
- Template primeiro, link só na resposta (janela de 24h da Meta). Pedido para parar bloqueia o telefone para
  sempre no WhatsApp; o e-mail continua.

## Dossiê automático
- Ao receber o MED, `BuildDossierAction` puxa Woovi (transação, cobrança, devoluções, comprovante), unico-api
  (venda - rota ainda a criar - e cadastro) e KYC (status), gera evidências `uploaded_by=system` e roda a
  conferência (`MatchDossierAction`). Na defesa do seller, `ProcessSellerDefense` completa, reconfere e decide o
  envio automático (`AutoSendDisputeAction`, com travas). Detalhes e contrato do unico-api: KB seção 8.
- Documento gerado só muda pelo `StoreSystemEvidence` (troca o correlationID quando o conteúdo muda; o que foi
  enviado nunca muda). Só item ok da conferência vai para a carta. PII do pagador: payload criptografado,
  PDFs mascarados (`App\Support\Mask`), nada no link público nem no Discord.

## Armadilhas
- O webhook de disputa da Woovi **não diz de qual conta veio**: a conta sai da `webhook_key` na URL. Não
  troque por uma URL única.
- O caso é identificado pelo `endToEndId` (é o que o `GET /dispute/{id}` aceita); o `id` interno da Woovi
  nem sempre vem.
- Na Woovi, `ACCEPTED` = devolução aceita (seller **perdeu**); `REJECTED` = contestação recusada (seller
  **ganhou**). O enum `DisputeStatus` documenta isso - não inverter nos textos.
- Verifique a assinatura sobre o **corpo bruto** (`$request->getContent()`), nunca sobre JSON reserializado.

## Base de conhecimento (ler e manter)
`doc/MED_KNOWLEDGE_BASE.md` registra o contrato da Woovi como foi conferido, as decisões e o que ainda está
em aberto. Leia antes de mexer no fluxo; atualize quando descobrir algo não óbvio (mesma regra do KYC).
