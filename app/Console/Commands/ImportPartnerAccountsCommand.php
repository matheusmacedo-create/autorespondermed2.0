<?php

namespace App\Console\Commands;

use App\Actions\Med\ImportPartnerAccountsAction;
use App\Actions\Med\RegisterWooviWebhooksAction;
use App\Actions\Med\SyncAccountDisputesAction;
use App\Models\WooviAccount;
use App\Services\DiscordNotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Subcontas novas na Woovi entram sozinhas: a conta mestre lista, o MED cria o
 * aplicativo em cada uma, cadastra os webhooks de disputa e busca os MEDs da
 * janela. Conta ativa ainda sem webhook ou nunca sincronizada (falha de uma
 * rodada anterior, ou cadastrada à mão) é religada na rodada seguinte. Agendado
 * de hora em hora quando WOOVI_MASTER_APP_ID está definido.
 */
class ImportPartnerAccountsCommand extends Command
{
    protected $signature = 'med:importar-contas
        {--dias=90 : Janela de MEDs buscada nas contas recém-ligadas}
        {--sem-webhooks : Não cadastrar os webhooks nas contas}
        {--sem-sincronizar : Não buscar os MEDs das contas}';

    protected $description = 'Importa as subcontas Woovi dos sellers pela conta mestre (API de parceiro) e já liga webhooks e MEDs';

    public function handle(ImportPartnerAccountsAction $import, RegisterWooviWebhooksAction $webhooks, SyncAccountDisputesAction $sync, DiscordNotificationService $discord): int
    {
        if (blank(config('services.woovi.master_app_id'))) {
            $this->error('WOOVI_MASTER_APP_ID vazio: sem a conta mestre o MED não lista as subcontas.');

            return self::FAILURE;
        }

        // Uma importação por vez, venha do cron ou de um operador: duas em paralelo
        // criariam duas credenciais na mesma subconta.
        $lock = Cache::lock('med:importar-contas', 3600);

        if (! $lock->get()) {
            $this->warn('Já há uma importação rodando; esta sai sem fazer nada.');

            return self::SUCCESS;
        }

        try {
            return $this->importAndWire($import, $webhooks, $sync, $discord);
        } finally {
            $lock->release();
        }
    }

    private function importAndWire(ImportPartnerAccountsAction $import, RegisterWooviWebhooksAction $webhooks, SyncAccountDisputesAction $sync, DiscordNotificationService $discord): int
    {
        try {
            $summary = $import->handle(function (string $level, string $message): void {
                match ($level) {
                    'error' => $this->error($message),
                    'warn' => $this->warn($message),
                    default => $this->line($message),
                };
            });
        } catch (Throwable $e) {
            $this->error('Importação abortada: '.$e->getMessage());
            $discord->operationAlert('Importação das subcontas Woovi parou', $e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Subcontas na Woovi: %d · criadas aqui: %d · já existiam: %d · sem empresa ainda: %d · falhas: %d',
            $summary['found'],
            $summary['created'],
            $summary['existing'],
            $summary['skipped'],
            $summary['failed'],
        ));

        if ($summary['aborted'] !== null) {
            $this->error('Criação de contas interrompida: '.$summary['aborted']);
            $discord->operationAlert('Importação das subcontas Woovi interrompida', $summary['aborted']);
        }

        $failures = $summary['failed'] + ($summary['aborted'] !== null ? 1 : 0);

        $pending = WooviAccount::query()
            ->where('is_active', true)
            ->where(fn ($query) => $query->whereKey($summary['created_ids'])
                ->orWhereNull('webhooks_registered_at')
                ->orWhereNull('last_synced_at'))
            ->get();

        foreach ($pending as $account) {
            if (! $this->option('sem-webhooks') && ($account->webhooks_registered_at === null || in_array($account->id, $summary['created_ids'], true))) {
                try {
                    $created = $webhooks->handle($account);
                    $this->line("{$account->name}: webhooks ".($created === [] ? 'já cadastrados' : implode(', ', $created)));
                } catch (Throwable $e) {
                    $failures++;
                    $this->error("{$account->name}: webhooks: {$e->getMessage()}");
                }
            }

            if (! $this->option('sem-sincronizar') && $account->fresh()?->last_synced_at === null) {
                try {
                    $result = $sync->handle($account, (int) $this->option('dias'));
                    $this->line("{$account->name}: {$result['seen']} MED(s) na janela, {$result['created']} novo(s).");
                } catch (Throwable $e) {
                    $failures++;
                    $this->error("{$account->name}: MEDs: {$e->getMessage()}");
                }
            }
        }

        if ($failures > 0 && $summary['aborted'] === null) {
            $discord->operationAlert('Importação das subcontas Woovi com falhas', "{$failures} falha(s) nesta rodada; detalhes em storage/logs/med-agendado.log. Contas sem webhook ou sem sincronização são retentadas na próxima hora.");
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
