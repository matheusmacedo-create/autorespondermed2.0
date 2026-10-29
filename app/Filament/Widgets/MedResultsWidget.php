<?php

namespace App\Filament\Widgets;

use App\Support\MedScoreboard;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * O placar das respostas (`MedScoreboard`): quanto foi respondido à Woovi, quanto ainda
 * espera decisão, quanto o banco recusou (ganho: o lojista manteve o valor) e quanto
 * aceitou (perdido: devolvido ao pagador). O decidido sem resposta nossa fica no
 * subtítulo, para comparar.
 */
class MedResultsWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '300s';

    protected ?string $heading = 'Resultado das respostas';

    protected function getStats(): array
    {
        $board = MedScoreboard::make();
        $since = $board->firstAnsweredAt()?->timezone('America/Sao_Paulo')->format('d/m');
        $rate = $board->winRate();

        return [
            Stat::make('Respondidos à Woovi', $board->answered->count())
                ->description(MedScoreboard::money($board->answered).($since ? " · desde {$since}" : ''))
                ->url('/admin/disputes?tab=enviados'),
            Stat::make('Aguardando decisão', $board->waiting()->count())
                ->description(MedScoreboard::money($board->waiting()).' bloqueados até a Woovi decidir')
                ->color($board->waiting()->isNotEmpty() ? 'warning' : 'gray')
                ->url('/admin/disputes?tab=enviados'),
            Stat::make('Ganhos', $board->won()->count())
                ->description(MedScoreboard::money($board->won()).' mantidos'.($rate !== null ? " · {$rate}% dos decididos" : ''))
                ->color('success')
                ->url('/admin/disputes?tab=encerrados'),
            Stat::make('Perdidos', $board->lost()->count())
                ->description(MedScoreboard::money($board->lost()).' devolvidos ao pagador')
                ->color($board->lost()->isNotEmpty() ? 'danger' : 'gray')
                ->url('/admin/disputes?tab=encerrados'),
        ];
    }

    protected function getDescription(): ?string
    {
        $board = MedScoreboard::make();
        $won = $board->won($board->unanswered)->count();
        $lost = $board->lost($board->unanswered)->count();

        return 'Para comparar: sem defesa nossa, a Woovi decidiu '.($won + $lost)." MED(s) da janela - {$won} ganho(s) e {$lost} perdido(s).";
    }
}
