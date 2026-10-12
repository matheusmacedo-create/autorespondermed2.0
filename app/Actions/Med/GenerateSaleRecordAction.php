<?php

namespace App\Actions\Med;

use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Support\Mask;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;

/**
 * "Registro da venda": um PDF montado com o que a Woovi e a plataforma sabem da
 * venda - a cobrança que o recebedor criou, o pagamento que a quitou, o pedido e a
 * entrega. É o que mostra que o Pix pagou uma venda real, não um Pix avulso.
 *
 * Dados do pagador/comprador saem mascarados (Mask): o documento prova o vínculo
 * (o mesmo CPF na cobrança e no pagamento) sem expor o dado inteiro.
 */
class GenerateSaleRecordAction
{
    public function __construct(private readonly StoreSystemEvidence $storeSystemEvidence) {}

    public function handle(Dispute $dispute): ?DisputeEvidence
    {
        $data = $this->data($dispute);

        if ($data === null) {
            return null;
        }

        $pdf = Pdf::loadView('pdf.sale-record', $data + [
            'generatedAt' => now()->timezone('America/Sao_Paulo'),
        ])->setPaper('a4')->output();

        return $this->storeSystemEvidence->handle(
            $dispute,
            'sale_record',
            EvidenceKind::SALE_RECORD,
            $pdf,
            "registro-da-venda-{$dispute->end_to_end_id}.pdf",
            'application/pdf',
            'Cobrança, pagamento, pedido e entrega reunidos pela ÚnicoPag',
            fingerprint: hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE) ?: ''),
        );
    }

    /**
     * Dados do documento (só o que existe; nada inventado). Null sem a transação.
     *
     * @return array<string, mixed>|null
     */
    public function data(Dispute $dispute): ?array
    {
        $transaction = $dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION);

        if ($transaction === null) {
            return null;
        }

        $charge = $dispute->sourcePayload(DossierSource::WOOVI_CHARGE);
        $refunds = (array) ($dispute->sourcePayload(DossierSource::WOOVI_REFUNDS)['refunds'] ?? []);
        $sale = $dispute->sourcePayload(DossierSource::UNICOPAG_SALE);

        $payerTaxId = data_get($transaction, 'payer.taxID.taxID');
        $customer = (array) ($charge['customer'] ?? $transaction['customer'] ?? []);

        return [
            'endToEndId' => $dispute->end_to_end_id,
            'payment' => array_filter([
                'Valor' => $this->money(data_get($transaction, 'value')),
                'Pago em' => $this->date(data_get($transaction, 'time')),
                'Identificador da cobrança (txid)' => data_get($transaction, 'transactionID'),
                'Mensagem do pagador' => data_get($transaction, 'infoPagador'),
            ]),
            'payer' => array_filter([
                'Nome' => data_get($transaction, 'payer.name'),
                'CPF/CNPJ' => Mask::taxId($payerTaxId),
            ]),
            'charge' => $charge === null ? [] : array_filter([
                'Criada pelo recebedor em' => $this->date($charge['createdAt'] ?? null),
                'Valor cobrado' => $this->money($charge['value'] ?? null),
                'Identificador do pedido (correlationID)' => $charge['correlationID'] ?? null,
                'Descrição' => $charge['comment'] ?? null,
            ]),
            'chargeInfo' => collect((array) ($charge['additionalInfo'] ?? []))
                ->filter(fn ($item): bool => is_array($item) && filled($item['key'] ?? null))
                ->mapWithKeys(fn (array $item): array => [(string) $item['key'] => (string) ($item['value'] ?? '')])
                ->all(),
            'customer' => array_filter([
                'Nome' => $customer['name'] ?? null,
                'CPF/CNPJ' => Mask::taxId(data_get($customer, 'taxID.taxID')),
                'E-mail' => Mask::email($customer['email'] ?? null),
                'Telefone' => Mask::phone($customer['phone'] ?? null),
            ]),
            'samePerson' => Mask::sameTaxId($payerTaxId, data_get($customer, 'taxID.taxID')),
            'refunds' => collect($refunds)->map(fn (array $refund): array => array_filter([
                'valor' => $this->money($refund['value'] ?? null),
                'status' => $refund['status'] ?? null,
                'em' => $this->date($refund['time'] ?? null),
            ]))->all(),
            'refundsKnown' => $dispute->sourcePayload(DossierSource::WOOVI_REFUNDS) !== null,
            'sale' => $sale === null ? null : [
                'details' => array_filter([
                    'Pedido' => data_get($sale, 'order.id'),
                    'Valor do pedido' => $this->money(data_get($sale, 'amount_total')),
                    'Criado em' => $this->date(data_get($sale, 'created_at')),
                    'Pago em' => $this->date(data_get($sale, 'paid_at')),
                    'Tipo de produto' => data_get($sale, 'order.product_type'),
                    'Checkout feito do IP' => Mask::ip(data_get($sale, 'checkout.ip')),
                ]),
                'items' => collect((array) data_get($sale, 'order.items', []))
                    ->filter(fn (mixed $item): bool => is_array($item))
                    ->map(fn (array $item): array => [
                        'name' => (string) ($item['name'] ?? '—'),
                        'quantity' => (string) ($item['quantity'] ?? $item['qty'] ?? '1'),
                        'amount' => $this->money($item['unit_amount'] ?? $item['amount'] ?? null) ?? '—',
                    ])->values()->all(),
                'fulfillment' => array_filter([
                    'Situação da entrega' => data_get($sale, 'fulfillment.status'),
                    'Transportadora' => data_get($sale, 'fulfillment.carrier'),
                    'Código de rastreio' => data_get($sale, 'fulfillment.tracking_code'),
                    'Entregue em' => $this->date(data_get($sale, 'fulfillment.delivered_at')),
                ]),
            ],
            'sellerDetails' => array_filter([
                'Pedido informado pelo recebedor' => data_get($dispute->seller_details, 'order_reference'),
                'Rastreio informado pelo recebedor' => data_get($dispute->seller_details, 'tracking_code'),
            ]),
        ];
    }

    private function money(mixed $cents): ?string
    {
        return is_numeric($cents) ? 'R$ '.number_format(((float) $cents) / 100, 2, ',', '.') : null;
    }

    private function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return rescue(fn () => Carbon::parse($value)->timezone('America/Sao_Paulo')->format('d/m/Y H:i:s'), null, report: false);
    }
}
