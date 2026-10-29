<?php

namespace App\Support;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * O placar dos MEDs, numa definição só para o painel e o `med:placar`.
 *
 * Respondido é o que saiu para a Woovi (desde o primeiro envio, fora da janela também:
 * caso enviado nunca é podado). Ganho = REJECTED (o lojista manteve o valor), perdido =
 * ACCEPTED (devolvido ao pagador). O que ficou sem resposta nossa conta à parte, só os
 * MEDs da janela, para comparar.
 */
final class MedScoreboard
{
    /**
     * @param  Collection<int, Dispute>  $answered
     * @param  Collection<int, Dispute>  $unanswered
     */
    private function __construct(
        public readonly Collection $answered,
        public readonly Collection $unanswered,
    ) {}

    public static function make(): self
    {
        $columns = ['id', 'type', 'status', 'value', 'opened_at', 'evidence_sent_at'];

        return new self(
            Dispute::query()->whereNotNull('evidence_sent_at')->get($columns),
            Dispute::query()->inWindow()->whereNull('evidence_sent_at')->where('type', 'MED')->get($columns),
        );
    }

    /**
     * @return Collection<int, Dispute>
     */
    public function waiting(): Collection
    {
        return $this->answered->reject(fn (Dispute $dispute): bool => $dispute->status->isClosed());
    }

    /**
     * @return Collection<int, Dispute>
     */
    public function won(?Collection $disputes = null): Collection
    {
        return ($disputes ?? $this->answered)->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::REJECTED);
    }

    /**
     * @return Collection<int, Dispute>
     */
    public function lost(?Collection $disputes = null): Collection
    {
        return ($disputes ?? $this->answered)->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::ACCEPTED);
    }

    /**
     * @return Collection<int, Dispute>
     */
    public function canceled(?Collection $disputes = null): Collection
    {
        return ($disputes ?? $this->answered)->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::CANCELED);
    }

    /**
     * Sem resposta nossa e ainda sem decisão: `true` = o prazo da Woovi ainda corre.
     *
     * @return Collection<int, Dispute>
     */
    public function unansweredOpen(bool $withinDeadline): Collection
    {
        $hours = (int) config('med.prazo_woovi_horas');

        return $this->unanswered
            ->reject(fn (Dispute $dispute): bool => $dispute->status->isClosed())
            ->filter(fn (Dispute $dispute): bool => $withinDeadline === (bool) $dispute->opened_at?->copy()->addHours($hours)->isFuture());
    }

    /**
     * Taxa de ganho entre os decididos (ganho ou perdido), em %; null sem decisão.
     */
    public function winRate(): ?int
    {
        $decided = $this->won()->count() + $this->lost()->count();

        return $decided > 0 ? (int) round(100 * $this->won()->count() / $decided) : null;
    }

    public function firstAnsweredAt(): ?CarbonInterface
    {
        return $this->answered->min('evidence_sent_at');
    }

    /**
     * @param  Collection<int, Dispute>  $disputes
     */
    public static function money(Collection $disputes): string
    {
        return 'R$ '.number_format($disputes->sum('value') / 100, 2, ',', '.');
    }
}
