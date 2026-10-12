<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\IngestDisputeAction;
use App\Actions\Med\MatchDossierAction;
use App\Actions\Med\RequestComplementAction;
use App\Actions\Med\SubmitSellerDefenseAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\SourceStatus;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use App\Models\User;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;

/**
 * Um teste por achado confirmado na revisão adversarial do dossiê (12/10/2026).
 */
class ReviewRegressionTest extends DossierTestCase
{
    private function refake(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeAll();
    }

    private function source(Dispute $dispute, DossierSource $source)
    {
        return $dispute->fresh()->sources->firstWhere('source', $source);
    }

    private function defend(Dispute $dispute, array $details = []): Dispute
    {
        return app(SubmitSellerDefenseAction::class)->handle(
            $dispute->fresh(), 'José Seller', 'Vendi o fone ao comprador, que retirou o produto com nota fiscal emitida.', [], [], $details,
        )->fresh();
    }

    private function enableAutoSend(): void
    {
        config(['med.envio_automatico.habilitado' => true]);
        $this->account->forceFill(['auto_send' => true])->save();
    }

    public function test_sources_that_waited_for_the_transaction_are_fetched_when_it_arrives(): void
    {
        $this->fakes['transaction'] = Http::response('indisponível', 503);
        $dispute = $this->receive();
        $this->assertSame(SourceStatus::SKIPPED, $this->source($dispute, DossierSource::WOOVI_CHARGE)->status);

        $this->fakes['transaction'] = $this->freshTransaction();
        $this->refake();
        $dispute = $this->defend($dispute);

        foreach ([DossierSource::WOOVI_CHARGE, DossierSource::WOOVI_REFUNDS, DossierSource::WOOVI_RECEIPT, DossierSource::UNICOPAG_SALE] as $source) {
            $this->assertSame(SourceStatus::OK, $this->source($dispute, $source)->status, $source->value);
        }
        $this->assertTrue($dispute->evidences()->where('kind', EvidenceKind::PIX_RECEIPT->value)->exists());
        $this->assertSame(MatchDossierAction::OK, $this->checks($dispute)['no_refund']['status']);
    }

    public function test_hourly_sync_retries_cases_with_a_failed_source(): void
    {
        $this->fakes['kyc'] = Http::response('erro', 500);
        $dispute = $this->receive();
        $this->assertSame(SourceStatus::FAILED, $this->source($dispute, DossierSource::KYC_STATUS)->status);

        $this->fakes['kyc'] = Http::response(['status' => 'approved']);
        $this->refake();
        Http::fake([
            'api.woovi.test/api/v1/dispute?*' => Http::response(['disputes' => [], 'pageInfo' => ['hasNextPage' => false]]),
            'api.woovi.test/api/v1/dispute/*' => Http::response(['dispute' => ['status' => 'OPENED', 'endToEndId' => self::E2E, 'type' => 'MED']]),
        ]);

        $this->artisan('med:sincronizar')->assertSuccessful();

        $this->assertSame(SourceStatus::OK, $this->source($dispute, DossierSource::KYC_STATUS)->status);
    }

    public function test_queue_failure_does_not_break_the_ingestion(): void
    {
        config(['queue.default' => 'fila-que-nao-existe']);
        $this->fakeAll();

        $dispute = app(IngestDisputeAction::class)->handle($this->account, ['status' => 'OPENED', 'endToEndId' => 'E-FILA', 'disputeReason' => 'Golpe']);

        $this->assertTrue($dispute->exists);
        Mail::assertSent(DisputeOpenedMail::class);
    }

    public function test_failed_refresh_keeps_the_data_that_was_already_ok(): void
    {
        $dispute = $this->receive();
        $factsBefore = MatchDossierAction::facts($dispute->dossier_match);

        $this->fakes['transaction'] = Http::response('timeout', 504);
        $this->refake();
        app(BuildDossierAction::class)->handle($dispute, refresh: true);

        $transaction = $this->source($dispute, DossierSource::WOOVI_TRANSACTION);
        $this->assertSame(SourceStatus::OK, $transaction->status);
        $this->assertNotNull($transaction->payload);
        $this->assertNotNull($transaction->error);
        $this->assertSame('partial', $dispute->fresh()->dossier_status);
        $this->assertSame($factsBefore, MatchDossierAction::facts($dispute->fresh()->dossier_match));
    }

