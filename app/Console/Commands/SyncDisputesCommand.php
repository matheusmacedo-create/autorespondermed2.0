<?php

namespace App\Console\Commands;

use App\Actions\Med\SyncAccountDisputesAction;
use App\Actions\Med\SyncDisputeAction;
use App\Enums\SourceStatus;
use App\Jobs\BuildDisputeDossier;
use App\Models\Dispute;
use App\Models\WooviAccount;
use Illuminate\Console\Command;
use Throwable;

/**
 * Rede de segurança do webhook: varre as disputas de cada conta ativa na Woovi
 * (pega MED que o webhook perdeu) e relê os casos abertos (pega decisão que o
 * webhook perdeu). Uma conta com erro não para as outras.
 */
class SyncDisputesCommand extends Command
{
    protected $signature = 'med:sincronizar
        {--conta= : ID da conta Woovi (woovi_accounts.id); sem ela, todas as ativas}
        {--dias=30 : Janela da varredura de disputas novas}';

    protected $description = 'Busca MEDs novos e atualiza os abertos direto na Woovi';

    public function handle(SyncAccountDisputesAction $syncAccount, SyncDisputeAction $syncDispute): int
    {
        $accounts = WooviAccount::query()
            ->where('is_active', true)
            ->when($this->option('conta'), fn ($query, $id) => $query->whereKey($id))
            ->get();

        $failures = 0;

        foreach ($accounts as $account) {
            try {
                $result = $syncAccount->handle($account, (int) $this->option('dias'));
                $this->line("{$account->name}: {$result['seen']} disputa(s), {$result['created']} nova(s).");
            } catch (Throwable $e) {
                $failures++;
                $this->error("{$account->name}: {$e->getMessage()}");
            }
        }

        // Casos ainda sem decisão: relê um a um (a listagem pode não trazer o
        // status final de caso antigo, fora da janela).
        $open = Dispute::query()
            ->open()
            ->whereIn('woovi_account_id', $accounts->pluck('id'))
            ->with('wooviAccount')
            ->get();

        foreach ($open as $dispute) {
            try {
                $syncDispute->handle($dispute);
            } catch (Throwable $e) {
                $failures++;
                $this->error("Caso {$dispute->id}: {$e->getMessage()}");
            }
        }

        // Rede de segurança do dossiê: caso aberto sem dossiê (criado antes da opção
        // ser ligada, ou cujo job se perdeu) ou com dossiê incompleto (fonte que falhou
        // ou ficou esperando outra) entra na fila - só o que falta é buscado de novo.
        $withoutDossier = 0;

        if (config('med.dossie.habilitado')) {
            $open->filter(fn (Dispute $dispute): bool => $dispute->dossier_built_at === null
                    || in_array($dispute->dossier_status, ['failed', 'partial'], true)
                    || $dispute->sources()->where('status', SourceStatus::FAILED->value)->exists())
                ->filter(fn (Dispute $dispute): bool => ! $dispute->fresh()?->status->isClosed())
                ->each(function (Dispute $dispute) use (&$withoutDossier): void {
                    BuildDisputeDossier::dispatch($dispute->id);
                    $withoutDossier++;
                });
        }

        $this->info("Contas: {$accounts->count()} · casos abertos relidos: {$open->count()} · dossiês pedidos: {$withoutDossier} · falhas: {$failures}");

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
