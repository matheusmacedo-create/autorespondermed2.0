<?php

namespace App\Console\Commands;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Services\DiscordNotificationService;
use App\Services\DisputeEventRecorder;
use App\Services\SellerNotifier;
use Illuminate\Console\Command;

/**
 * Prazo vencendo: lembra o seller que ainda não respondeu (uma vez por caso) e
 * manda ao Discord a lista do que vence dentro do alerta e ainda não foi enviado
 * à Woovi.
 */
class DeadlineWarningCommand extends Command
{
    protected $signature = 'med:alertar-prazos';

    protected $description = 'Lembra o seller e alerta a operação dos MEDs com prazo vencendo';

    public function handle(SellerNotifier $notifier, DiscordNotificationService $discord, DisputeEventRecorder $events): int
    {
        $limit = now()->addHours((int) config('med.fila.alerta_horas_restantes'));

        $dueSoon = Dispute::query()
            ->whereIn('status', [DisputeStatus::PENDING_SELLER->value, DisputeStatus::SELLER_SUBMITTED->value])
            ->inWindow()
            ->whereNotNull('respond_by')
            ->where('respond_by', '<=', $limit)
            ->with('wooviAccount')
            ->orderBy('respond_by')
            ->get();

        $reminded = 0;

        foreach ($dueSoon as $dispute) {
            $alreadyWarned = $dispute->events()->where('event', DisputeEventType::DEADLINE_WARNING->value)->exists();

            if (! SellerNotifier::enabled() || $alreadyWarned || $dispute->status !== DisputeStatus::PENDING_SELLER || $dispute->respond_by->isPast()) {
                continue;
            }

            $events->record(DisputeEventType::DEADLINE_WARNING, $dispute, 'Lembrete de prazo enviado ao seller.');
            $notifier->disputeOpened($dispute, $dispute->complement_request);
            $reminded++;
        }

        $discord->deadlineDigest($dueSoon);

        $this->info("Vencendo: {$dueSoon->count()} · sellers lembrados: {$reminded}");

        return self::SUCCESS;
    }
}
