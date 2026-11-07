<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\IngestDisputeAction;
use App\Models\Dispute;
use App\Models\WooviAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Base dos testes do dossiê: Woovi, unico-api, KYC e o arquivo da nota fiscal
 * falsos, com uma venda "boa" (pagador = cliente, entregue, sem devolução, KYC ok).
 * Cada teste troca só o pedaço que quer quebrar.
 */
abstract class DossierTestCase extends TestCase
{
    use RefreshDatabase;

    protected const E2E = 'E18236120202610121530s0133872GZA';

    protected const PAYER_CPF = '12345678909';

    protected WooviAccount $account;

    /**
     * @var array<string, mixed>
     */
    protected array $fakes = [];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();

        config([
            'med.dossie.habilitado' => true,
            'services.unicopag.key' => 'chave-med',
            'services.unicopag.sale_endpoint' => '/api/med/sales/lookup?account_id={account_id}&correlation_id={correlation_id}&end_to_end_id={end_to_end_id}',
        ]);

        $this->account = WooviAccount::factory()->create(['unicopag_account_id' => '4321']);

        $this->fakes = [
            'transaction' => Http::response(['transaction' => [
                'value' => 25000,
                'time' => '2026-10-10T15:30:00.000Z',
                'endToEndId' => self::E2E,
                'transactionID' => 'txid-42',
                'infoPagador' => 'Pedido 42',
                'type' => 'PAYMENT',
                'payer' => ['name' => 'Maria Compradora', 'taxID' => ['taxID' => self::PAYER_CPF, 'type' => 'BR:CPF']],
                'charge' => ['correlationID' => 'pedido-42', 'status' => 'COMPLETED', 'createdAt' => '2026-10-10T15:20:00.000Z'],
            ]]),
            'refunds' => Http::response(['refunds' => []]),
            'charge' => Http::response(['charge' => [
                'value' => 25000,
                'correlationID' => 'pedido-42',
                'comment' => 'Fone bluetooth',
                'createdAt' => '2026-10-10T15:20:00.000Z',
                'customer' => ['name' => 'Maria Compradora', 'email' => 'maria@exemplo.com', 'taxID' => ['taxID' => self::PAYER_CPF, 'type' => 'BR:CPF']],
                'additionalInfo' => [['key' => 'Produto', 'value' => 'Fone bluetooth X1']],
            ]]),
            'receipt' => Http::response('%PDF-1.4 comprovante woovi', 200, ['Content-Type' => 'application/pdf']),
            // Formato da doc funds-recovery-disputes-api: uma lista.
            'funds' => Http::response([[
                'type' => 'MED', 'status' => 'IN_REVIEW', 'sentOrReceived' => 'RECEIVED', 'situationType' => 'SCAM',
                'value' => 25000, 'endToEndId' => self::E2E, 'disputeReason' => 'Pagamento apontado como parte de um golpe.',
            ]]),
            'sale' => Http::response(['data' => [
                'matched_by' => 'correlation_id',
                'amount_total' => 25000,
                'payment_status' => 'paid',
                'created_at' => '2026-10-10T15:19:00.000Z',
                'paid_at' => '2026-10-10T15:30:00.000Z',
                'order' => ['id' => '42', 'items' => [['name' => 'Fone bluetooth X1', 'quantity' => 1, 'unit_amount' => 25000]], 'product_type' => 'physical'],
                'checkout' => ['ip' => '189.10.20.30'],
                'fulfillment' => ['status' => 'delivered', 'carrier' => 'Correios', 'tracking_code' => 'QB123456789BR', 'delivered_at' => '2026-10-11T10:00:00.000Z'],
                'documents' => [['kind' => 'invoice', 'url' => 'https://files.unicopag.test/nf-42.pdf', 'description' => 'Nota fiscal 42']],
            ]]),
            'invoice' => Http::response('%PDF-1.4 nota fiscal 42', 200, ['Content-Type' => 'application/pdf']),
            'register' => Http::response(['data' => ['account_id' => '4321', 'register' => ['trade_name' => 'Loja do Zé']]]),
            'kyc' => Http::response(['success' => true, 'status' => 'approved']),
        ];
    }

    protected function fakeAll(): void
    {
        $f = $this->fakes;

        // Ordem importa: o padrão mais específico vem antes (refund antes de charge).
        Http::fake([
            'api.woovi.test/api/v1/transaction/*' => $f['transaction'],
            'api.woovi.test/api/v1/charge/*/refund' => $f['refunds'],
            'api.woovi.test/api/v1/charge/*' => $f['charge'],
            'api.woovi.test/api/v1/receipt/pix-in/*' => $f['receipt'],
            'api.woovi.test/api/v1/funds-recovery/*/disputes' => $f['funds'],
            'api.woovi.test/api/v1/files' => Http::sequence()
                ->push(['file' => ['id' => 'file-1']], 201)->push(['file' => ['id' => 'file-2']], 201)
                ->push(['file' => ['id' => 'file-3']], 201)->push(['file' => ['id' => 'file-4']], 201)
                ->push(['file' => ['id' => 'file-5']], 201)->push(['file' => ['id' => 'file-6']], 201),
            'api.woovi.test/api/v1/dispute/*/evidence' => Http::response(['documents' => []]),
            'unico-api.test/api/med/sales/lookup*' => $f['sale'],
            'unico-api.test/api/kyc-panel/accounts/*/register' => $f['register'],
            'files.unicopag.test/*' => $f['invoice'],
            'kyc.test/api/onboarding/status*' => $f['kyc'],
            'kyc.test/api/med/stores/*' => $f['kyc_store'] ?? Http::response([], 404),
        ]);
    }

    /**
     * MED chegando como o webhook da Woovi manda (sem valor, como o GET de MED).
     */
    protected function receive(array $payload = []): Dispute
    {
        $this->fakeAll();

        return app(IngestDisputeAction::class)->handle($this->account, array_merge([
            'status' => 'OPENED',
            'endToEndId' => self::E2E,
            'disputeReason' => 'Golpe',
        ], $payload))->fresh();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    protected function checks(Dispute $dispute): array
    {
        return collect((array) data_get($dispute->fresh()->dossier_match, 'checks', []))->keyBy('key')->all();
    }
}
