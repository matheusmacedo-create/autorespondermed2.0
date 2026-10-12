<?php

namespace App\Jobs;

use App\Actions\Med\AutoSendDisputeAction;
use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\MatchDossierAction;
use App\Models\Dispute;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Depois da defesa do seller: completa o dossiê (só as fontes que faltaram ou
 * falharam), refaz a conferência com o que o seller mandou e decide o envio
 * automático.
 */
class ProcessSellerDefense implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /**
     * @var array<int, int>
     */
    public array $backoff = [120];

    public function __construct(public int $disputeId) {}

    public function handle(BuildDossierAction $build, MatchDossierAction $match, AutoSendDisputeAction $autoSend): void
    {
        $dispute = Dispute::query()->with('wooviAccount')->find($this->disputeId);

        if (! $dispute || $dispute->status->isClosed()) {
            return;
        }

        if (config('med.dossie.habilitado')) {
            // Sem refresh: o que já deu certo fica; o que falhou ou ainda não foi
            // buscado é buscado agora. A conferência roda no fim do build.
            $build->handle($dispute);
        } elseif ($dispute->dossier_built_at !== null) {
            $match->handle($dispute);
        }

        $autoSend->handle($dispute->fresh());
    }
}
