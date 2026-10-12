<?php

namespace App\Filament\Resources\WooviAccounts\Pages;

use App\Enums\WooviAccountSource;
use App\Filament\Resources\WooviAccounts\WooviAccountResource;
use Filament\Resources\Pages\CreateRecord;

class CreateWooviAccount extends CreateRecord
{
    protected static string $resource = WooviAccountResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['source'] = WooviAccountSource::MANUAL;

        return $data;
    }
}
