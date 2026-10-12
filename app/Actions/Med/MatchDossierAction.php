<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\SourceStatus;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\DisputeSource;
use App\Services\DisputeEventRecorder;
use App\Support\Mask;
use Illuminate\Support\Carbon;

/**
 * Conferência do dossiê: cruza o que a Woovi, a plataforma, o KYC e o seller dizem
 * sobre a venda. Cada item sai como ok, divergente ou sem dado - sem PII no texto.
 *
 * - Os itens ok viram fatos na carta de defesa (`fact`): só o que foi confirmado vai
 *   para a Woovi e o banco do pagador.
 * - Divergência em item que bloqueia (`blocking`) tira o caso do envio automático e
 *   fica só no painel - nunca na carta.
 */
class MatchDossierAction
{
    public const OK = 'ok';

    public const DIVERGENT = 'divergent';

    public const MISSING = 'missing';

    public function __construct(private readonly DisputeEventRecorder $events) {}

    /**
     * @return array<string, mixed> o resultado gravado em disputes.dossier_match
     */
    public function handle(Dispute $dispute): array
    {
        $dispute->unsetRelation('sources');
        $dispute->load(['sources', 'evidences']);

        $transaction = $dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION);
        $charge = $dispute->sourcePayload(DossierSource::WOOVI_CHARGE);
        $refunds = $dispute->sourcePayload(DossierSource::WOOVI_REFUNDS);
        $sale = $dispute->sourcePayload(DossierSource::UNICOPAG_SALE);
        $kyc = $dispute->sourcePayload(DossierSource::KYC_STATUS);

        $txValue = is_numeric($transaction['value'] ?? null) ? (int) round((float) $transaction['value']) : null;
        $customerTaxId = data_get($charge, 'customer.taxID.taxID') ?? data_get($transaction, 'customer.taxID.taxID');