    public function test_refund_made_after_the_med_arrived_blocks_the_auto_send(): void
    {
        $this->enableAutoSend();
        $dispute = $this->receive();

        $this->fakes['refunds'] = Http::response(['refunds' => [['status' => 'CONFIRMED', 'value' => 25000]]]);
        $this->refake();
        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertSame(MatchDossierAction::DIVERGENT, $this->checks($dispute)['no_refund']['status']);
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/evidence'));
    }

    public function test_seller_blocked_in_the_kyc_after_the_med_arrived_blocks_the_auto_send(): void
    {
        $this->enableAutoSend();
        $dispute = $this->receive();

        $this->fakes['kyc'] = Http::response(['status' => 'blocked']);
        $this->refake();
        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/evidence'));
    }

    public function test_refunds_that_could_not_be_reread_block_the_auto_send(): void
    {
        $this->enableAutoSend();
        $dispute = $this->receive();

        $this->fakes['refunds'] = Http::response('erro', 502);
        $this->refake();
        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('não relidas', $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole()->message);
    }

    public function test_answer_to_a_complement_the_analyst_asked_for_goes_to_the_analyst(): void
    {
        $this->enableAutoSend();
        $dispute = $this->receive();
        $dispute->forceFill(['status' => DisputeStatus::SELLER_SUBMITTED, 'seller_submitted_at' => now(), 'seller_statement' => 'Primeira defesa do seller.'])->save();
        app(RequestComplementAction::class)->handle($dispute->fresh(), 'Envie a nota fiscal assinada.');

        $this->refake();
        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('pediu complemento', $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole()->message);
    }

    public function test_unknown_claim_type_blocks_the_auto_send(): void
    {
        $this->enableAutoSend();
        // Conta sem a feature "MED API": a Woovi não diz a alegação.
        $this->fakes['funds'] = Http::response(['error' => 'Sem MED API'], 403);
        $dispute = $this->receive();
        $this->assertSame(SourceStatus::NOT_CONFIGURED, $this->source($dispute, DossierSource::WOOVI_FUNDS_RECOVERY)->status);

        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('situationType', $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole()->message);
    }

    public function test_claim_reason_mentioning_a_hijacked_account_blocks_the_auto_send(): void
    {
        $this->enableAutoSend();
        $dispute = $this->receive(['disputeReason' => 'Conta do pagador invadida']);

        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('conta invadida', $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole()->message);
    }

    public function test_pix_without_charge_never_claims_payer_and_customer_are_the_same(): void
    {
        $this->fakes['transaction'] = Http::response(['transaction' => [
            'value' => 25000, 'time' => '2026-10-10T15:30:00.000Z', 'endToEndId' => self::E2E, 'charge' => null,
            'payer' => ['name' => 'Fulano', 'taxID' => ['taxID' => self::PAYER_CPF]],
            // A Woovi cria o "customer" da transação a partir do próprio pagador.
            'customer' => ['name' => 'Fulano', 'taxID' => ['taxID' => self::PAYER_CPF]],
        ]]);

        $dispute = $this->receive();

        $this->assertSame(MatchDossierAction::MISSING, $this->checks($dispute)['payer_is_customer']['status']);
        $this->assertNotContains('O CPF/CNPJ de quem pagou é o mesmo do cliente cadastrado na cobrança.', MatchDossierAction::facts($dispute->dossier_match));
    }

    public function test_tracking_typed_by_the_seller_is_not_a_confirmed_fact(): void
    {
        config(['services.unicopag.sale_endpoint' => null]);
        $dispute = $this->defend($this->receive(), ['tracking_code' => 'x']);

        $this->assertSame(MatchDossierAction::OK, $this->checks($dispute)['delivery']['status']);
        $facts = MatchDossierAction::facts($dispute->dossier_match);
        $this->assertEmpty(array_filter($facts, fn (string $f): bool => str_contains($f, 'rastreio')));
    }

