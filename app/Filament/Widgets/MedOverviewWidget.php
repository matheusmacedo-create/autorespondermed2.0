<?php

namespace App\Filament\Widgets;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * O resumo da janela de trabalho (`med.janela_dias`): quantos MEDs ainda dá tempo
 * de defender (o vencido fica fora), de quantos já se sabe o lojista, quantas
 * defesas estão prontas, o que vence primeiro e o resultado dos encerrados.
 */
class MedOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $window = fn () => Dispute::query()->inWindow();
        $toDefend = fn () => $window()->toDefend();
        $alertHours = (int) config('med.fila.alerta_horas_restantes');
        $days = (int) config('med.janela_dias');
        $period = $days > 0 ? "últimos {$days} dias" : 'todos';

        $open = $toDefend()->count();
        $openValue = (int) $toDefend()->sum('value');
        $withMerchant = $toDefend()->whereNotNull('merchant_account_id')->count();
        $ready = $toDefend()->whereNotNull('defense_text')->count();
        $dueSoon = $toDefend()->whereBetween('respond_by', [now(), now()->addHours($alertHours)])->count();
        $overdue = $window()->toDefend(overdue: true)->count();
        $sent = $window()->where('status', DisputeStatus::EVIDENCE_SENT->value)->count();
        $won = $window()->where('status', DisputeStatus::REJECTED->value)->count();
        $lost = $window()->where('status', DisputeStatus::ACCEPTED->value)->count();

        return [
            Stat::make('MEDs para defender', $open)
                ->description('R$ '.number_format($openValue / 100, 2, ',', '.')." em disputa, prazo correndo · {$period}")
                ->color($open > 0 ? 'warning' : 'success')
                ->url('/admin/disputes?tab=abertos'),
            Stat::make('Lojista identificado', "{$withMerchant} de {$open}")
                ->description($open - $withMerchant > 0 ? ($open - $withMerchant).' sem venda localizada' : 'Todos identificados pela venda')
                ->color($open - $withMerchant > 0 ? 'danger' : 'success')
                ->url('/admin/disputes?tab=sem_lojista'),
            Stat::make('Defesa pronta', "{$ready} de {$open}")
                ->description('Rascunho para revisar e enviar')
                ->color($ready < $open ? 'warning' : 'success')
                ->url('/admin/disputes?tab=defesa_pronta'),
            Stat::make("Vencem em {$alertHours}h", $dueSoon)
                ->description($overdue > 0 ? "{$overdue} já vencido(s), fora da fila" : 'Defenda primeiro estes')
                ->color($dueSoon > 0 ? 'danger' : 'success')
                ->url('/admin/disputes?tab=abertos'),
            Stat::make('Enviados à Woovi', $sent)
                ->description('Aguardando decisão')
                ->url('/admin/disputes?tab=enviados'),
            Stat::make('Encerrados', "{$won} ganhos · {$lost} perdidos")
                ->description('Rejeitada = lojista manteve o valor')
                ->color($lost > $won ? 'danger' : 'success')
                ->url('/admin/disputes?tab=encerrados'),
        ];
    }
}
