<?php

namespace App\Console\Commands;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\WooviAccount;
use App\Support\MedScoreboard;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * O placar do painel em texto (`MedScoreboard`): o que foi respondido e como a Woovi
 * decidiu, o que ficou sem resposta e as decisões recentes. É o que o acompanhamento
 * diário lê.
 */
class ScoreboardCommand extends Command
{
    protected $signature = 'med:placar {--horas=24 : Decisões da Woovi nas últimas N horas}';

    protected $description = 'Placar dos MEDs: respondidos, ganhos, perdidos e o que está em aberto';

    public function handle(): int
    {
        $tz = 'America/Sao_Paulo';
        $board = MedScoreboard::make();
        $line = fn (string $label, Collection $disputes) => $this->line(sprintf('  %s %4d · %s', $label.str_repeat(' ', max(0, 22 - mb_strlen($label))), $disputes->count(), MedScoreboard::money($disputes)));
        $synced = WooviAccount::query()->where('is_active', true)->whereNotNull('last_synced_at')->latest('last_synced_at')->first()?->last_synced_at;

        $this->info('Placar dos MEDs - '.now()->timezone($tz)->format('d/m H:i').($synced ? ' (Woovi lida às '.$synced->timezone($tz)->format('H:i').')' : ''));

        $since = $board->firstAnsweredAt()?->timezone($tz)->format('d/m');
        $this->line('Respondidos à Woovi'.($since ? " desde {$since}" : '').": {$board->answered->count()} · ".MedScoreboard::money($board->answered));
        $line('aguardando decisão', $board->waiting());
        $line('ganhos', $board->won());
        $line('perdidos', $board->lost());
        $line('cancelados', $board->canceled());
        $rate = $board->winRate();
        $this->line('  taxa de ganho: '.($rate !== null ? "{$rate}% dos decididos" : 'nenhum decidido ainda'));

        $start = Dispute::windowStart();
        $this->line('Sem resposta nossa'.($start ? ' (MEDs abertos desde '.$start->timezone($tz)->format('d/m').')' : '').':');
        $line('no prazo da Woovi', $board->unansweredOpen(withinDeadline: true));
        $line('prazo vencido', $board->unansweredOpen(withinDeadline: false));
        $line('decididos: ganhos', $board->won($board->unanswered));
        $line('decididos: perdidos', $board->lost($board->unanswered));

        $hours = (int) $this->option('horas');
        $recent = Dispute::query()
            ->whereNotNull('evidence_sent_at')
            ->whereIn('status', [DisputeStatus::REJECTED->value, DisputeStatus::ACCEPTED->value, DisputeStatus::CANCELED->value])
            ->where('closed_at', '>=', now()->subHours($hours))
            ->orderBy('closed_at')
            ->get();

        $this->line("Decisões nas respondidas, últimas {$hours}h: ".($recent->isEmpty() ? 'nenhuma' : $recent->count()));

        foreach ($recent as $dispute) {
            $this->line(sprintf('  #%d %s %s - %s (%s)', $dispute->id, $dispute->formattedValue(), $dispute->merchantName() ?? 'sem lojista', $dispute->status->label(), $dispute->closed_at->timezone($tz)->format('d/m H:i')));
        }

        return self::SUCCESS;
    }
}
