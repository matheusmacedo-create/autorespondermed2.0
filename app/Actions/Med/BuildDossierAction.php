<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\SourceStatus;
use App\Models\Dispute;
use App\Models\DisputeSource;
use App\Services\DisputeEventRecorder;
use App\Services\Kyc\KycClient;
use App\Services\Unicopag\UnicopagClient;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;
use Closure;
use Throwable;

/**
 * Monta o dossiê automático do caso: busca cada fonte, guarda o que veio (payload
 * criptografado) e gera as evidências do sistema - comprovante oficial da Woovi e
 * registro da venda - antes de o seller responder. No fim, roda a conferência.
 *
 * Fonte que falha não derruba as outras e nada aqui lança: o dossiê é um extra e
 * nunca pode travar a ingestão do MED nem a defesa do seller. Fonte que já deu
 * certo não é buscada de novo, a não ser com $refresh (botão "Montar dossiê agora").
 */
class BuildDossierAction
{
    public function __construct(
        private readonly UnicopagClient $unicopag,
        private readonly KycClient $kyc,
        private readonly StoreSystemEvidence $storeSystemEvidence,
        private readonly GenerateSaleRecordAction $saleRecord,
        private readonly ImportPlatformDocumentsAction $platformDocuments,
        private readonly MatchDossierAction $match,
        private readonly DisputeEventRecorder $events,
    ) {}

    public function handle(Dispute $dispute, bool $refresh = false): Dispute
    {
        $dispute->loadMissing('wooviAccount');
        $woovi = WooviClient::for($dispute->wooviAccount);
        $accountId = (string) $dispute->wooviAccount->unicopag_account_id;

        $transaction = $this->fetch($dispute, DossierSource::WOOVI_TRANSACTION, $refresh, function () use ($woovi, $dispute) {
            return $this->wooviOrNotFound(fn () => $woovi->getTransaction($dispute->end_to_end_id));
        });

        $correlationId = $this->string(data_get($transaction, 'charge.correlationID'));

        $this->fetch($dispute, DossierSource::WOOVI_CHARGE, $refresh, function () use ($woovi, $transaction, $correlationId) {
            // Pix avulso (direto na chave) não tem cobrança: não há o que buscar.
            if ($transaction === null || $correlationId === null) {
                return [SourceStatus::SKIPPED, null];
            }

            return $this->wooviOrNotFound(fn () => $woovi->getCharge($correlationId));
        });

        $this->fetch($dispute, DossierSource::WOOVI_REFUNDS, $refresh, function () use ($woovi, $correlationId) {
            if ($correlationId === null) {
                return [SourceStatus::SKIPPED, null];
            }

            return $this->wooviOrNotFound(fn () => ['refunds' => $woovi->getChargeRefunds($correlationId)]);
        });

        $this->fetch($dispute, DossierSource::WOOVI_RECEIPT, $refresh, function () use ($woovi, $dispute, $transaction) {
            if (! config('med.dossie.comprovante_woovi') || $transaction === null) {
                return [SourceStatus::SKIPPED, null];
            }

            $result = $this->wooviOrNotFound(fn () => ['pdf' => $woovi->getPixInReceipt($dispute->end_to_end_id)]);

            if ($result[0] !== SourceStatus::OK) {
                return $result;
            }

            $this->storeSystemEvidence->handle(
                $dispute,
                DossierSource::WOOVI_RECEIPT->value,
                EvidenceKind::PIX_RECEIPT,
                $result[1]['pdf'],
                "comprovante-pix-{$dispute->end_to_end_id}.pdf",
                'application/pdf',
                'Comprovante emitido pela Woovi, instituição do recebedor',
                // O PDF pode mudar de bytes a cada download (data de emissão): o
                // comprovante de um endToEndId é sempre o mesmo documento.
                fingerprint: hash('sha256', 'woovi-receipt:'.$dispute->end_to_end_id),
            );

            // O PDF já virou evidência; o payload guarda só que ele existe.
            return [SourceStatus::OK, ['stored' => true]];
        });

        $this->fetch($dispute, DossierSource::UNICOPAG_SALE, $refresh, function () use ($dispute, $accountId, $transaction, $correlationId) {
            if (! $this->unicopag->saleConfigured() || $accountId === '') {
                return [SourceStatus::NOT_CONFIGURED, null];
            }

            $sale = $this->unicopag->sale($accountId, $correlationId, $this->string(data_get($transaction, 'transactionID')), $dispute->end_to_end_id);

            return $sale === null ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, $sale];
        });

