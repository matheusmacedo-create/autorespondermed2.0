<?php

use App\Http\Controllers\EvidenceDownloadController;
use App\Livewire\Med\SellerDefenseForm;
use App\Support\Permissions;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Formulário público de defesa do seller, autenticado pelo próprio token (sem login).
Route::get('/med/{token}', SellerDefenseForm::class)
    ->middleware('throttle:60,1')
    ->name('med.seller');

// Arquivo de evidência por link temporário assinado: é daqui que a Woovi baixa
// no modo `url` (services.woovi.evidence_mode).
Route::get('/med/evidencias/{evidence}/arquivo', EvidenceDownloadController::class)
    ->middleware(['signed', 'throttle:120,1'])
    ->name('med.evidence.download');

// Mesmo arquivo para o analista, pela sessão do painel.
Route::get('/admin/evidencias/{evidence}/arquivo', EvidenceDownloadController::class)
    ->middleware(['web', 'auth', 'can:'.Permissions::VIEW_DISPUTES])
    ->name('admin.evidence.download');
