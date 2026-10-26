<?php

namespace App\Filament\Resources\Disputes\Tables;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Support\TaxId;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DisputesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('wooviAccount')->withCount('evidences'))
            ->columns([
                TextColumn::make('merchant_name')
                    ->label('Lojista')
                    ->state(fn (Dispute $record): string => $record->merchantName() ?? 'Não identificado')
                    ->description(fn (Dispute $record): string => $record->merchant
                        ? (TaxId::format(data_get($record->merchant, 'tax_id')) ?? 'conta '.$record->merchant_account_id)
                        : 'Venda não localizada na plataforma')
                    ->color(fn (Dispute $record): ?string => $record->merchant ? null : 'danger')
                    ->weight(fn (Dispute $record): ?string => $record->merchant ? 'medium' : null)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where('merchant', 'like', '%'.$search.'%')),
                TextColumn::make('value')
                    ->label('Valor')
                    ->formatStateUsing(fn (Dispute $record): string => $record->formattedValue())
                    ->sortable(),
                TextColumn::make('type')
                    ->label('Tipo')
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('dispute_reason')
                    ->label('Motivo do pagador')
                    ->limit(40)
                    ->tooltip(fn (Dispute $record): ?string => $record->dispute_reason)
                    ->wrap()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (DisputeStatus $state): string => $state->label())
                    ->color(fn (DisputeStatus $state): string => $state->color())
                    ->sortable(),
                TextColumn::make('respond_by')
                    ->label('Prazo')
                    ->dateTime('d/m H:i', 'America/Sao_Paulo')
                    ->description(fn (Dispute $record): ?string => self::hoursLeftLabel($record))
                    ->color(fn (Dispute $record): string => $record->deadlineColor())
                    ->sortable(),
                TextColumn::make('dossier_status')
                    ->label('Dossiê')
                    ->badge()
                    ->state(fn (Dispute $record): ?string => match (true) {
                        $record->dossier_match === null => null,
                        (bool) data_get($record->dossier_match, 'ready') => 'Conferido',
                        data_get($record->dossier_match, 'divergent', []) !== [] => 'Divergente',
                        default => 'Parcial',
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'Conferido' => 'success',
                        'Divergente' => 'danger',
                        default => 'warning',
                    })
                    ->placeholder('—'),
                TextColumn::make('defense')
                    ->label('Defesa')
                    ->badge()
                    ->state(fn (Dispute $record): ?string => match (true) {
                        $record->ai_defense !== null => match (data_get($record->ai_defense, 'recommendation')) {
                            'defender' => 'Defender',
                            'defender_com_ressalvas' => 'Com ressalvas',
                            'nao_defender' => 'Não defender',
                            default => 'Pronta',
                        },
                        filled($record->defense_text) => 'Pronta',
                        default => null,
                    })
                    ->color(fn (?string $state): string => match ($state) {
                        'Defender', 'Pronta' => 'success',
                        'Com ressalvas' => 'warning',
                        'Não defender' => 'danger',
                        default => 'gray',
                    })
                    ->placeholder('Preparando'),
                TextColumn::make('evidences_count')
                    ->label('Arquivos')
                    ->badge()
                    ->color('gray')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('end_to_end_id')
                    ->label('endToEndId')
                    ->searchable()
                    ->copyable()
                    ->limit(14)
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('opened_at')
                    ->label('Aberto em')
                    ->dateTime('d/m/Y H:i', 'America/Sao_Paulo')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('wooviAccount.name')
                    ->label('Conta Woovi')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label('Status')->options(DisputeStatus::options()),
                SelectFilter::make('type')->label('Tipo')->options(['MED' => 'MED', 'CHARGEBACK' => 'Chargeback', 'DISPUTE' => 'Disputa']),
                SelectFilter::make('woovi_account_id')->label('Conta Woovi')->relationship('wooviAccount', 'name')->searchable()->preload(),
            ])
            ->defaultSort('respond_by', 'asc')
            ->recordActions([
                ViewAction::make(),
            ]);
    }

    private static function hoursLeftLabel(Dispute $record): ?string
    {
        if ($record->status->isClosed() || $record->status === DisputeStatus::EVIDENCE_SENT) {
            return null;
        }

        $hours = $record->hoursLeft();

        if ($hours === null) {
            return null;
        }

        if ($hours < 0) {
            return 'vencido há '.self::humanHours(abs($hours));
        }

        return 'faltam '.self::humanHours($hours);
    }

    private static function humanHours(float $hours): string
    {
        return $hours >= 48 ? round($hours / 24).' dias' : (int) floor($hours).'h';
    }
}
