<?php

namespace App\Filament\Resources\MerchantStores\Pages;

use App\Filament\Resources\MerchantStores\MerchantStoreResource;
use App\Jobs\CaptureMerchantStore;
use App\Services\Stores\StoreCapturer;
use Filament\Resources\Pages\EditRecord;

class EditMerchantStore extends EditRecord
{
    protected static string $resource = MerchantStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [MerchantStoreResource::captureAction()];
    }

    /**
     * URL mexida pelo analista passa a ser "manual" e vale uma captura nova.
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        $record = $this->getRecord();

        if (($data['website'] ?? null) !== $record->website) {
            $data['website_source'] = 'manual';
        }

        return $data;
    }

    protected function afterSave(): void
    {
        $record = $this->getRecord();

        if ($record->wasChanged(['website', 'shipping_url', 'terms_url', 'exchange_url']) && app(StoreCapturer::class)->isAvailable()) {
            CaptureMerchantStore::dispatch($record->id);
        }
    }
}
