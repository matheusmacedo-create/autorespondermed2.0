<?php

namespace App\Console\Commands;

use App\Actions\Med\ImportPartnerAccountsAction;
use App\Actions\Med\RegisterWooviWebhooksAction;
use App\Actions\Med\SyncAccountDisputesAction;
use App\Models\WooviAccount;
use Illuminate\Console\Command;
use Throwable;

/**
 * Subcontas novas na Woovi entram sozinhas: a conta mestre lista, o MED cria o
 * aplicativo em cada uma, cadastra os webhooks de disputa e busca os MEDs da
 * janela. Agendado de hora em hora quando WOOVI_MASTER_APP_ID está definido.
 */
class ImportPartnerAccountsCommand extends Command
{
    protected $signature = 'med:importar-contas
        {--dias=90 : Janela de MEDs buscada nas contas recém-importadas}
        {--sem-webhooks : Não cadastrar os webhooks nas contas novas}
        {--sem-sincronizar : Não buscar os MEDs das contas novas}';

    protected $description = 'Importa as subcontas Woovi dos sellers pela conta mestre (API de parceiro) e já liga webhooks e MEDs';

    public function handle(ImportPartnerAccountsAction $import, RegisterWooviWebhooksAction $webhooks, SyncAccountDisputesAction $sync): int
    {
        if (blank(config('services.woovi.master_app_id'))) {
            $this->error('WOOVI_MASTER_APP_ID vazio: sem a conta mestre o MED não lista as subcontas.');

            return self::FAILURE;
        }

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

        $failures = $summary['failed'];

        foreach (WooviAccount::query()->whereKey($summary['created_ids'])->get() as $account) {
            if (! $this->option('sem-webhooks')) {
                try {
                    $created = $webhooks->handle($account);
                    $this->line("{$account->name}: webhooks ".($created === [] ? 'já cadastrados' : implode(', ', $created)));
                } catch (Throwable $e) {
                    $failures++;
                    $this->error("{$account->name}: webhooks: {$e->getMessage()}");
                }
            }

            if (! $this->option('sem-sincronizar')) {
                try {
                    $result = $sync->handle($account, (int) $this->option('dias'));
                    $this->line("{$account->name}: {$result['seen']} MED(s) na janela, {$result['created']} novo(s).");
                } catch (Throwable $e) {
                    $failures++;
                    $this->error("{$account->name}: MEDs: {$e->getMessage()}");
                }
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
