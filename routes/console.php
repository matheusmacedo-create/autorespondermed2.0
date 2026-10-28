<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

// ATENÇÃO produção: o cron `php artisan schedule:run` a cada minuto precisa
// existir no servidor - sem ele estes agendamentos "existem e nunca rodam"
// (foi o que aconteceu no KYC, KB 8.4 de lá).

// Subconta nova na Woovi entra sozinha (conta mestre): aplicativo, webhooks e
// MEDs. Antes do sincronizar, para a conta nova já entrar na mesma hora.
Schedule::command('med:importar-contas')
    ->hourly()
    ->when(fn (): bool => filled(config('services.woovi.master_app_id')) && config('services.woovi.partner_import'))
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/med-agendado.log'));

// Rede de segurança do webhook: MED novo ou decisão que o webhook perdeu.
Schedule::command('med:sincronizar')
    ->hourly()
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/med-agendado.log'));

// Resposta automática (MED_RESPOSTA_AUTOMATICA): 20 min depois da sincronização, com o
// dossiê e o rascunho da IA dos MEDs novos já prontos, responde sozinho o que passa
// nas travas do med:responder - sem ninguém clicar.
Schedule::command('med:responder --automatico --confirmar')
    ->hourlyAt(20)
    ->when(fn (): bool => (bool) config('med.resposta_automatica.habilitada'))
    ->withoutOverlapping(50)
    ->appendOutputTo(storage_path('logs/med-agendado.log'));

// Prazo vencendo: lembrete ao seller e lista no Discord.
Schedule::command('med:alertar-prazos')
    ->cron('0 9,14,18 * * *')
    ->timezone('America/Sao_Paulo')
    ->withoutOverlapping()
    ->appendOutputTo(storage_path('logs/med-agendado.log'));

// Batimento do agendador: prova que o cron `schedule:run` existe e roda. O
// `med:diagnostico` lê esta marca - sem ela, as rotinas acima "existem e nunca
// rodam" e ninguém percebe (lição do KYC).
Schedule::call(fn () => Cache::forever('med:scheduler:heartbeat', now()->toIso8601String()))
    ->everyMinute()
    ->name('med:scheduler-heartbeat');
