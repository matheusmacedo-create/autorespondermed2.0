<?php

namespace App\Jobs;

use App\Actions\Med\BuildDossierAction;
use App\Models\Dispute;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Monta o dossiê automático de um caso fora da requisição (são várias chamadas à
 * Woovi e ao unico-api). Único por caso: o webhook repetido e a sincronização
 * não montam o mesmo dossiê duas vezes ao mesmo tempo.
 */
class BuildDisputeDossier implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * @var array<int, int>
     */
    public array $backoff = [60, 300];

    public int $uniqueFor = 600;

    public function __construct(public int $disputeId, public bool $refresh = false) {}

    public function uniqueId(): string
    {
        return (string) $this->disputeId;
    }

    public function handle(BuildDossierAction $build): void
    {
        $dispute = Dispute::query()->find($this->disputeId);

        if (! $dispute || $dispute->status->isClosed()) {
            return;
        }

        $dispute = $build->handle($dispute, $this->refresh);

        // A montagem não lança por fonte que falha; sem a transação (o que mais
        // importa), volta para a fila com espera, até as tentativas acabarem. Depois
        // disso a sincronização de hora em hora pega o caso.
        if ($dispute->dossier_status === 'failed' && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 300);
        }
    }
}
