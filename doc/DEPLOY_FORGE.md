# Deploy no Laravel Forge

Mesmo servidor e mesmo esquema do KYC (`kyc.unicopag.com.br`): servidor `ms-subscription`, PHP 8.4,
deploy sem downtime, banco no mesmo RDS com schema e usuário próprios. Estes passos são feitos por uma
pessoa no painel do Forge - criar site, cron e variáveis de produção não é tarefa do agente (KB do KYC
8.4 e 13.5).

## 1. Banco

No RDS (o mesmo do KYC): schema `unicopag-med` e um usuário próprio só com permissão nele.

## 2. Site

Forge → servidor `ms-subscription` → **New Site**:

- Domínio: `med.unicopag.com.br` (sugestão - precisa de DNS apontando para o servidor).
- Projeto: Laravel, PHP **8.4**, web directory `/public`.
- Repositório: `matheusmacedo-create/autorespondermed2.0`, branch **`main`**.
- **Zero downtime deployments**: ligado.
- **SSL**: Let's Encrypt. Obrigatório - a Woovi chama o webhook e baixa evidências por https.

## 3. Variáveis de ambiente (Site → Environment)

Partindo do `.env.example`, o que muda em produção:

```dotenv
APP_NAME="UnicoPag MED"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://med.unicopag.com.br
# APP_KEY: o Forge gera. Não troque depois - o AppID das contas Woovi é criptografado com ela.

DB_CONNECTION=mysql
DB_HOST=<host do RDS>
DB_PORT=3306
DB_DATABASE=unicopag-med
DB_USERNAME=<usuário do MED>
DB_PASSWORD=<senha>

# O KYC aprendeu que sessão e cache no banco remoto pesam em toda requisição (KB do KYC 8.17):
# o Redis já está no servidor.
SESSION_DRIVER=redis
CACHE_STORE=redis
QUEUE_CONNECTION=database

MAIL_MAILER=smtp            # o mesmo provedor do KYC
MAIL_FROM_ADDRESS=...
MAIL_FROM_NAME="ÚnicoPag"

API_KEY=<segredo compartilhado com o unico-api>
DISCORD_MED_WEBHOOK_URL=<canal da operação de MED>
DISCORD_MED_ROLE_ID=<cargo a mencionar>

WOOVI_BASE_URL=https://api.woovi.com
WOOVI_WEBHOOK_VERIFY_SIGNATURE=true
WOOVI_EVIDENCE_MODE=file_id
MED_PRAZO_RESPOSTA_HORAS=72
```

> **APP_KEY é chave de cofre aqui:** os AppIDs das contas Woovi ficam criptografados com ela. Trocar a
> `APP_KEY` torna todos ilegíveis - seria preciso recadastrar as contas.

Depois de mudar variável: redeploy (ou `php artisan optimize` em Commands), porque a config fica em cache.

## 4. Script de deploy

Copie `deploy/forge-deploy.sh` para Site → Deployments → Deploy script e ligue **Quick deploy** (deploy a
cada push na `main`).

## 5. Worker da fila (Site → Daemons, ou Server → Daemons)

O webhook da Woovi só grava a chamada e enfileira; quem processa é o worker. Sem ele, MED nenhum entra.

- Comando: `php8.4 artisan queue:work database --sleep=3 --tries=5 --max-time=3600`
- Diretório: `/home/forge/med.unicopag.com.br/current`
- Usuário: `forge`, 1 processo.

## 6. Agendador (Server → Scheduler)

- Comando: `php8.4 /home/forge/med.unicopag.com.br/current/artisan schedule:run`
- Usuário `forge`, **Every Minute**.

Sem este cron a sincronização de hora em hora e os alertas de prazo **existem e nunca rodam** - foi o
que aconteceu no KYC por semanas (KB do KYC 8.4).

## 7. Primeiro acesso (Site → Commands)

```bash
php artisan make:filament-user        # o primeiro usuário vira Super Admin
php artisan med:diagnostico           # tem que terminar com "Ambiente ok."
```

O `med:diagnostico` confere banco, migrações, worker parado, cron (pelo batimento que o agendador grava a
cada minuto), e-mail, chaves, assinatura do webhook, disco das evidências e contas sem webhook. Rode de
novo depois de qualquer mudança de ambiente.

## 8. Ligar as contas

1. Cadastrar as contas Woovi (painel ou `PUT /api/woovi-accounts` pelo unico-api).
2. `php artisan med:registrar-webhooks` - cadastra os eventos `OPENPIX:DISPUTE_*` de cada conta.
3. `php artisan med:sincronizar --dias=90` - traz os MEDs que já existiam.
4. `php artisan med:diagnostico` de novo.

## Evidências

Ficam em `storage/app/private/med` (disco `local`), que o Forge mantém entre releases. Para backup e
mais de um servidor, apontar `MED_EVIDENCIAS_DISK` para um disco S3 (configurar em `config/filesystems.php`).
