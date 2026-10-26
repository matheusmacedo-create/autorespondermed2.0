<?php

namespace App\Jobs;

use App\Actions\Med\MerchantStoreAction;
use App\Models\MerchantStore;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Captura as políticas do site de uma loja (o navegador leva até alguns minutos) e
 * espalha o resultado pelos MEDs abertos dela.
 */
class CaptureMerchantStore implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(public readonly int $storeId) {}

    public function uniqueId(): string
    {
        return (string) $this->storeId;
    }

    public function handle(MerchantStoreAction $stores): void
    {
        $store = MerchantStore::query()->find($this->storeId);

        if (! $store) {
            return;
        }

        $stores->spread($stores->capture($store));
    }
}
