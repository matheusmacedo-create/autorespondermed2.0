# Script de deploy do site no Laravel Forge (Site > Deployments > Deploy script).
# Pressupõe "zero downtime deployments" ligado, como o KYC (/home/forge/<site>/current).
# As macros $CREATE_RELEASE / $ACTIVATE_RELEASE / $RESTART_QUEUES e as variáveis
# $FORGE_* são do Forge - confira com o script padrão que o Forge gera para o site
# e mantenha a ordem abaixo.

$CREATE_RELEASE()

cd $FORGE_RELEASE_DIRECTORY

$FORGE_COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader

npm ci
npm run build

# Migra antes de ativar a release: a versão nova só entra no ar com o banco pronto.
# A migração de papéis (2026_10_13_000002) também roda aqui.
$FORGE_PHP artisan migrate --force

$FORGE_PHP artisan optimize
$FORGE_PHP artisan filament:optimize

$ACTIVATE_RELEASE()

# O worker (daemon queue:work) segura o código antigo em memória até reiniciar.
$RESTART_QUEUES()

# O worker do MED pode rodar como processo em segundo plano (Server > Background
# processes), que o $RESTART_QUEUES() não alcança: reinicia pelo sinal do Laravel.
$FORGE_PHP artisan queue:restart
