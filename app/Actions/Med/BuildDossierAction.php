<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\SourceStatus;
use App\Jobs\GenerateAiDefense;
use App\Models\Dispute;
use App\Models\DisputeSource;
use App\Services\Defense\DefenseWriter;
use App\Services\DisputeEventRecorder;
use App\Services\Kyc\KycClient;
use App\Services\SellerNotifier;
use App\Services\Unicopag\UnicopagClient;
use App\Services\Woovi\WooviClient;
use App\Services\Woovi\WooviException;
use App\Support\DisputeLock;
use App\Support\Mask;
use Closure;
use Throwable;

/**
 * Monta o dossiê automático do caso: busca cada fonte, guarda o que veio (payload
 * criptografado) e gera as evidências do sistema - comprovante oficial da Woovi,
 * registro da venda e os documentos que a plataforma já tem - antes de o seller
 * responder. No fim, roda a conferência.
 *
 * - Roda sob a trava do caso (DisputeLock): montagem e envio nunca se cruzam.
 * - Fonte que falha não derruba as outras e nada aqui lança por causa de fonte.
 * - O que já deu certo não é buscado de novo, salvo $refresh (tudo) ou
 *   $refreshSources (só essas - as que alimentam travas do envio automático).
 * - Releitura que falha NÃO apaga o que já estava ok: fica o dado anterior, com o
 *   erro registrado e o fetched_at antigo (quem exige dado recente vê que não é).
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
        private readonly SellerNotifier $notifier,
        private readonly DefenseWriter $defenseWriter,
    ) {}

    /**
     * @param  array<int, DossierSource>  $refreshSources
     */
    public function handle(Dispute $dispute, bool $refresh = false, array $refreshSources = [], int $waitSeconds = 60): Dispute
    {
        return DisputeLock::run($dispute, fn () => $this->build($dispute->refresh(), $refresh, $refreshSources), $waitSeconds);
    }

    /**
     * @param  array<int, DossierSource>  $refreshSources
     */
    private function build(Dispute $dispute, bool $refresh, array $refreshSources): Dispute
    {
        $dispute->loadMissing('wooviAccount');
        $woovi = WooviClient::for($dispute->wooviAccount);
        $accountId = (string) $dispute->wooviAccount->unicopag_account_id;
        $again = fn (DossierSource $source): bool => $refresh || in_array($source, $refreshSources, true);

        $transaction = $this->fetch($dispute, DossierSource::WOOVI_TRANSACTION, $again, function () use ($woovi, $dispute) {
            return $this->wooviOrNotFound(fn () => $woovi->getTransaction($dispute->end_to_end_id));
        });

        $correlationId = $this->string(data_get($transaction, 'charge.correlationID'));

        // Sem a transação, o que depende dela fica "skipped" e é reavaliado na
        // próxima montagem - não é a mesma coisa que Pix avulso (transação ok sem
        // cobrança), que é resposta definitiva.
        $this->fetch($dispute, DossierSource::WOOVI_CHARGE, $again, function () use ($woovi, $transaction, $correlationId) {
            if ($transaction === null || $correlationId === null) {
                return [SourceStatus::SKIPPED, null];
            }

            return $this->wooviOrNotFound(fn () => $woovi->getCharge($correlationId));
        });

        $this->fetch($dispute, DossierSource::WOOVI_REFUNDS, $again, function () use ($woovi, $transaction, $correlationId) {
            if ($transaction === null || $correlationId === null) {
                return [SourceStatus::SKIPPED, null];
            }

            return $this->wooviOrNotFound(fn () => ['refunds' => $woovi->getChargeRefunds($correlationId)]);
        });

        $this->fetch($dispute, DossierSource::WOOVI_RECEIPT, $again, function () use ($woovi, $dispute, $transaction) {
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

        $this->fetch($dispute, DossierSource::WOOVI_FUNDS_RECOVERY, $again, function () use ($woovi, $dispute) {
            try {
                $disputes = $woovi->getFundsRecoveryDisputes($dispute->end_to_end_id);
            } catch (WooviException $e) {
                // 403 = conta sem a feature "MED API" (ou AppID sem escopo): é configuração.
                return match (true) {
                    $e->status === 403 => [SourceStatus::NOT_CONFIGURED, null],
                    $e->isNotFound() => [SourceStatus::NOT_FOUND, null],
                    default => throw $e,
                };
            }

            $mine = collect($disputes)->first(fn (array $d): bool => ($d['endToEndId'] ?? null) === $dispute->end_to_end_id
                && strtoupper((string) ($d['sentOrReceived'] ?? 'RECEIVED')) === 'RECEIVED');

            return $mine === null ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, $mine];
        });

        $this->fetch($dispute, DossierSource::UNICOPAG_SALE, $again, function () use ($dispute, $accountId, $transaction, $correlationId) {
            // Sem account_id também: na conta mestre a venda é que diz de qual lojista é
            // o caso (o unico-api casa pelo correlationID da cobrança).
            if (! $this->unicopag->saleConfigured()) {
                return [SourceStatus::NOT_CONFIGURED, null];
            }

            // Sem a transação não há correlationID/txid para casar: "não achou" sem
            // os identificadores não seria resposta.
            if ($transaction === null) {
                return [SourceStatus::SKIPPED, null];
            }

            $sale = $this->unicopag->sale($accountId, $correlationId, $this->string(data_get($transaction, 'transactionID')), $dispute->end_to_end_id);

            return $sale === null || $sale === [] ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, $sale];
        });

        // Lojista da venda: vale para o cadastro, o KYC e o aviso.
        $merchantChanged = $this->rememberMerchant($dispute);
        $accountId = $dispute->platformAccountId();

        $this->fetch($dispute, DossierSource::UNICOPAG_REGISTER, $again, function () use ($accountId) {
            if (! $this->unicopag->registerConfigured() || $accountId === '') {
                return [SourceStatus::NOT_CONFIGURED, null];
            }

            $register = $this->unicopag->register($accountId);

            return $register === null || $register === [] ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, $register];
        });

        $this->fetch($dispute, DossierSource::KYC_STATUS, $again, function () use ($accountId, $dispute) {
            if (! $this->kyc->isConfigured()) {
                // A venda pode trazer o status do KYC do lojista (lido na plataforma).
                $fromSale = data_get($dispute->merchant, 'kyc_status');

                return is_string($fromSale) && $fromSale !== '' ? [SourceStatus::OK, ['status' => strtolower($fromSale), 'via' => 'unico-api']] : [SourceStatus::NOT_CONFIGURED, null];
            }

            // KYC ligado e conta sem vínculo com a plataforma: não conferido, que é
            // diferente de "KYC desligado" - o envio automático trava nisso.
            if ($accountId === '') {
                return [SourceStatus::SKIPPED, null];
            }

            $status = $this->kyc->onboardingStatus($accountId);

            return $status === null ? [SourceStatus::NOT_FOUND, null] : [SourceStatus::OK, ['status' => $status]];
        });

        $dispute->unsetRelation('sources');
        $dispute->load('sources');

        // O GET /dispute de um MED não traz o valor nem a alegação: a transação e a
        // recuperação de fundos trazem.
        $value = data_get($dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION), 'value');
        if ($dispute->value === null && is_numeric($value)) {
            $dispute->value = (int) round((float) $value);
        }

        $situation = data_get($dispute->sourcePayload(DossierSource::WOOVI_FUNDS_RECOVERY), 'situationType');
        if (blank($dispute->situation_type) && is_string($situation) && $situation !== '') {
            $dispute->situation_type = $situation;
        }

        if ($dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION) !== null) {
            try {
                $this->saleRecord->handle($dispute);
            } catch (Throwable $e) {
                report($e);
                $this->events->record(DisputeEventType::DOSSIER_SOURCE_FAILED, $dispute, 'Registro da venda não gerado: '.$e->getMessage(), level: 'error');
            }
        }

        $statuses = $dispute->sources->mapWithKeys(fn (DisputeSource $s): array => [$s->source->value => $s->status->value]);

        $dispute->fill([
            'dossier_status' => match ($statuses[DossierSource::WOOVI_TRANSACTION->value] ?? null) {
                SourceStatus::OK->value => $dispute->sources->contains(fn (DisputeSource $s): bool => $s->status === SourceStatus::FAILED || filled($s->error)) ? 'partial' : 'ready',
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
            ['sources' => $statuses->all(), 'refresh' => $refresh, 'refresh_sources' => array_map(fn (DossierSource $s) => $s->value, $refreshSources)],
        );

        $match = $this->match->handle($dispute);

        // Documento da plataforma só entra quando a venda conferiu com o Pix: o pedido
        // errado traria a nota fiscal (e os dados) de outro comprador para a evidência.
        if (($sale = $dispute->sourcePayload(DossierSource::UNICOPAG_SALE)) !== null
            && collect((array) ($match['checks'] ?? []))->firstWhere('key', 'sale_amount_matches')['status'] === MatchDossierAction::OK) {
            if ($this->platformDocuments->handle($dispute, (array) ($sale['documents'] ?? [])) > 0) {
                $this->match->handle($dispute);
            }
        }

        // Lojista identificado agora e ninguém avisado ainda (conta mestre sem
        // contato): o aviso sai para o lojista da venda.
        if ($merchantChanged && $dispute->seller_notified_at === null && $dispute->status === DisputeStatus::PENDING_SELLER) {
            rescue(fn () => $this->notifier->disputeOpened($dispute->refresh()), report: true);
        }

        // Defesa já preparada quando o analista abre a ficha: rascunho da IA com o
        // dossiê conferido, uma vez por caso (refazer é pelo botão da ficha).
        if (config('med.ia_automatica') && $dispute->ai_defense === null
            && $dispute->status->canSendToWoovi() && $this->defenseWriter->isConfigured()) {
            rescue(function () use ($dispute): void {
                GenerateAiDefense::dispatch($dispute->id);
            }, report: true);
        }

        return $dispute;
    }

    /**
     * Guarda o lojista que a venda informa (bloco `seller` + `account_id`). Devolve
     * true quando ele mudou nesta montagem.
     */
    private function rememberMerchant(Dispute $dispute): bool
    {
        $sale = $dispute->unsetRelation('sources')->load('sources')->sourcePayload(DossierSource::UNICOPAG_SALE);

        if ($sale === null) {
            return false;
        }

        $seller = (array) ($sale['seller'] ?? []);
        $merchant = array_filter([
            'name' => $this->string($seller['name'] ?? null),
            'trade_name' => $this->string($seller['trade_name'] ?? null),
            'tax_id' => $this->string(preg_replace('/\D/', '', (string) ($seller['document'] ?? ''))),
            'contact_name' => $this->string($seller['contact_name'] ?? null),
            'email' => filter_var($seller['email'] ?? null, FILTER_VALIDATE_EMAIL) ?: null,
            'phone' => $this->string($seller['phone'] ?? null),
            'kyc_status' => $this->string($seller['kyc_status'] ?? null),
        ]);
        $accountId = $this->string(isset($sale['account_id']) ? (string) $sale['account_id'] : null);

        if ($merchant === [] && $accountId === null) {
            return false;
        }

        $dispute->forceFill([
            'merchant' => $merchant ?: $dispute->merchant,
            'merchant_account_id' => $accountId ?? $dispute->merchant_account_id,
        ]);

        if (! $dispute->isDirty(['merchant', 'merchant_account_id'])) {
            return false;
        }

        $dispute->save();

        $this->events->record(
            DisputeEventType::MERCHANT_IDENTIFIED,
            $dispute,
            'Lojista identificado pela venda na plataforma: '.($merchant['trade_name'] ?? $merchant['name'] ?? "conta {$accountId}").'.',
            ['account_id' => $accountId],
        );

        return true;
    }

    /**
     * Busca uma fonte e grava o resultado. Devolve o payload vigente quando ok.
     *
     * @param  Closure(DossierSource): bool  $again
     * @param  Closure(): array{0: SourceStatus, 1: array<string, mixed>|null}  $fetch
     * @return array<string, mixed>|null
     */
    private function fetch(Dispute $dispute, DossierSource $source, Closure $again, Closure $fetch): ?array
    {
        $row = DisputeSource::query()->firstOrNew(['dispute_id' => $dispute->id, 'source' => $source->value]);

        // Ok com erro guardado = a última releitura falhou: tenta de novo, senão o erro
        // (e o dossiê "partial") nunca sai.
        if ($row->exists && ! $again($source) && ! $row->status->shouldRetry() && blank($row->error)) {
            return $row->status === SourceStatus::OK ? (array) $row->payload : null;
        }

        try {
            [$status, $payload] = $fetch();
        } catch (Throwable $e) {
            // Sem URL: a do KYC leva o account_id, e a de arquivo pode ser pré-assinada.
            $error = mb_substr(Mask::urls($e->getMessage()), 0, 1000);

            $this->events->record(
                DisputeEventType::DOSSIER_SOURCE_FAILED,
                $dispute,
                "{$source->label()}: {$error}",
                ['source' => $source->value, 'kept_previous' => $row->exists && $row->status === SourceStatus::OK],
                level: 'warning',
            );

            // Releitura que falhou não apaga o dado bom: guarda só o erro.
            if ($row->exists && $row->status === SourceStatus::OK) {
                $row->forceFill(['error' => $error, 'attempts' => $row->attempts + 1])->save();

                return (array) $row->payload;
            }

            $row->fill(['status' => SourceStatus::FAILED, 'payload' => null, 'error' => $error, 'attempts' => $row->attempts + 1, 'fetched_at' => now()])->save();

            return null;
        }

        $row->fill([
            'status' => $status,
            'payload' => $payload,
            'error' => null,
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
