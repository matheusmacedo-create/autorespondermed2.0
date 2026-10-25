<?php

namespace App\Console\Commands;

use App\Models\WooviAccount;
use App\Support\Permissions;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Confere se o ambiente está de pé de verdade - não só se o código existe.
 * Feito para rodar no Forge (site > Commands) depois de cada mudança de
 * ambiente. Sai com erro quando algo obrigatório falha.
 */
class DiagnosticsCommand extends Command
{
    protected $signature = 'med:diagnostico';

    protected $description = 'Confere banco, fila, agendador, chaves e integrações do ambiente';

    /**
     * @var array<int, array{0: string, 1: string, 2: string}>
     */
    private array $rows = [];

    private bool $failed = false;

    public function handle(): int
    {
        $production = app()->environment('production');

        $this->check('Ambiente', $production ? 'ok' : 'warn', (string) app()->environment());
        $this->check('APP_DEBUG desligado', config('app.debug') ? ($production ? 'fail' : 'warn') : 'ok', config('app.debug') ? 'ligado' : 'desligado');
        $this->check('APP_URL público com https', str_starts_with((string) config('app.url'), 'https://') ? 'ok' : ($production ? 'fail' : 'warn'), (string) config('app.url'));

        $this->checkDatabase();
        $this->checkQueue($production);
        $this->checkScheduler();

        $this->check('API_KEY (unico-api)', filled(config('services.api.key')) ? 'ok' : 'warn', filled(config('services.api.key')) ? 'definida' : 'vazia: as rotas do unico-api negam tudo');
        $this->check(
            'Assinatura do webhook Woovi',
            config('services.woovi.webhook.verify_signature') ? 'ok' : ($production ? 'fail' : 'warn'),
            config('services.woovi.webhook.verify_signature') ? 'conferida' : 'DESLIGADA',
        );
        $this->check('Woovi base URL', 'ok', (string) config('services.woovi.base_url'));
        $this->check(
            'Defesa com IA (Claude)',
            filled(config('services.anthropic.api_key')) ? 'ok' : 'warn',
            filled(config('services.anthropic.api_key'))
                ? 'ligada: '.config('services.anthropic.model').', effort '.config('services.anthropic.effort')
                : 'desligada (ANTHROPIC_API_KEY vazio): botão "Gerar defesa com IA" escondido',
        );
        $this->check(
            'Conta mestre Woovi (importação)',
            filled(config('services.woovi.master_app_id')) ? 'ok' : 'warn',
            filled(config('services.woovi.master_app_id'))
                ? 'definida: subcontas importadas de hora em hora (med:importar-contas)'
                : 'vazia (WOOVI_MASTER_APP_ID): contas só à mão ou pelo unico-api',
        );

        $mailer = (string) config('mail.default');
        $this->check('E-mail ao seller', in_array($mailer, ['log', 'array'], true) ? ($production ? 'fail' : 'warn') : 'ok', "mailer {$mailer}, de ".config('mail.from.address'));
        $this->checkWhatsApp();
        $this->checkDossier();
        $this->check('Discord (alertas)', filled(config('services.discord.med_webhook_url')) ? 'ok' : 'warn', filled(config('services.discord.med_webhook_url')) ? 'configurado' : 'vazio: alertas só no log');

        $this->checkStorage();
        $this->checkPeople();

        $this->table(['', 'Item', 'Situação'], $this->rows);

        if ($this->failed) {
            $this->error('Há itens obrigatórios falhando (✗).');

            return self::FAILURE;
        }

        $this->info('Ambiente ok.');

        return self::SUCCESS;
    }

    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->check('Banco', 'fail', $e->getMessage());