        $this->fetch($dispute, DossierSource::UNICOPAG_REGISTER, $refresh, function () use ($accountId) {
            if (! $this->unicopag->registerConfigured() || $accountId === '') {
                return [SourceStatus::NOT_CONFIGURED, null];
            }

            $register = $this->unicopag->register($accountId);

            return $register === null ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, $register];
        });

        $this->fetch($dispute, DossierSource::KYC_STATUS, $refresh, function () use ($accountId) {
            if (! $this->kyc->isConfigured() || $accountId === '') {
                return [SourceStatus::NOT_CONFIGURED, null];
            }

            $status = $this->kyc->onboardingStatus($accountId);

            return $status === null ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, ['status' => $status]];
        });

        $dispute->unsetRelation('sources');
        $dispute->load('sources');

        // O GET /dispute de um MED não traz o valor: a transação traz.
        $value = data_get($dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION), 'value');
        if ($dispute->value === null && is_numeric($value)) {
            $dispute->value = (int) round((float) $value);
        }

        if ($dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION) !== null) {
            try {
                $this->saleRecord->handle($dispute);
            } catch (Throwable $e) {
                report($e);
                $this->events->record(DisputeEventType::DOSSIER_SOURCE_FAILED, $dispute, 'Registro da venda não gerado: '.$e->getMessage(), level: 'error');
            }
        }

        if (($sale = $dispute->sourcePayload(DossierSource::UNICOPAG_SALE)) !== null) {
            $this->platformDocuments->handle($dispute, (array) ($sale['documents'] ?? []));
        }

        $statuses = $dispute->sources->mapWithKeys(fn (DisputeSource $s): array => [$s->source->value => $s->status->value]);

        $dispute->fill([
            'dossier_status' => match ($statuses[DossierSource::WOOVI_TRANSACTION->value] ?? null) {
                SourceStatus::OK->value => $statuses->contains(SourceStatus::FAILED->value) ? 'partial' : 'ready',
                SourceStatus::FAILED->value => 'failed',
                default => 'partial',
            },
            'dossier_built_at' => now(),
        ])->save();

        $this->events->record(
            DisputeEventType::DOSSIER_BUILT,
            $dispute,
            'Dossiê automático: '.$dispute->sources
                ->map(fn (DisputeSource $s): string => $s->source->label().' - '.mb_strtolower($s->status->label()))
                ->implode('; ').'.',
            ['sources' => $statuses->all(), 'refresh' => $refresh],
        );

        $this->match->handle($dispute);

        return $dispute;
    }

    /**
     * Busca uma fonte e grava o resultado. Devolve o payload quando deu certo.
     *
     * @param  Closure(): array{0: SourceStatus, 1: array<string, mixed>|null}  $fetch
     * @return array<string, mixed>|null
     */
    private function fetch(Dispute $dispute, DossierSource $source, bool $refresh, Closure $fetch): ?array
    {
        $row = DisputeSource::query()->firstOrNew(['dispute_id' => $dispute->id, 'source' => $source->value]);

        if ($row->exists && ! $refresh && ! $row->status->shouldRetry()) {
            return $row->status === SourceStatus::OK ? (array) $row->payload : null;
        }

        try {
            [$status, $payload] = $fetch();
            $error = null;
        } catch (Throwable $e) {
            [$status, $payload, $error] = [SourceStatus::FAILED, null, mb_substr($e->getMessage(), 0, 1000)];

            $this->events->record(
                DisputeEventType::DOSSIER_SOURCE_FAILED,
                $dispute,
                "{$source->label()}: {$error}",
                ['source' => $source->value],
                level: 'warning',
            );
        }

        $row->fill([
            'status' => $status,
            'payload' => $payload,
            'error' => $error,
            'attempts' => $row->attempts + 1,
            'fetched_at' => now(),
        ])->save();

        return $status === SourceStatus::OK ? $payload : null;
    }

    /**
     * Woovi: "não achou" é resposta (not_found), não falha.
     *
     * @param  Closure(): array<string, mixed>  $call
     * @return array{0: SourceStatus, 1: array<string, mixed>|null}
     */
    private function wooviOrNotFound(Closure $call): array
    {
        try {
            $payload = $call();
        } catch (WooviException $e) {
            if ($e->isNotFound()) {
                return [SourceStatus::NOT_FOUND, null];
            }

            throw $e;
        }

        return $payload === [] ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, $payload];
    }

    private function string(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }
}
