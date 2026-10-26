<?php

namespace App\Filament\Resources\Disputes\Pages;

use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Models\Dispute;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

/**
 * A lista é a fila de trabalho, só com os MEDs da janela (`med.janela_dias`). A
 * primeira aba é tudo que ainda precisa de defesa, pelo prazo mais curto; as
 * seguintes separam o que trava a defesa (lojista não identificado) do que já
 * está pronto para revisar.
 */
class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    public function getSubheading(): ?string
    {
        $start = Dispute::windowStart();

        return $start
            ? 'MEDs abertos desde '.$start->timezone('America/Sao_Paulo')->format('d/m/Y').' (últimos '.config('med.janela_dias').' dias).'
            : null;
    }

    public function getTabs(): array
    {
        $toDefend = fn (Builder $query): Builder => $query->whereIn('status', [DisputeStatus::PENDING_SELLER->value, DisputeStatus::SELLER_SUBMITTED->value]);
        $count = fn (callable $scope): int => $scope(Dispute::query()->inWindow())->count();

        $withoutMerchant = fn (Builder $query): Builder => $toDefend($query)->whereNull('merchant_account_id');
        $defenseReady = fn (Builder $query): Builder => $toDefend($query)->whereNotNull('defense_text');
        $sellerAnswered = fn (Builder $query): Builder => $query->where('status', DisputeStatus::SELLER_SUBMITTED->value);

        return [
            'abertos' => Tab::make('Para defender')
                ->icon('heroicon-o-shield-exclamation')
                ->badge(fn (): int => $count($toDefend))
                ->badgeColor('warning')
                ->modifyQueryUsing(fn (Builder $query): Builder => $toDefend($query)->reorder('respond_by', 'asc')),

            'sem_lojista' => Tab::make('Sem lojista')
                ->icon('heroicon-o-question-mark-circle')
                ->badge(fn (): int => $count($withoutMerchant))
                ->badgeColor('danger')
                ->modifyQueryUsing(fn (Builder $query): Builder => $withoutMerchant($query)->reorder('respond_by', 'asc')),

            'defesa_pronta' => Tab::make('Defesa pronta')
                ->icon('heroicon-o-document-check')
                ->badge(fn (): int => $count($defenseReady))
                ->badgeColor('success')
                ->modifyQueryUsing(fn (Builder $query): Builder => $defenseReady($query)->reorder('respond_by', 'asc')),

            'lojista_respondeu' => Tab::make('Lojista respondeu')
                ->icon('heroicon-o-inbox-arrow-down')
                ->badge(fn (): ?int => $count($sellerAnswered) ?: null)
                ->badgeColor('info')
                ->modifyQueryUsing(fn (Builder $query): Builder => $sellerAnswered($query)->reorder('respond_by', 'asc')),

            'enviados' => Tab::make('Enviados à Woovi')
                ->icon('heroicon-o-paper-airplane')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query->where('status', DisputeStatus::EVIDENCE_SENT->value)),

            'encerrados' => Tab::make('Encerrados')
                ->icon('heroicon-o-check-circle')
                ->modifyQueryUsing(fn (Builder $query): Builder => $query
                    ->whereIn('status', array_map(fn (DisputeStatus $status) => $status->value, DisputeStatus::closed()))
                    ->reorder('closed_at', 'desc')),

            'todos' => Tab::make('Todos'),
        ];
    }
}
