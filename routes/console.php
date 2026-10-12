<?php

use Illuminate\Support\Facades\Schedule;

// ATENÇÃO produção: o cron `php artisan schedule:run` a cada minuto precisa
// existir no servidor - sem ele estes agendamentos "existem e nunca rodam"
// (foi o que aconteceu no KYC, KB 8.4 de lá).

// Rede de segurança do webhook: MED novo ou decisão que o webhook perdeu.
Schedule::command('med:sincronizar')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/med-agendado.log'));

// Prazo vencendo: lembrete ao seller e lista no Discord.
Schedule::command('med:alertar-prazos')
    ->cron('0 9,14,18 * * *')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/med-agendado.log'));
