<?php

namespace App\Console\Commands;

use App\Actions\Med\BuildDossierAction;
use App\Models\Dispute;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Monta (ou remonta) o dossiê automático na hora, sem fila - para um caso ou para
 * todos os abertos. Útil depois de ligar a opção ou de configurar o unico-api.
 */
class BuildDossierCommand extends Command
{
    protected $signature = 'med:montar-dossie
        {--caso=* : ID do caso (disputes.id); repita para vários}
        {--abertos : Todos os casos abertos}
        {--refazer : Busca de novo também as fontes que já deram certo}';

    protected $description = 'Monta o dossiê automático (Woovi, unico-api, KYC) dos casos';

    public function handle(BuildDossierAction $build): int
    {
        $ids = array_filter((array) $this->option('caso'));

        if ($ids === [] && ! $this->option('abertos')) {
            $this->error('Informe --caso=ID ou --abertos.');

            return self::FAILURE;
        }

        $disputes = Dispute::query()
            ->with('wooviAccount')
            ->when($ids !== [], fn ($query) => $query->whereKey($ids))
            ->when($this->option('abertos'), fn ($query) => $query->open())
            ->get();

        $busy = 0;

        foreach ($disputes as $dispute) {
            try {
                $build->handle($dispute, (bool) $this->option('refazer'), waitSeconds: 10);
            } catch (RuntimeException $e) {
                // Caso ocupado (envio ou outra montagem): segue o lote.
                $busy++;
                $this->warn("Caso {$dispute->id}: {$e->getMessage()}");

                continue;
            }

            $match = (array) $dispute->fresh()->dossier_match;

            $this->line(sprintf(
                'Caso %d (%s): dossiê %s, conferência %s.',
                $dispute->id,
                $dispute->wooviAccount->name,
                $dispute->fresh()->dossier_status ?? '—',
                ($match['ready'] ?? false) ? 'pronta para envio' : 'com pendências',
            ));
        }

        $this->info(($disputes->count() - $busy)." caso(s) processado(s), {$busy} ocupado(s).");

        return $busy === 0 ? self::SUCCESS : self::FAILURE;
    }
}