            return;
        }

        $this->check('Banco', 'ok', DB::connection()->getDriverName().' / '.DB::connection()->getDatabaseName());

        $pending = rescue(function (): int {
            $ran = DB::table('migrations')->pluck('migration')->all();
            $files = collect(glob(database_path('migrations/*.php')))->map(fn (string $f): string => basename($f, '.php'));

            return $files->diff($ran)->count();
        }, -1, report: false);

        $this->check('Migrações', $pending === 0 ? 'ok' : 'fail', $pending === 0 ? 'em dia' : ($pending < 0 ? 'tabela migrations ausente' : "{$pending} pendente(s): rode php artisan migrate --force"));
    }

    private function checkQueue(bool $production): void
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            $this->check('Fila', $production ? 'fail' : 'warn', 'sync: o webhook processa dentro da requisição da Woovi');

            return;
        }

        if ($connection !== 'database') {
            $this->check('Fila', 'ok', $connection);

            return;
        }

        $oldest = rescue(fn () => DB::table('jobs')->whereNull('reserved_at')->min('available_at'), null, report: false);
        $failed = (int) rescue(fn () => DB::table('failed_jobs')->count(), 0, report: false);

        if ($oldest !== null && now()->timestamp - (int) $oldest > 300) {
            $minutes = (int) floor((now()->timestamp - (int) $oldest) / 60);
            $this->check('Worker da fila', 'fail', "job esperando há {$minutes} min: o daemon queue:work não está rodando");
        } else {
            $this->check('Worker da fila', 'ok', 'nenhum job parado há mais de 5 min');
        }

        $this->check('Jobs com falha', $failed === 0 ? 'ok' : 'warn', $failed === 0 ? 'nenhum' : "{$failed} em failed_jobs (php artisan queue:failed)");
    }

    private function checkScheduler(): void
    {
        $heartbeat = Cache::get('med:scheduler:heartbeat');
        $at = $heartbeat ? rescue(fn () => Carbon::parse($heartbeat), null, report: false) : null;

        if (! $at) {
            $this->check('Agendador (cron schedule:run)', 'fail', 'nunca rodou: crie o cron no Forge (Scheduler, a cada minuto)');

            return;
        }

        $late = $at->diffInMinutes(now()) > 5;
        $this->check('Agendador (cron schedule:run)', $late ? 'fail' : 'ok', 'último batimento '.$at->timezone('America/Sao_Paulo')->format('d/m H:i').($late ? ' - parado' : ''));
    }

    private function checkWhatsApp(): void
    {
        if (blank(config('services.kapso.api_key')) || blank(config('services.kapso.phone_number_id'))) {
            $this->check('WhatsApp (Kapso)', 'warn', 'desligado: sem KAPSO_API_KEY/KAPSO_PHONE_NUMBER_ID, aviso só por e-mail');

            return;
        }

        $missing = array_keys(array_filter([
            'MED_WHATSAPP_TEMPLATE' => blank(config('med.whatsapp.template')),
            'KAPSO_WEBHOOK_SECRET' => blank(config('services.kapso.webhook_secret')),
        ]));

        $this->check(
            'WhatsApp (Kapso)',
            $missing === [] ? 'ok' : 'warn',
            $missing === [] ? 'template '.config('med.whatsapp.template').' (rode med:registrar-webhook-kapso uma vez)' : 'falta '.implode(' e ', $missing).': sem isso o link não vai na resposta',
        );
    }

    private function checkDossier(): void
    {
        if (! config('med.dossie.habilitado')) {
            $this->check('Dossiê automático', 'warn', 'desligado (MED_DOSSIE_HABILITADO): evidências só do seller e do analista');
        } else {
            $missing = array_keys(array_filter([
                'UNICOPAG_API_KEY' => blank(config('services.unicopag.key')),
                'UNICOPAG_SALE_ENDPOINT (rota ainda a criar no unico-api)' => blank(config('services.unicopag.sale_endpoint')),
            ]));

            $this->check('Dossiê automático', $missing === [] ? 'ok' : 'warn', $missing === []
                ? 'Woovi + unico-api + KYC'
                : 'ligado com a Woovi; falta '.implode(' e ', $missing).' para a venda na plataforma');
        }

        if (! config('med.envio_automatico.habilitado')) {
            $this->check('Envio automático', 'ok', 'desligado: todo envio passa pelo analista');

            return;
        }

        $optIn = (int) rescue(fn () => WooviAccount::query()->where('is_active', true)->where('auto_send', true)->count(), 0, report: false);

        $this->check(
            'Envio automático',
            config('med.dossie.habilitado') ? 'ok' : 'warn',
            config('med.dossie.habilitado')
                ? "ligado, {$optIn} conta(s) com opt-in, teto R$ ".number_format(config('med.envio_automatico.teto_centavos') / 100, 2, ',', '.')
                : 'ligado sem o dossiê: nunca vai enviar (precisa da conferência)',
        );
    }

    private function checkStorage(): void
    {
        $disk = (string) config('med.evidencias.disk');

        try {
            $probe = 'med/.diagnostico';
            Storage::disk($disk)->put($probe, 'ok');
            Storage::disk($disk)->delete($probe);
            $this->check('Disco das evidências', 'ok', "{$disk} gravável");
        } catch (Throwable $e) {
            $this->check('Disco das evidências', 'fail', "{$disk}: {$e->getMessage()}");
        }
    }

    private function checkPeople(): void
    {
        $superAdmins = (int) rescue(fn () => Role::findByName(Permissions::ROLE_SUPER_ADMIN)->users()->count(), 0, report: false);
        $this->check('Super Admin no painel', $superAdmins > 0 ? 'ok' : 'warn', $superAdmins > 0 ? "{$superAdmins}" : 'nenhum: rode php artisan make:filament-user');

        $accounts = (int) rescue(fn () => WooviAccount::query()->where('is_active', true)->count(), 0, report: false);
        $withoutWebhook = (int) rescue(fn () => WooviAccount::query()->where('is_active', true)->whereNull('webhooks_registered_at')->count(), 0, report: false);
        $this->check(
            'Contas Woovi ativas',
            $accounts > 0 && $withoutWebhook === 0 ? 'ok' : 'warn',
            $accounts === 0 ? 'nenhuma cadastrada' : "{$accounts}, {$withoutWebhook} sem webhook cadastrado (med:registrar-webhooks)",
        );
    }

    /**
     * @param  'ok'|'warn'|'fail'  $status
     */
    private function check(string $item, string $status, string $detail): void
    {
        $this->failed = $this->failed || $status === 'fail';
        $this->rows[] = [match ($status) {
            'ok' => '✓',
            'warn' => '!',
            default => '✗',
        }, $item, $detail];
    }
}
