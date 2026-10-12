<?php

namespace App\Filament\Resources\Disputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\Dispute;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * A lista é a fila de trabalho: a primeira aba é o que o analista tem de
 * decidir agora, ordenada pelo prazo mais curto.
 */
class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    public function getTabs(): array
    {
        $count = fn (DisputeStatus $status): int => Dispute::query()->where('status', $status->value)->count();

        return [
            'fila' => Tab::make('Defesa recebida')
                ->icon('heroicon-o-inbox-arrow-down')
                ->badge(fn (): int => $count(DisputeStatus::SELLER_SUBMITTED))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', DisputeStatus::SELLER_SUBMITTED->value)
                    ->reorder('respond_by', 'asc')),

            'aguardando_seller' => Tab::make('Aguardando seller')
                ->icon('heroicon-o-clock')
                ->badge(fn (): int => $count(DisputeStatus::PENDING_SELLER))
                ->badgeColor('gray')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->where('status', DisputeStatus::PENDING_SELLER->value)
                    ->reorder('respond_by', 'asc')),

            'enviados' => Tab::make('Enviados à Woovi')
                ->icon('heroicon-o-paper-airplane')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DisputeStatus::EVIDENCE_SENT->value)),

            'encerrados' => Tab::make('Encerrados')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', array_map(fn (DisputeStatus $status) => $status->value, DisputeStatus::closed()))),

            'todos' => Tab::make('Todos'),
        ];
    }
}
