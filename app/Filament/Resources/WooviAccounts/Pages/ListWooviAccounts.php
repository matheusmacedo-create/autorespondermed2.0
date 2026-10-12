<?php

namespace App\Filament\Resources\WooviAccounts\Pages;

use App\Filament\Resources\WooviAccounts\WooviAccountResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListWooviAccounts extends ListRecords
{
    protected static string $resource = WooviAccountResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