    public function test_presigned_url_changing_does_not_duplicate_the_platform_invoice(): void
    {
        $sale = $this->fakes['sale'];
        $this->fakes['sale'] = $this->saleWithInvoiceUrl('https://files.unicopag.test/nf-42.pdf?X-Amz-Signature=aaa');
        // Storage que responde octet-stream: o tipo vem dos bytes.
        $this->fakes['invoice'] = Http::response('%PDF-1.4 nota fiscal 42', 200, ['Content-Type' => 'application/octet-stream']);
        $dispute = $this->receive();
        $this->assertSame(1, $dispute->evidences()->where('kind', EvidenceKind::INVOICE->value)->count());

        $this->fakes['sale'] = $this->saleWithInvoiceUrl('https://files.unicopag.test/nf-42.pdf?X-Amz-Signature=bbb');
        $this->refake();
        app(BuildDossierAction::class)->handle($dispute, refresh: true);

        $this->assertSame(1, $dispute->evidences()->where('kind', EvidenceKind::INVOICE->value)->count());
    }

    public function test_documents_of_a_sale_that_does_not_match_the_pix_are_not_imported(): void
    {
        $this->fakes['sale'] = $this->saleWithInvoiceUrl('https://files.unicopag.test/nf-99.pdf', amount: 99900);

        $dispute = $this->receive();

        $this->assertSame(MatchDossierAction::DIVERGENT, $this->checks($dispute)['sale_amount_matches']['status']);
        $this->assertFalse($dispute->evidences()->where('kind', EvidenceKind::INVOICE->value)->exists());
    }

    public function test_discarded_evidence_is_not_sent_nor_brought_back_by_a_rebuild(): void
    {
        $dispute = $this->defend($this->receive());
        $invoice = $dispute->evidences()->where('kind', EvidenceKind::INVOICE->value)->sole();
        $this->actingAs(User::factory()->create());

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('discardEvidence', data: ['evidence_id' => $invoice->id, 'reason' => 'Nota de outro pedido.'])
            ->assertHasNoActionErrors();

        $this->refake();
        app(BuildDossierAction::class)->handle($dispute->fresh(), refresh: true);
        $this->assertFalse($dispute->evidences()->where('kind', EvidenceKind::INVOICE->value)->exists());

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])->callAction('sendToWoovi');
        $documents = collect(Http::recorded())->first(fn ($pair) => str_ends_with($pair[0]->url(), '/evidence'))[0]->data()['documents'];
        $this->assertFalse(collect($documents)->contains(fn (array $d): bool => str_contains($d['description'], 'Nota fiscal 42')));
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::EVIDENCE_DISCARDED->value]);
    }

    public function test_build_works_on_the_current_case_not_on_a_stale_copy(): void
    {
        config(['services.unicopag.sale_endpoint' => null]);
        $dispute = $this->receive();
        $stale = Dispute::query()->find($dispute->id);

        $this->defend($dispute, ['tracking_code' => 'QB123BR']);
        $this->refake();
        app(BuildDossierAction::class)->handle($stale);

        $this->assertSame(MatchDossierAction::OK, $this->checks($dispute)['delivery']['status']);
        $this->assertSame(MatchDossierAction::OK, $this->checks($dispute)['seller_statement']['status']);
    }

    private function freshTransaction()
    {
        return Http::response(['transaction' => [
            'value' => 25000, 'time' => '2026-10-10T15:30:00.000Z', 'endToEndId' => self::E2E, 'transactionID' => 'txid-42',
            'payer' => ['name' => 'Maria Compradora', 'taxID' => ['taxID' => self::PAYER_CPF]],
            'charge' => ['correlationID' => 'pedido-42', 'createdAt' => '2026-10-10T15:20:00.000Z'],
        ]]);
    }

    private function saleWithInvoiceUrl(string $url, int $amount = 25000)
    {
        return Http::response(['data' => [
            'amount_total' => $amount,
            'fulfillment' => ['status' => 'delivered'],
            'documents' => [['kind' => 'invoice', 'url' => $url, 'description' => 'Nota fiscal 42']],
        ]]);
    }
}
