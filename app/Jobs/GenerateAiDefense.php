<?php

namespace App\Jobs;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Models\Dispute;
use App\Models\User;
use App\Services\Defense\DefenseWriterException;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * A redação leva de alguns segundos a alguns minutos: fora da requisição do
 * painel. O analista que pediu recebe o aviso no sininho do painel.
 */
class GenerateAiDefense implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $disputeId, public readonly ?int $userId = null) {}

    public function uniqueId(): string
    {
        return (string) $this->disputeId;
    }

    public function handle(GenerateAiDefenseAction $generate): void
    {
        $dispute = Dispute::query()->find($this->disputeId);
        $user = $this->userId ? User::query()->find($this->userId) : null;

        if (! $dispute) {
            return;
        }

        try {
            $dispute = $generate->handle($dispute, $this->userId);
        } catch (DefenseWriterException $e) {
            if ($user) {
                Notification::make()->danger()->title("Defesa com IA não gerada (caso #{$dispute->id})")->body($e->getMessage())->sendToDatabase($user);
            }

            return;
        }

        if ($user) {
            Notification::make()
                ->success()
                ->title("Rascunho da defesa pronto (caso #{$dispute->id})")
                ->body('Recomendação: '.GenerateAiDefenseAction::recommendationLabel(data_get($dispute->ai_defense, 'recommendation')).'. Revise antes de enviar.')
                ->sendToDatabase($user);
        }
    }
}
