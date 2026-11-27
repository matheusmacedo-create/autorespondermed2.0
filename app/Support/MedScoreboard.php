<?php

namespace App\Support;

use App\Enums\DisputeStatus;
use App\Enums\Provider;
use App\Models\Dispute;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * O placar dos MEDs, numa definição só para o painel e o `med:placar`.
 *
 * Respondido é o que saiu para a instituição da conta - Woovi ou Transfeera - (desde o
 * primeiro envio, fora da janela também: caso enviado nunca é podado). Ganho = REJECTED
 * (o lojista manteve o valor), perdido = ACCEPTED (devolvido ao pagador). O que ficou sem
 * resposta nossa conta à parte, para comparar: só os casos da janela, e só dos tipos que
 * o sistema responde (MED e chargeback, `Dispute::DEFENDED_TYPES`).
 *
 * Sem resposta nossa não quer dizer sem resposta: a infração respondida fora daqui (no
 * painel da Transfeera) fica em "Resposta enviada" sem `evidence_sent_at`. Ela não entra
 * nos respondidos nem é prazo correndo ou perdido ({@see unansweredOpen()}); até a
 * decisão conta à parte ({@see answeredElsewhere()}) e, decidida, entra nos ganhos e
 * perdidos sem resposta nossa - foi decidida sem defesa deste sistema.
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
        // A conta e o `respond_by` entram por causa da instituição: o prazo da
        // Transfeera é o de cada infração, e o placar separa as respostas por ela.
        $columns = ['id', 'woovi_account_id', 'type', 'status', 'value', 'opened_at', 'respond_by', 'evidence_sent_at'];

        return new self(
            Dispute::query()->with('wooviAccount:id,provider')->whereNotNull('evidence_sent_at')->get($columns),
            // "Sem resposta" só dos tipos que o sistema responde, pelo mesmo teste do
            // `med:responder`: com uma lista à parte aqui (`type = MED`), o chargeback
            // sem resposta sumia do placar.
            Dispute::query()->with('wooviAccount:id,provider')->inWindow()->whereNull('evidence_sent_at')->get($columns)
                ->filter(fn (Dispute $dispute): bool => $dispute->isDefendedType()),
        );
    }

    /**
     * Quantos casos por instituição ("Woovi 252 · Transfeera 6"). Vazio quando todos
     * são de uma só: aí o total já diz tudo.
     */
    public static function byProvider(Collection $disputes): string
    {
        $counts = $disputes->countBy(fn (Dispute $dispute): string => $dispute->provider()->label());

        return $counts->count() > 1
            ? $counts->sortKeys()->map(fn (int $count, string $label): string => "{$label} {$count}")->reverse()->implode(' · ')
            : '';
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
     * Ainda esperando resposta: `true` = o prazo da instituição ainda corre, `false` = o
     * prazo passou sem resposta. Só o que espera resposta de verdade
     * (`DisputeStatus::awaitsResponse()`): tirar apenas os encerrados deixava aqui o
     * respondido fora daqui, como prazo correndo e depois como prazo perdido.
     *
     * @return Collection<int, Dispute>
     */
    public function unansweredOpen(bool $withinDeadline): Collection
    {
        $hours = (int) config('med.prazo_woovi_horas');

        return $this->unanswered
            ->filter(fn (Dispute $dispute): bool => $dispute->status->awaitsResponse())
            // Transfeera: o prazo é o da própria infração (`respond_by`).
            ->filter(fn (Dispute $dispute): bool => $withinDeadline === (bool) ($dispute->provider() === Provider::TRANSFEERA
                ? $dispute->respond_by?->isFuture()
                : $dispute->opened_at?->copy()->addHours($hours)->isFuture()));
    }

    /**
     * Respondidos fora deste sistema e ainda sem decisão: "Resposta enviada" sem
     * `evidence_sent_at`, como a ingestão deixa a infração que chega com a análise já
     * registrada na Transfeera (`IngestDisputeAction`). Decididos, saem daqui para os
     * ganhos e perdidos sem resposta nossa.
     *
     * @return Collection<int, Dispute>
     */
    public function answeredElsewhere(): Collection
    {
        return $this->unanswered->filter(fn (Dispute $dispute): bool => $dispute->status === DisputeStatus::EVIDENCE_SENT);
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
