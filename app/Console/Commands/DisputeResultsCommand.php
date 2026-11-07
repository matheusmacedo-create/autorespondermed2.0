<?php

namespace App\Console\Commands;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Enums\DisputeEventType;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use Illuminate\Console\Command;

/**
 * Resultado das respostas na Woovi: das defesas enviadas, quantas o banco recusou
 * (REJECTED = lojista manteve o valor) e quantas aceitou (ACCEPTED = devolveu), por
 * recomendação da IA no envio e por loja. É a conta para decidir se vale seguir
 * contestando os casos "não defender".
 */
class DisputeResultsCommand extends Command
{
    protected $signature = 'med:resultados {--dias=30 : MEDs abertos nos últimos N dias}';

    protected $description = 'Decisões da Woovi nas defesas enviadas, por recomendação da IA e por loja';

    public function handle(): int
    {
        $sent = Dispute::query()
            ->whereNotNull('evidence_sent_at')
            ->where('opened_at', '>=', now()->subDays((int) $this->option('dias')))
            ->get();

        // A recomendação que valia quando a defesa saiu (o envio guarda no evento).
        $atSend = DisputeEvent::query()
            ->whereIn('dispute_id', $sent->pluck('id'))
            ->where('event', DisputeEventType::EVIDENCE_SENT->value)
            ->get()
            ->groupBy('dispute_id')
            ->map(fn ($events) => data_get($events->first()->context, 'ai_recommendation'));

        $outcome = fn (Dispute $d): string => match ($d->woovi_status) {
            'REJECTED' => 'ganho',
            'ACCEPTED' => 'perdido',
            'CANCELED' => 'cancelado',
            default => 'aguardando',
        };

        $table = fn (string $title, $groups) => $this->table(
            [$title, 'Enviados', 'Ganhos', 'Perdidos', 'Aguardando', 'Taxa de ganho'],
            $groups->map(function ($group, $name) use ($outcome): array {
                $won = $group->filter(fn ($d) => $outcome($d) === 'ganho')->count();
                $lost = $group->filter(fn ($d) => $outcome($d) === 'perdido')->count();

                return [
                    $name,
                    $group->count(),
                    $won,
                    $lost,
                    $group->filter(fn ($d) => $outcome($d) === 'aguardando')->count(),
                    $won + $lost > 0 ? round(100 * $won / ($won + $lost)).'%' : '-',
                ];
            })->values()->all(),
        );

        $this->info("Defesas enviadas (MEDs dos últimos {$this->option('dias')} dias): {$sent->count()}");
        $table('Recomendação da IA', $sent->groupBy(fn (Dispute $d) => GenerateAiDefenseAction::recommendationLabel($atSend[$d->id] ?? data_get($d->ai_defense, 'recommendation'))));
        $table('Loja', $sent->groupBy(fn (Dispute $d) => $d->merchantName() ?? 'sem lojista'));

        $unanswered = Dispute::query()
            ->whereNull('evidence_sent_at')
            ->whereIn('woovi_status', ['REJECTED', 'ACCEPTED'])
            ->where('opened_at', '>=', now()->subDays((int) $this->option('dias')))
            ->get();

        $this->line(sprintf(
            'Para comparar, sem resposta nossa: %d decididos - %d ganhos, %d perdidos.',
            $unanswered->count(),
            $unanswered->where('woovi_status', 'REJECTED')->count(),
            $unanswered->where('woovi_status', 'ACCEPTED')->count(),
        ));

        return self::SUCCESS;
    }
}
