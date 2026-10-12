<?php

namespace App\Filament\Resources\WooviAccounts\Pages;

use App\Filament\Resources\WooviAccounts\WooviAccountResource;
use Filament\Resources\Pages\EditRecord;

class EditWooviAccount extends EditRecord
{
    protected static string $resource = WooviAccountResource::class;

    /**
     * AppID em branco na edição = manter o atual (o campo nunca mostra a chave).
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        if (blank($data['app_id'] ?? null)) {
            unset($data['app_id']);
        }

        return $data;
    }
}
