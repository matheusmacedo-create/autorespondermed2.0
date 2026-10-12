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
- Usuário do painel: `php artisan make:filament-user`. Demo local: `php artisan db:seed --class=MedDemoSeeder`.
- Agendados (precisam do cron `schedule:run`): `med:sincronizar` (de hora em hora) e `med:alertar-prazos`.
- `med:registrar-webhooks` cadastra os eventos `OPENPIX:DISPUTE_*` nas contas Woovi.

## Estrutura que importa
- `app/Actions/Med/` - as regras do fluxo (ingestão, defesa do seller, complemento, carta de defesa, envio à Woovi, sincronização).
- `app/Services/Woovi/` - cliente da API e verificação da assinatura do webhook.
- `app/Livewire/Med/SellerDefenseForm.php` - formulário público `/med/{token}`.
- `app/Filament/Resources/Disputes` (fila e ficha) e `WooviAccounts` (contas e AppIDs).
- `routes/api.php` - webhook da Woovi (um por conta) e a ponte com o unico-api (X-API-Key).

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
