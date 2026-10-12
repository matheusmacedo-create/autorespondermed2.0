<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Jobs\BuildDisputeDossier;
use App\Models\Dispute;
use App\Models\WooviAccount;
use App\Services\DiscordNotificationService;
use App\Services\DisputeEventRecorder;
use App\Services\SellerNotifier;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Cria ou atualiza o caso a partir do que a Woovi informa - pelo webhook
 * (OPENPIX:DISPUTE_*) ou pela sincronização (GET /dispute). Idempotente: o mesmo
 * payload duas vezes não duplica caso, evento nem aviso ao seller.
 */
class IngestDisputeAction
{
    public function __construct(
        private readonly DisputeEventRecorder $events,
        private readonly SellerNotifier $sellerNotifier,
        private readonly DiscordNotificationService $discord,
    ) {}

    /**
     * @param  array<string, mixed>  $payload  o objeto `dispute` da Woovi
     */
    public function handle(WooviAccount $account, array $payload, string $source = 'webhook', ?string $event = null): Dispute
    {
        $endToEndId = trim((string) ($payload['endToEndId'] ?? ''));

        if ($endToEndId === '') {
            throw new InvalidArgumentException('Disputa da Woovi sem endToEndId.');
        }

        $wooviStatus = isset($payload['status']) ? strtoupper((string) $payload['status']) : null;

        // O webhook de "aceita/rejeitada/cancelada" às vezes chega com o status no
        // nome do evento antes do campo: o evento decide quando o campo falta.
        $wooviStatus ??= match ($event) {
            'OPENPIX:DISPUTE_ACCEPTED' => 'ACCEPTED',
            'OPENPIX:DISPUTE_REJECTED' => 'REJECTED',
            'OPENPIX:DISPUTE_CANCELED' => 'CANCELED',
            'OPENPIX:DISPUTE_CREATED' => 'OPENED',
            default => null,
        };

        [$dispute, $created, $previousWooviStatus] = DB::transaction(function () use ($account, $payload, $endToEndId, $wooviStatus) {
            $dispute = Dispute::query()
                ->where('woovi_account_id', $account->id)
                ->where('end_to_end_id', $endToEndId)
                ->lockForUpdate()
                ->first();

            $created = $dispute === null;
            $previousWooviStatus = $dispute?->woovi_status;

            $dispute ??= new Dispute([
                'woovi_account_id' => $account->id,
                'end_to_end_id' => $endToEndId,
                'status' => DisputeStatus::PENDING_SELLER,
            ]);

            $dispute->fill(array_filter([
                'woovi_dispute_id' => $this->string($payload['id'] ?? $payload['_id'] ?? null),
                'type' => $this->string($payload['type'] ?? null),
                'woovi_status' => $wooviStatus,
                'value' => $this->cents($payload['value'] ?? null),
                'dispute_reason' => $this->string($payload['disputeReason'] ?? null),
                'situation_type' => $this->string($payload['situationType'] ?? null),
                'payer_name' => $this->string($payload['name'] ?? null),
                'payer_email' => $this->string($payload['email'] ?? null),
                'payer_phone' => $this->string($payload['phoneNumber'] ?? null),
            ], fn ($value) => $value !== null));

            if ($created) {
                $openedAt = $this->date($payload['createdAt'] ?? null) ?? now();
                $respondBy = $openedAt->copy()->addHours((int) config('med.prazo_resposta_horas'));

                $dispute->fill([
                    'type' => $dispute->type ?: 'MED',
                    'opened_at' => $openedAt,
                    'respond_by' => $respondBy,
                    'token_expires_at' => $respondBy->copy()->addHours((int) config('med.link.folga_horas')),
                ]);
            }

            $dispute->raw = array_merge((array) $dispute->raw, Arr::except($payload, ['raw']));

            $final = DisputeStatus::fromWooviStatus($wooviStatus);

            if ($final !== null && ! $dispute->status->isClosed()) {
                $dispute->status = $final;
                $dispute->closed_at = now();
                // Link morto: o caso acabou, não há mais o que o seller enviar.
                $dispute->token_expires_at = now();
            }

            $dispute->save();

            return [$dispute, $created, $previousWooviStatus];
        });

        $dispute->loadMissing('wooviAccount');
        $context = ['source' => $source, 'event' => $event, 'woovi_status' => $wooviStatus];

        if ($created) {
            $this->events->record(
                DisputeEventType::RECEIVED,
                $dispute,
                sprintf('%s de %s recebido da Woovi (%s).', $dispute->type, $dispute->formattedValue(), $source),
                $context,
                source: 'woovi',
            );

            if ($dispute->status->isClosed()) {
                // Caso que já nasce decidido (sincronização de histórico): registra,
                // mas não cobra defesa de quem não tem mais o que defender.
                $this->events->record(DisputeEventType::CLOSED, $dispute, "Caso já chegou encerrado: {$dispute->status->label()}.", $context, source: 'woovi');

                return $dispute;
            }

            $this->sellerNotifier->disputeOpened($dispute);
            $this->discord->disputeReceived($dispute);

            // Dossiê automático: o seller ainda nem abriu o link e o caso já vai sendo
            // montado. Best-effort - nunca pode derrubar a ingestão (o retry do webhook
            // veria o caso como já criado e o seller ficaria sem aviso).
            if (config('med.dossie.habilitado')) {
                // Closure com corpo: o PendingDispatch é enfileirado no __destruct, e com
                // arrow function isso aconteceria fora do rescue.
                rescue(function () use ($dispute): void {
                    BuildDisputeDossier::dispatch($dispute->id);
                }, report: true);
            }

            return $dispute;
        }

        if ($wooviStatus !== null && $wooviStatus !== $previousWooviStatus) {
            $this->events->record(
                DisputeEventType::WOOVI_STATUS_CHANGED,
                $dispute,
                sprintf('Status na Woovi: %s -> %s.', $previousWooviStatus ?? '—', $wooviStatus),
                $context,
                source: 'woovi',
            );

            if ($dispute->status->isClosed() && DisputeStatus::fromWooviStatus($previousWooviStatus) === null) {
                $this->events->record(DisputeEventType::CLOSED, $dispute, "Caso encerrado: {$dispute->status->label()}.", $context, source: 'woovi');
                $this->discord->disputeClosed($dispute);
                $this->sellerNotifier->disputeClosed($dispute);
            }
        }

        return $dispute;
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * A Woovi manda o valor em centavos - como número no webhook e como string
     * no GET /dispute/{id}.
     */
    private function cents(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        return (int) round((float) $value);
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($value), null, report: false);
    }
}
