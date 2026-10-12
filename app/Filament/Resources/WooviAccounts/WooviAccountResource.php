<?php

namespace App\Filament\Resources\WooviAccounts;

use App\Filament\Resources\WooviAccounts\Pages\CreateWooviAccount;
use App\Filament\Resources\WooviAccounts\Pages\EditWooviAccount;
use App\Filament\Resources\WooviAccounts\Pages\ListWooviAccounts;
use App\Filament\Resources\WooviAccounts\Schemas\WooviAccountForm;
use App\Filament\Resources\WooviAccounts\Tables\WooviAccountsTable;
use App\Models\WooviAccount;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class WooviAccountResource extends Resource
{
    protected static ?string $model = WooviAccount::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::BuildingStorefront;

    protected static ?string $navigationLabel = 'Contas Woovi';

    protected static ?string $modelLabel = 'conta Woovi';

    protected static ?string $pluralModelLabel = 'contas Woovi';

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return WooviAccountForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WooviAccountsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWooviAccounts::route('/'),
            'create' => CreateWooviAccount::route('/create'),
            'edit' => EditWooviAccount::route('/{record}/edit'),
        ];
    }
}
