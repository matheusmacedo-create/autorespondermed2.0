<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\GenerateSaleRecordAction;
use App\Actions\Med\MatchDossierAction;
use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\SourceStatus;
use App\Mail\DisputeOpenedMail;
use App\Models\DisputeSource;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

class BuildDossierTest extends DossierTestCase
{
    public function test_dispute_received_builds_the_dossier_before_the_seller_answers(): void
    {
        $dispute = $this->receive();

        $statuses = $dispute->sources->mapWithKeys(fn (DisputeSource $s): array => [$s->source->value => $s->status]);
        $this->assertCount(7, $statuses);
        $statuses->each(fn (SourceStatus $status, string $source) => $this->assertSame(SourceStatus::OK, $status, $source));

        // O GET de MED não traz valor: veio da transação.
        $this->assertSame(25000, $dispute->value);
        $this->assertSame('ready', $dispute->dossier_status);
        $this->assertTrue($dispute->dossier_match['ready']);

        $kinds = $dispute->evidences->pluck('kind')->all();
        $this->assertContains(EvidenceKind::PIX_RECEIPT, $kinds);
        $this->assertContains(EvidenceKind::SALE_RECORD, $kinds);
        $this->assertContains(EvidenceKind::INVOICE, $kinds);
        $this->assertTrue($dispute->evidences->every(fn ($e): bool => $e->uploaded_by === 'system' && $e->sent_at === null));

        $checks = $this->checks($dispute);
        foreach (['transaction_found', 'value_matches', 'charge_linked', 'charge_before_payment', 'payer_is_customer', 'no_refund', 'sale_found', 'sale_amount_matches', 'delivery', 'kyc_approved'] as $key) {
            $this->assertSame(MatchDossierAction::OK, $checks[$key]['status'], $key);
        }

        // Seller avisado como antes; o dossiê não atrapalha a ingestão.
        Mail::assertSent(DisputeOpenedMail::class);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::DOSSIER_BUILT->value]);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::DOSSIER_MATCHED->value, 'level' => 'info']);

        // A venda é buscada pelo correlationID que a transação trouxe, com a chave do MED.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'unico-api.test/api/med/sales/lookup')
            && str_contains($r->url(), 'account_id=4321')
            && str_contains($r->url(), 'correlation_id=pedido-42')
            && $r->header('X-API-Key')[0] === 'chave-med');
    }

    public function test_payer_data_is_stored_encrypted(): void
    {
        $this->receive();

        $raw = DB::table('dispute_sources')->where('source', DossierSource::WOOVI_TRANSACTION->value)->value('payload');

        $this->assertStringNotContainsString(self::PAYER_CPF, $raw);
        $this->assertStringNotContainsString('Maria Compradora', $raw);
    }

    public function test_sale_record_masks_personal_data(): void
    {
        $dispute = $this->receive();

        $data = app(GenerateSaleRecordAction::class)->data($dispute->fresh());

        $this->assertSame('***.456.789-**', $data['payer']['CPF/CNPJ']);
        $this->assertSame('ma***@exemplo.com', $data['customer']['E-mail']);
        $this->assertSame('189.10.20.***', $data['sale']['details']['Checkout feito do IP']);
        $this->assertTrue($data['samePerson']);
        $this->assertStringNotContainsString(self::PAYER_CPF, json_encode($data));
    }

    public function test_one_source_failing_does_not_stop_the_others_or_the_ingestion(): void
    {
        $this->fakes['transaction'] = Http::response(['error' => 'Forbidden'], 403);

        $dispute = $this->receive();

        $statuses = $dispute->sources->mapWithKeys(fn (DisputeSource $s): array => [$s->source->value => $s->status]);
        $this->assertSame(SourceStatus::FAILED, $statuses['woovi_transaction']);
        $this->assertSame(SourceStatus::SKIPPED, $statuses['woovi_charge']);
        $this->assertSame(SourceStatus::OK, $statuses['unicopag_register']);
        $this->assertSame(SourceStatus::OK, $statuses['kyc_status']);
        $this->assertSame('failed', $dispute->dossier_status);
        $this->assertFalse($dispute->dossier_match['ready']);

        Mail::assertSent(DisputeOpenedMail::class);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::DOSSIER_SOURCE_FAILED->value, 'level' => 'warning']);
    }

    public function test_failed_source_is_fetched_again_and_ok_sources_are_not(): void
    {
        $this->fakes['kyc'] = Http::response('erro', 500);
        $dispute = $this->receive();
        $this->assertSame(SourceStatus::FAILED, $dispute->sources->firstWhere('source', DossierSource::KYC_STATUS)->status);

        $this->fakes['kyc'] = Http::response(['status' => 'approved']);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeAll();

        app(BuildDossierAction::class)->handle($dispute->fresh());

        $this->assertSame(SourceStatus::OK, $dispute->fresh()->sources->firstWhere('source', DossierSource::KYC_STATUS)->status);
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/transaction/'));
    }

    public function test_pix_without_charge_skips_charge_sources_and_blocks_auto_send_checks(): void
    {
        $this->fakes['transaction'] = Http::response(['transaction' => [
            'value' => 25000, 'time' => '2026-10-10T15:30:00.000Z', 'endToEndId' => self::E2E,
            'payer' => ['name' => 'Fulano', 'taxID' => ['taxID' => self::PAYER_CPF]], 'charge' => null,
        ]]);

        $dispute = $this->receive();
        $checks = $this->checks($dispute);

        $this->assertSame(SourceStatus::SKIPPED, $dispute->sources->firstWhere('source', DossierSource::WOOVI_CHARGE)->status);
        $this->assertSame(MatchDossierAction::MISSING, $checks['charge_linked']['status']);
        $this->assertSame(MatchDossierAction::MISSING, $checks['no_refund']['status']);
    }

    public function test_payer_different_from_customer_is_a_blocking_divergence(): void
    {
        $charge = $this->fakes['charge'];
        $this->fakes['charge'] = Http::response(['charge' => [
            'correlationID' => 'pedido-42', 'createdAt' => '2026-10-10T15:20:00.000Z',
            'customer' => ['name' => 'Outra Pessoa', 'taxID' => ['taxID' => '98765432100']],
        ]]);

        $dispute = $this->receive();

        $this->assertSame(MatchDossierAction::DIVERGENT, $this->checks($dispute)['payer_is_customer']['status']);
        $this->assertFalse($dispute->dossier_match['ready']);
        $this->assertContains('payer_is_customer', $dispute->dossier_match['divergent']);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::DOSSIER_MATCHED->value, 'level' => 'warning']);
    }

    public function test_refund_already_made_is_a_blocking_divergence(): void
    {
        $this->fakes['refunds'] = Http::response(['refunds' => [['status' => 'CONFIRMED', 'value' => 25000, 'time' => '2026-10-11T09:00:00.000Z']]]);

        $dispute = $this->receive();

        $this->assertSame(MatchDossierAction::DIVERGENT, $this->checks($dispute)['no_refund']['status']);
        $this->assertFalse($dispute->dossier_match['ready']);
    }

    public function test_without_the_platform_sale_route_the_dossier_works_with_woovi_only(): void
    {
        config(['services.unicopag.sale_endpoint' => null]);

        $dispute = $this->receive();

        $this->assertSame(SourceStatus::NOT_CONFIGURED, $dispute->sources->firstWhere('source', DossierSource::UNICOPAG_SALE)->status);
        $this->assertSame(MatchDossierAction::MISSING, $this->checks($dispute)['sale_found']['status']);
        // A venda na plataforma não bloqueia: a prova da Woovi basta para "pronto".
        $this->assertTrue($dispute->dossier_match['ready']);
        $this->assertFalse($dispute->evidences->contains('kind', EvidenceKind::INVOICE));
    }

    public function test_letter_carries_only_confirmed_facts(): void
    {
        $this->fakes['refunds'] = Http::response(['refunds' => [['status' => 'CONFIRMED', 'value' => 100]]]);
        $dispute = $this->receive();

        $facts = MatchDossierAction::facts($dispute->dossier_match);

        $this->assertContains('O CPF/CNPJ de quem pagou é o mesmo do cliente cadastrado na cobrança.', $facts);
        $this->assertContains('A entrega do pedido está registrada na plataforma.', $facts);
        // Divergência (devolução já feita) nunca vira frase da carta.
        $this->assertEmpty(array_filter($facts, fn (string $f): bool => str_contains(mb_strtolower($f), 'devolu')));
    }

    public function test_closed_case_and_disabled_option_build_nothing(): void
    {
        config(['med.dossie.habilitado' => false]);
        $dispute = $this->receive();
        $this->assertSame(0, $dispute->sources()->count());

        config(['med.dossie.habilitado' => true]);
        $closed = $this->receive(['endToEndId' => 'E-FECHADO', 'status' => 'REJECTED']);
        $this->assertSame(0, $closed->sources()->count());
    }

    public function test_rebuild_does_not_duplicate_documents_that_did_not_change(): void
    {
        $dispute = $this->receive();
        $before = $dispute->evidences()->count();

        app(BuildDossierAction::class)->handle($dispute->fresh(), refresh: true);

        $this->assertSame($before, $dispute->evidences()->count());
    }

    public function test_sync_command_requests_the_dossier_of_open_cases_without_one(): void
    {
        config(['med.dossie.habilitado' => false]);
        $dispute = $this->receive();
        $this->assertNull($dispute->dossier_built_at);

        config(['med.dossie.habilitado' => true]);
        Http::fake([
            'api.woovi.test/api/v1/dispute?*' => Http::response(['disputes' => [], 'pageInfo' => ['hasNextPage' => false]]),
            'api.woovi.test/api/v1/dispute/*' => Http::response(['dispute' => ['status' => 'OPENED', 'endToEndId' => self::E2E, 'type' => 'MED']]),
        ]);

        $this->artisan('med:sincronizar')->assertSuccessful();

        $this->assertNotNull($dispute->fresh()->dossier_built_at);
    }

    public function test_manual_command_builds_the_dossier(): void
    {
        config(['med.dossie.habilitado' => false]);
        $dispute = $this->receive();

        $this->artisan('med:montar-dossie', ['--caso' => [$dispute->id]])
            ->expectsOutputToContain('conferência pronta para envio')
            ->assertSuccessful();
    }
}
