<?php

use App\Http\Controllers\Api\KapsoWebhookController;
use App\Http\Controllers\Api\RiskOpsDisputesController;
use App\Http\Controllers\Api\WooviAccountApiController;
use App\Http\Controllers\Api\WooviWebhookController;
use Illuminate\Support\Facades\Route;

// Webhook da Woovi, um por conta (a chave secreta na URL diz de qual conta é o
// evento). Fora da X-API-Key de propósito: quem chama é a Woovi, que autentica
// assinando o corpo (x-webhook-signature, conferido no controller).
Route::post('webhooks/woovi/{webhookKey}', WooviWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.woovi');

// Webhook da Kapso (WhatsApp oficial): entrega das mensagens e respostas do seller,
// que liberam o link da defesa. Autentica pelo HMAC do corpo (X-Webhook-Signature).
Route::post('webhooks/kapso', KapsoWebhookController::class)
    ->middleware('throttle:600,1')
    ->name('webhooks.kapso');

// Ponte com o unico-api. Mesma X-API-Key nas duas rotas (services.api.key).
Route::middleware('service-api-key:services.api.key')->group(function () {
    Route::put('woovi-accounts', [WooviAccountApiController::class, 'upsert']);
    Route::get('woovi-accounts/{accountId}/disputes', [WooviAccountApiController::class, 'disputes']);
});

// RiskOps do KYC: MEDs e chargebacks por conta, só leitura e sem dado do pagador.
// Chave própria (services.riskops.key), separada da do unico-api.
Route::middleware(['service-api-key:services.riskops.key', 'throttle:60,1'])->group(function () {
    Route::get('riskops/disputas', [RiskOpsDisputesController::class, 'index']);
    Route::get('riskops/disputas/{conta}', [RiskOpsDisputesController::class, 'show']);
});