        $checks = [
            $this->check('transaction_found', 'Transação localizada na Woovi', true, match (true) {
                $transaction !== null => [self::OK, 'Pix localizado pelo endToEndId.'],
                $this->sourceStatus($dispute, DossierSource::WOOVI_TRANSACTION) === SourceStatus::NOT_FOUND => [self::DIVERGENT, 'A Woovi não encontrou a transação deste endToEndId na conta.'],
                default => [self::MISSING, 'Transação ainda não obtida.'],
            }, 'O Pix contestado foi localizado nos registros da instituição do recebedor.'),

            $this->check('value_matches', 'Valor contestado x valor do Pix', true, match (true) {
                $txValue === null || $dispute->value === null => [self::MISSING, 'Sem um dos valores para comparar.'],
                $dispute->value <= $txValue => [self::OK, 'Valor contestado dentro do valor pago.'],
                default => [self::DIVERGENT, 'Valor contestado maior que o valor do Pix.'],
            }),

            $this->check('charge_linked', 'Pix pagou uma cobrança do recebedor', false, match (true) {
                $charge !== null => [self::OK, 'Pagamento de cobrança gerada pelo recebedor.'],
                $transaction !== null && data_get($transaction, 'charge') === null => [self::MISSING, 'Pix avulso (direto na chave), sem cobrança.'],
                default => [self::MISSING, 'Cobrança não obtida.'],
            }, 'O pagamento quitou uma cobrança gerada pelo recebedor para a venda, e não foi uma transferência avulsa.'),

            $this->check('charge_before_payment', 'Cobrança criada antes do pagamento', false, $this->before(data_get($charge, 'createdAt'), data_get($transaction, 'time')), 'A cobrança foi criada antes do pagamento, no fluxo normal de uma compra.'),

            $this->check('payer_is_customer', 'Quem pagou é o cliente da cobrança', true, match (Mask::sameTaxId(data_get($transaction, 'payer.taxID.taxID'), $customerTaxId)) {
                true => [self::OK, 'Mesmo CPF/CNPJ no pagamento e no cadastro da cobrança.'],
                false => [self::DIVERGENT, 'CPF/CNPJ de quem pagou é diferente do cliente da cobrança.'],
                null => [self::MISSING, 'Sem documento do pagador ou do cliente para comparar.'],
            }, 'O CPF/CNPJ de quem pagou é o mesmo do cliente cadastrado na cobrança.'),

            $this->check('no_refund', 'Nenhuma devolução já feita', true, match (true) {
                $refunds === null => [self::MISSING, 'Devoluções não consultadas.'],
                collect((array) ($refunds['refunds'] ?? []))->contains(fn ($r): bool => in_array($r['status'] ?? null, ['CONFIRMED', 'IN_PROCESSING'], true)) => [self::DIVERGENT, 'Já existe devolução nesta cobrança: risco de devolver duas vezes.'],
                default => [self::OK, 'Nenhuma devolução registrada na cobrança.'],
            }),

            $this->check('sale_found', 'Venda localizada na plataforma', false, match (true) {
                $sale !== null => [self::OK, 'Pedido localizado no unico-api.'],
                $this->sourceStatus($dispute, DossierSource::UNICOPAG_SALE) === SourceStatus::NOT_FOUND => [self::MISSING, 'O unico-api não achou a venda.'],
                default => [self::MISSING, 'Busca da venda não configurada ou não feita.'],
            }, 'A venda está registrada na plataforma ÚnicoPag.'),

            $this->check('sale_amount_matches', 'Valor do pedido x valor do Pix', true, match (true) {
                $sale === null || $txValue === null || ! is_numeric($sale['amount_total'] ?? null) => [self::MISSING, 'Sem pedido ou valor para comparar.'],
                (int) round((float) $sale['amount_total']) === $txValue => [self::OK, 'Valor do pedido igual ao do Pix.'],
                default => [self::DIVERGENT, 'Valor do pedido diferente do valor do Pix.'],
            }, 'O valor do pedido na plataforma é igual ao valor do Pix.'),

            $this->check('delivery', 'Entrega comprovada', false, $this->delivery($sale, $dispute)),

            $this->check('kyc_approved', 'Seller com KYC aprovado', true, match ($kyc['status'] ?? null) {
                'approved' => [self::OK, 'Onboarding aprovado no KYC.'],
                'rejected', 'repproved', 'blocked' => [self::DIVERGENT, 'Onboarding do seller reprovado ou bloqueado no KYC.'],
                null => [self::MISSING, 'Status do KYC não obtido.'],
                default => [self::MISSING, 'Onboarding do seller ainda não aprovado no KYC.'],
            }, 'O recebedor teve o cadastro verificado (KYC) pela ÚnicoPag.'),

            $this->check('seller_statement', 'Relato do seller', false, filled($dispute->seller_statement)
                ? [self::OK, 'Seller mandou o relato.']
                : [self::MISSING, 'Seller ainda não mandou o relato.']),

            $this->check('seller_evidence', 'Comprovantes do seller', false, $dispute->evidences->contains(fn (DisputeEvidence $e): bool => $e->uploaded_by === 'seller')
                ? [self::OK, 'Seller anexou comprovantes.']
                : [self::MISSING, 'Seller ainda não anexou comprovantes.']),
        ];

        $byKey = collect($checks)->keyBy('key');
        $divergent = collect($checks)->where('status', self::DIVERGENT)->where('blocking', true)->pluck('key')->values()->all();
        $hasSystemProof = $dispute->evidences->contains(fn (DisputeEvidence $e): bool => in_array($e->kind, [EvidenceKind::PIX_RECEIPT, EvidenceKind::SALE_RECORD], true));

        $result = [
            'computed_at' => now()->toIso8601String(),
            // Pronto = transação conferida, nenhuma divergência que bloqueia e algum
            // documento de prova (do dossiê ou do seller) para mandar.
            'ready' => $byKey['transaction_found']['status'] === self::OK
                && $divergent === []
                && ($hasSystemProof || $byKey['seller_evidence']['status'] === self::OK),
            'divergent' => $divergent,
            'checks' => $checks,
        ];

