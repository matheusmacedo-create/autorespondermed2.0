<?php

namespace App\Console\Commands;

use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\WooviAccount;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deixa na base só o que é da operação: apaga os MEDs abertos antes da janela de
 * trabalho (`med.janela_dias`) e, com --conta, contas inteiras (as de
 * demonstração). Sem --confirmar só mostra o que apagaria. Caso que já foi à Woovi
 * nunca sai: a defesa enviada é registro.
 */
class PruneDisputesCommand extends Command
{
    protected $signature = 'med:podar
        {--conta=* : ID de conta Woovi a apagar inteira, com os casos (ex.: as de demonstração)}
        {--confirmar : Apaga de verdade; sem ela, só lista}';

    protected $description = 'Apaga os MEDs fora da janela de trabalho e contas de demonstração';

    public function handle(): int
    {
        $start = Dispute::windowStart();
        $accountIds = array_map('intval', (array) $this->option('conta'));

        $query = Dispute::query()
            ->whereNull('evidence_sent_at')
            ->where(function (Builder $query) use ($start, $accountIds): void {
                $query->whereRaw('1 = 0');

                if ($start !== null) {
                    $query->orWhere('opened_at', '<', $start)->orWhereNull('opened_at');
                }

                if ($accountIds !== []) {
                    $query->orWhereIn('woovi_account_id', $accountIds);
                }
            });

        $count = (clone $query)->count();
        $accounts = WooviAccount::query()->whereKey($accountIds)->get();

        $this->line(sprintf(
            'Janela: %s · casos a apagar: %d · contas a apagar: %s',
            $start ? 'desde '.$start->timezone('America/Sao_Paulo')->format('d/m/Y') : 'sem janela',
            $count,
            $accounts->pluck('name')->implode(', ') ?: 'nenhuma',
        ));

        if (! $this->option('confirmar')) {
            $this->comment('Nada apagado. Rode com --confirmar para apagar.');

            return self::SUCCESS;
        }

        $deleted = 0;

        $query->with('evidences')->chunkById(100, function ($disputes) use (&$deleted): void {
            foreach ($disputes as $dispute) {
                DB::transaction(function () use ($dispute): void {
                    DisputeEvent::query()->where('dispute_id', $dispute->id)->delete();
                    $dispute->delete();
                });

                // Arquivo depois do banco: falhar aqui deixa lixo no disco, nunca
                // evidência apontando para arquivo que sumiu.
                foreach ($dispute->evidences as $evidence) {
                    rescue(fn () => Storage::disk($evidence->disk)->delete($evidence->path), report: false);
                }

                $deleted++;
            }
        });

        foreach ($accounts as $account) {
            if ($account->disputes()->exists()) {
                $this->warn("{$account->name}: tem caso já enviado à Woovi, conta mantida.");

                continue;
            }

            DisputeEvent::query()->where('woovi_account_id', $account->id)->delete();
            $account->delete();
        }

        $this->info("Apagados: {$deleted} caso(s).");

        return self::SUCCESS;
    }
}
