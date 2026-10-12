<?php

use App\Http\Controllers\Api\WooviAccountApiController;
use App\Http\Controllers\Api\WooviWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook da Woovi, um por conta (a chave secreta na URL diz de qual conta é o
// evento). Fora da X-API-Key de propósito: quem chama é a Woovi, que autentica
// assinando o corpo (x-webhook-signature, conferido no controller).
Route::post('webhooks/woovi/{webhookKey}', WooviWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.woovi');

// Ponte com o unico-api. Mesma X-API-Key nas duas rotas (services.api.key).
Route::middleware('service-api-key:services.api.key')->group(function () {
    Route::put('woovi-accounts', [WooviAccountApiController::class, 'upsert']);
    Route::get('woovi-accounts/{accountId}/disputes', [WooviAccountApiController::class, 'disputes']);
});