        $dispute->forceFill(['dossier_match' => $result])->save();

        $ok = collect($checks)->where('status', self::OK)->count();
        $this->events->record(
            DisputeEventType::DOSSIER_MATCHED,
            $dispute,
            sprintf('Conferência: %d de %d itens ok%s.', $ok, count($checks), $divergent === []
                ? ''
                : '; divergência em '.collect($checks)->whereIn('key', $divergent)->pluck('label')->implode(', ')),
            ['divergent' => $divergent, 'ready' => $result['ready']],
            level: $divergent === [] ? 'info' : 'warning',
        );

        return $result;
    }

    /**
     * Fatos confirmados para a carta de defesa (só os itens ok que têm frase).
     *
     * @return array<int, string>
     */
    public static function facts(?array $match): array
    {
        return collect((array) ($match['checks'] ?? []))
            ->filter(fn (array $check): bool => $check['status'] === self::OK && filled($check['fact'] ?? null))
            ->pluck('fact')
            ->values()
            ->all();
    }

    /**
     * @param  array{0: string, 1: string, 2?: string}  $result  o 3º item troca a frase da carta
     * @return array<string, mixed>
     */
    private function check(string $key, string $label, bool $blocking, array $result, ?string $fact = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'status' => $result[0],
            'detail' => $result[1],
            'blocking' => $blocking,
            'fact' => $result[2] ?? $fact,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function before(mixed $chargeCreated, mixed $paidAt): array
    {
        $created = is_string($chargeCreated) ? rescue(fn () => Carbon::parse($chargeCreated), null, report: false) : null;
        $paid = is_string($paidAt) ? rescue(fn () => Carbon::parse($paidAt), null, report: false) : null;

        return match (true) {
            $created === null || $paid === null => [self::MISSING, 'Sem as duas datas para comparar.'],
            $created->lessThanOrEqualTo($paid) => [self::OK, 'Cobrança criada '.$created->diffForHumans($paid, ['syntax' => Carbon::DIFF_ABSOLUTE]).' antes do pagamento.'],
            default => [self::DIVERGENT, 'Cobrança criada depois do pagamento.'],
        };
    }

    /**
     * @param  array<string, mixed>|null  $sale
     * @return array{0: string, 1: string, 2?: string}
     */
    private function delivery(?array $sale, Dispute $dispute): array
    {
        $status = strtolower((string) data_get($sale, 'fulfillment.status'));

        return match (true) {
            $status === 'delivered' || filled(data_get($sale, 'fulfillment.delivered_at')) => [self::OK, 'Entrega registrada na plataforma.', 'A entrega do pedido está registrada na plataforma.'],
            $status === 'not_applicable' => [self::OK, 'Produto/serviço sem entrega física.', 'O produto ou serviço vendido não tem entrega física.'],
            filled(data_get($sale, 'fulfillment.tracking_code')) || filled(data_get($dispute->seller_details, 'tracking_code')) => [self::OK, 'Código de rastreio registrado.', 'O envio do pedido tem código de rastreio registrado.'],
            $dispute->evidences->contains(fn (DisputeEvidence $e): bool => in_array($e->kind, [EvidenceKind::DELIVERY_PROOF, EvidenceKind::SERVICE_PROOF], true)) => [self::OK, 'Comprovante de entrega/serviço anexado.', 'Há comprovante de entrega ou de prestação do serviço entre as evidências.'],
            default => [self::MISSING, 'Nenhum registro de entrega.'],
        };
    }

    private function sourceStatus(Dispute $dispute, DossierSource $source): ?SourceStatus
    {
        return $dispute->sources->first(fn (DisputeSource $s): bool => $s->source === $source)?->status;
    }
}
