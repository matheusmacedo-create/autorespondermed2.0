<?php

namespace App\Filament\Resources\Disputes;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Disputes\Schemas\DisputeInfolist;
use App\Filament\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use App\Support\Permissions;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::ShieldExclamation;

    protected static ?string $navigationLabel = 'MEDs';

    protected static ?string $modelLabel = 'MED';

    protected static ?string $pluralModelLabel = 'MEDs';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'end_to_end_id';

    /**
     * Só a janela de trabalho (`med.janela_dias`): caso mais antigo não aparece em
     * lugar nenhum do painel.
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->inWindow();
    }

    public static function getNavigationBadge(): ?string
    {
        $count = Dispute::query()->inWindow()->toDefend()->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return 'MEDs com prazo correndo, esperando defesa';
    }

    public static function infolist(Schema $schema): Schema
    {
        return DisputeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DisputesTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['end_to_end_id', 'payer_name', 'wooviAccount.name', 'wooviAccount.tax_id'];
    }

    public static function canViewAny(): bool
    {
        return Permissions::allows(Permissions::VIEW_DISPUTES);
    }

    public static function canView($record): bool
    {
        return Permissions::allows(Permissions::VIEW_DISPUTES);
    }

    public static function canCreate(): bool
    {
        // Caso nasce da Woovi (webhook ou sincronização), nunca à mão.
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
            'view' => ViewDispute::route('/{record}'),
        ];
    }
}
