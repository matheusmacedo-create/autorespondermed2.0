<?php

namespace App\Filament\Widgets;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * O resumo do dia: o que está na mão do analista, o que espera o seller, o que
 * vence e o resultado dos últimos 30 dias.
 */
class MedOverviewWidget extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    protected ?string $pollingInterval = '60s';

    protected function getStats(): array
    {
        $open = Dispute::query()->open();
        $alertHours = (int) config('med.fila.alerta_horas_restantes');

        $queue = (clone $open)->where('status', DisputeStatus::SELLER_SUBMITTED->value)->count();
        $waitingSeller = (clone $open)->where('status', DisputeStatus::PENDING_SELLER->value)->count();
        $dueSoon = (clone $open)
            ->whereIn('status', [DisputeStatus::PENDING_SELLER->value, DisputeStatus::SELLER_SUBMITTED->value])
            ->whereBetween('respond_by', [now(), now()->addHours($alertHours)])
            ->count();
        $overdue = (clone $open)
            ->whereIn('status', [DisputeStatus::PENDING_SELLER->value, DisputeStatus::SELLER_SUBMITTED->value])
            ->where('respond_by', '<', now())
            ->count();
        $openValue = (int) (clone $open)->sum('value');

        $closed = Dispute::query()->where('closed_at', '>=', now()->subDays(30));
        $won = (clone $closed)->where('status', DisputeStatus::REJECTED->value)->count();
        $lost = (clone $closed)->where('status', DisputeStatus::ACCEPTED->value)->count();

        return [
            Stat::make('Defesas para analisar', $queue)
                ->description('Seller já respondeu')
                ->color($queue > 0 ? 'warning' : 'gray')
                ->url('/admin/disputes?tab=fila'),
            Stat::make('Aguardando seller', $waitingSeller)
                ->description('Avisado, sem defesa ainda')
                ->color('gray')
                ->url('/admin/disputes?tab=aguardando_seller'),
            Stat::make("Vencem em {$alertHours}h", $dueSoon)
                ->description($overdue > 0 ? "{$overdue} já vencido(s) sem envio" : 'Nenhum vencido')
                ->color($overdue > 0 ? 'danger' : ($dueSoon > 0 ? 'warning' : 'success')),
            Stat::make('Em disputa', 'R$ '.number_format($openValue / 100, 2, ',', '.'))
                ->description('Soma dos casos abertos'),
            Stat::make('Resultado 30 dias', "{$won} ganhos · {$lost} perdidos")
                ->description('Rejeitada = seller manteve o valor')
                ->color($lost > $won ? 'danger' : 'success'),
        ];
    }
}
