<?php

namespace App\Jobs;

use App\Actions\Med\AutoSendDisputeAction;
use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\MatchDossierAction;
use App\Enums\DossierSource;
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
            // O que já deu certo fica, o que falhou ou ficou pendente é buscado agora -
            // e as fontes que alimentam as travas do envio automático são relidas
            // sempre: devolução feita ou KYC bloqueado depois da chegada do MED não
            // podem passar pelo retrato antigo.
            $build->handle($dispute, refreshSources: [
                DossierSource::WOOVI_TRANSACTION,
                DossierSource::WOOVI_REFUNDS,
                DossierSource::KYC_STATUS,
            ]);
        } elseif ($dispute->dossier_built_at !== null) {
            $match->handle($dispute);
        }

        $autoSend->handle($dispute->fresh());
    }
}
