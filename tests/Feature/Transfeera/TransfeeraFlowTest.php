<?php

namespace Tests\Feature\Transfeera;

use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\RegisterWooviWebhooksAction;
use App\Actions\Med\SendEvidenceToWooviAction;
use App\Actions\Med\SyncAccountDisputesAction;
use App\Actions\Med\SyncDisputeAction;
use App\Console\Commands\RespondDisputesCommand;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\Provider;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\WooviAccount;
use App\Services\Woovi\WooviException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/**
 * MED na Transfeera, de ponta a ponta com a API falsa: webhook e sincronização criam
 * o caso, o dossiê lê o Pix recebido e a resposta sai numa chamada só.
 */
class TransfeeraFlowTest extends TestCase
{
    use RefreshDatabase;

    private const E2E = 'E18236120202611201845s0133872GZA';

    private const INFRACTION_ID = '1eff300e-c113-6a7e-a8e4-f8c7d2c22fce';

    private const PAYER_CPF = '59136846058';

    private WooviAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Mail::fake();
        $this->account = WooviAccount::factory()->transfeera()->create(['unicopag_account_id' => '4321']);
    }

    /**
     * Exemplo de infração da doc da Transfeera.
     *
     * @return array<string, mixed>
     */
    private function infraction(array $overrides = []): array
    {
        return array_merge([
            'id' => self::INFRACTION_ID,
            'status' => 'pending',
            'analysis_status' => 'pending',
            'analysis_due_date' => now()->addDays(3)->toIso8601String(),
            'analysis_date' => null,
            'situation_type' => 'scam',
            'transaction_id' => self::E2E,
            'amount' => 25000,
            'infraction_date' => now()->subHours(2)->toIso8601String(),
            'infraction_description' => 'Cliente relata que comprou e não recebeu o produto.',
            'payer_name' => 'João da Silva',
            'payer_tax_id' => self::PAYER_CPF,
            'is_first_layer' => true,
            'txid' => '6e43085c2bee41cb83029bb898f90da6',
        ], $overrides);
    }

    private function postWebhook(array $data, ?string $secret = 'segredo-do-webhook', ?string $key = null)
    {
        $body = json_encode(['id' => 'evt-1', 'version' => 'v1', 'account_id' => 'acc-1', 'object' => 'Infraction', 'date' => now()->toIso8601String(), 'data' => $data]);
        $timestamp = (string) now()->getTimestampMs();
        $headers = ['CONTENT_TYPE' => 'application/json'];

        if ($secret !== null) {
            $headers['HTTP_TRANSFEERA_SIGNATURE'] = "t={$timestamp},v1=".hash_hmac('sha256', "{$timestamp}.{$body}", $secret);
        }

        return $this->call('POST', '/api/webhooks/transfeera/'.($key ?? $this->account->webhook_key), [], [], [], $headers, $body);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function fakeApi(array $extra = []): void
    {
        // Os fixos primeiro: um '*' em $extra não pode engolir o login e a infração.
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-abc', 'expires_in' => 1800]),
            'api.transfeera.test/med/infractions/'.self::INFRACTION_ID => Http::response($this->infraction()),
        ] + $extra);
    }

    private function openCase(array $attributes = []): Dispute
    {
        return Dispute::factory()->submitted()->create($attributes + [
            'woovi_account_id' => $this->account->id,
            'end_to_end_id' => self::E2E,
            'woovi_dispute_id' => self::INFRACTION_ID,
            'woovi_status' => 'PENDING',
            'value' => 25000,
            'defense_text' => 'A venda é legítima e o pedido está dentro do prazo de entrega.',
        ]);
    }

    public function test_webhook_opens_the_case_with_the_deadline_of_the_infraction(): void
    {
        $due = now()->addDays(3)->startOfSecond();

        $this->postWebhook($this->infraction(['analysis_due_date' => $due->toIso8601String()]))
            ->assertOk()
            ->assertJson(['received' => true]);

        $dispute = Dispute::sole();

        $this->assertSame($this->account->id, $dispute->woovi_account_id);
        $this->assertSame(Provider::TRANSFEERA, $dispute->provider());
        $this->assertSame(self::INFRACTION_ID, $dispute->woovi_dispute_id);
        $this->assertSame(self::E2E, $dispute->end_to_end_id);
        $this->assertSame('MED', $dispute->type);
        $this->assertSame('PENDING', $dispute->woovi_status);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $dispute->status);
        $this->assertSame(25000, $dispute->value);
        $this->assertSame('SCAM', $dispute->situation_type);
        $this->assertTrue($due->equalTo($dispute->respond_by));
        // O CPF de quem pagou não fica no caso em texto aberto.
        $this->assertStringNotContainsString(self::PAYER_CPF, json_encode($dispute->raw));
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::RECEIVED->value, 'source' => 'transfeera']);
    }

    public function test_webhook_without_valid_signature_is_refused(): void
    {
        $this->postWebhook($this->infraction(), secret: 'segredo-errado')->assertUnauthorized();
        $this->postWebhook($this->infraction(), secret: null)->assertUnauthorized();

        $this->assertSame(0, Dispute::count());
        $this->assertDatabaseHas('dispute_events', ['event' => DisputeEventType::WEBHOOK_REJECTED->value, 'source' => 'transfeera']);
    }

    public function test_each_webhook_route_only_serves_accounts_of_its_institution(): void
    {
        $woovi = WooviAccount::factory()->create();

        $this->postWebhook($this->infraction(), key: $woovi->webhook_key)->assertNotFound();
        $this->postJson('/api/webhooks/woovi/'.$this->account->webhook_key, ['event' => 'OPENPIX:DISPUTE_CREATED'])->assertNotFound();
    }

    public function test_agreed_means_the_seller_lost_and_disagreed_that_he_won(): void
    {
        $this->postWebhook($this->infraction())->assertOk();
        $this->postWebhook($this->infraction(['status' => 'agreed', 'analysis_status' => 'rejected']))->assertOk();

        $this->assertSame(DisputeStatus::ACCEPTED, Dispute::sole()->status);
        $this->assertNotNull(Dispute::sole()->closed_at);

        $this->postWebhook($this->infraction(['id' => 'outra-infracao', 'transaction_id' => 'E18236120202611201900s0133872GZB', 'status' => 'disagreed']))->assertOk();

        $this->assertSame(DisputeStatus::REJECTED, Dispute::query()->where('woovi_dispute_id', 'outra-infracao')->sole()->status);
    }

    public function test_infraction_already_answered_in_the_transfeera_panel_leaves_the_queue(): void
    {
        config(['med.dossie.habilitado' => true]);
        Http::fake();

        $this->postWebhook($this->infraction(['analysis_status' => 'rejected']))->assertOk();

        $dispute = Dispute::sole();

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $dispute->status);
        // Não foi este sistema que respondeu: fica fora do placar.
        $this->assertNull($dispute->evidence_sent_at);
        $this->assertNull($dispute->seller_notified_at);
        Http::assertNothingSent();
    }

    public function test_sync_lists_the_infractions_following_the_cursor_and_authenticates(): void
    {
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-abc', 'expires_in' => 1800]),
            'api.transfeera.test/med/infractions*' => Http::sequence()
                ->push(['items' => [$this->infraction()], 'meta' => ['next' => 'cursor-2']])
                ->push(['items' => [$this->infraction(['id' => 'infracao-2', 'transaction_id' => 'E18236120202611201900s0133872GZB'])], 'meta' => ['next' => null]]),
        ]);

        $result = app(SyncAccountDisputesAction::class)->handle($this->account, 14);

        $this->assertSame(['seen' => 2, 'created' => 2], $result);
        $this->assertSame(2, Dispute::count());
        $this->assertNotNull($this->account->fresh()->last_synced_at);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/authorization')
            && $request['grant_type'] === 'client_credentials'
            && filled($request['client_id']) && filled($request['client_secret'])
            && $request->header('User-Agent')[0] === 'Unico Pag (med@unicopag.test)');
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'page_cursor=cursor-2')
            && $request->hasHeader('Authorization', 'Bearer token-abc')
            && $request->header('User-Agent')[0] === 'Unico Pag (med@unicopag.test)');
        Http::assertSentCount(3);
    }

    public function test_subaccount_asks_the_token_with_its_scope(): void
    {
        $this->account->update(['woovi_company_id' => '806cafe8-f245-4aee-8785-2f385604454f']);
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-sub', 'expires_in' => 1800]),
            'api.transfeera.test/med/infractions*' => Http::response(['items' => [], 'meta' => []]),
        ]);

        app(SyncAccountDisputesAction::class)->handle($this->account->fresh(), 14);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/authorization')
            && $request['scope'] === 'account_id:806cafe8-f245-4aee-8785-2f385604454f');
    }

    public function test_rereading_a_case_applies_the_final_decision(): void
    {
        $dispute = $this->openCase(['status' => DisputeStatus::EVIDENCE_SENT, 'evidence_sent_at' => now()]);
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-abc', 'expires_in' => 1800]),
            'api.transfeera.test/med/infractions/'.self::INFRACTION_ID => Http::response($this->infraction(['status' => 'disagreed', 'analysis_status' => 'rejected'])),
        ]);

        $dispute = app(SyncDisputeAction::class)->handle($dispute);

        $this->assertSame(DisputeStatus::REJECTED, $dispute->status);
        $this->assertSame('DISAGREED', $dispute->woovi_status);
        // Caso respondido por este sistema continua no placar.
        $this->assertNotNull($dispute->evidence_sent_at);
    }

    public function test_answer_is_one_multipart_call_rejecting_the_infraction(): void
    {
        $dispute = $this->openCase();
        DisputeEvidence::factory()->for($dispute)->create();
        DisputeEvidence::factory()->for($dispute)->create(['original_name' => 'conversa.webp', 'mime_type' => 'image/webp']);
        $this->fakeApi(['api.transfeera.test/med/infractions/*/analysis' => Http::response($this->infraction(['analysis_status' => 'rejected']))]);

        $sent = app(SendEvidenceToWooviAction::class)->handle($dispute, userId: null);

        // A carta de defesa + a nota; o WEBP não é aceito pela Transfeera e fica fora.
        $this->assertSame(2, $sent);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/med/infractions/'.self::INFRACTION_ID.'/analysis')) {
                return false;
            }

            $parts = collect($request->data());
            $field = fn (string $name) => $parts->firstWhere('name', $name)['contents'] ?? null;
            $files = $parts->where('name', 'attachments')->values();

            return $request->isMultipart()
                && $request->method() === 'POST'
                && $field('analysis') === 'rejected'
                && $field('analysis_description') === 'A venda é legítima e o pedido está dentro do prazo de entrega.'
                && $files->count() === 2
                && str_starts_with($files[0]['filename'], 'carta-de-defesa-')
                && $files[1]['filename'] === 'nota.pdf';
        });

        $dispute->refresh();
        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $dispute->status);
        $this->assertNotNull($dispute->evidence_sent_at);
        $this->assertSame(1, $dispute->evidences()->whereNull('sent_at')->count());
        $this->assertNotNull($dispute->evidences()->where('kind', EvidenceKind::DEFENSE_LETTER->value)->sole()->sent_at);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::EVIDENCE_SENT->value]);
    }

    public function test_infraction_already_analyzed_is_never_answered_again(): void
    {
        $dispute = $this->openCase();
        DisputeEvidence::factory()->for($dispute)->create();
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-abc', 'expires_in' => 1800]),
            'api.transfeera.test/med/infractions/'.self::INFRACTION_ID => Http::response($this->infraction(['analysis_status' => 'accepted'])),
        ]);

        try {
            app(SendEvidenceToWooviAction::class)->handle($dispute, userId: null);
            $this->fail('Deveria recusar.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('já tem uma análise', $e->getMessage());
        }

        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/analysis'));
        $this->assertNull($dispute->fresh()->evidence_sent_at);
    }

    public function test_refused_answer_keeps_the_case_open_and_the_evidence_unsent(): void
    {
        $dispute = $this->openCase();
        DisputeEvidence::factory()->for($dispute)->create();
        $this->fakeApi(['api.transfeera.test/med/infractions/*/analysis' => Http::response([
            'code' => 'payload_invalid', 'message' => 'The payload is invalid.',
            'errors' => [['message' => 'analysis_description is too long', 'field' => 'analysis_description']],
        ], 400)]);

        try {
            app(SendEvidenceToWooviAction::class)->handle($dispute, userId: null);
            $this->fail('Deveria falhar.');
        } catch (WooviException $e) {
            $this->assertSame(400, $e->status);
            $this->assertStringContainsString('analysis_description is too long', $e->getMessage());
        }

        $dispute->refresh();
        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertSame(0, $dispute->evidences()->whereNotNull('sent_at')->count());
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::EVIDENCE_SEND_FAILED->value, 'level' => 'error']);
    }

    public function test_answer_registered_despite_the_error_counts_as_sent(): void
    {
        $dispute = $this->openCase();
        DisputeEvidence::factory()->for($dispute)->create();
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-abc', 'expires_in' => 1800]),
            // Antes do envio: pendente. Depois do erro: a análise ficou registrada.
            'api.transfeera.test/med/infractions/'.self::INFRACTION_ID => Http::sequence()
                ->push($this->infraction())
                ->push($this->infraction(['analysis_status' => 'rejected'])),
            'api.transfeera.test/med/infractions/*/analysis' => Http::response(['message' => 'Gateway Timeout'], 504),
        ]);

        $this->assertSame(2, app(SendEvidenceToWooviAction::class)->handle($dispute, userId: null));
        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $dispute->fresh()->status);
    }

    public function test_long_defense_is_cut_and_points_to_the_letter(): void
    {
        config(['services.transfeera.description_max' => 300]);
        $dispute = $this->openCase(['defense_text' => str_repeat('Venda legítima. ', 100)]);
        DisputeEvidence::factory()->for($dispute)->create();
        $this->fakeApi(['api.transfeera.test/med/infractions/*/analysis' => Http::response($this->infraction(['analysis_status' => 'rejected']))]);

        app(SendEvidenceToWooviAction::class)->handle($dispute, userId: null);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/analysis')) {
                return false;
            }

            $text = collect($request->data())->firstWhere('name', 'analysis_description')['contents'];

            return mb_strlen($text) <= 300 && str_ends_with($text, 'carta de defesa anexa.');
        });
    }

    public function test_registering_the_webhook_stores_the_signing_secret(): void
    {
        $this->account->update(['webhook_secret' => null, 'webhooks_registered_at' => null]);
        Http::fake([
            'login.transfeera.test/authorization' => Http::response(['access_token' => 'token-abc', 'expires_in' => 1800]),
            'api.transfeera.test/webhook' => function (Request $request) {
                return $request->method() === 'GET'
                    ? Http::response([['id' => 'outro', 'url' => 'https://outro.sistema/webhook', 'object_types' => ['Transfer'], 'signature_secret' => 'de-outro']])
                    : Http::response(['id' => 'wh-1', 'signature_secret' => 'segredo-novo']);
            },
        ]);

        $created = app(RegisterWooviWebhooksAction::class)->handle($this->account->fresh());

        $this->assertSame(['Infraction'], $created);
        $this->assertSame('segredo-novo', $this->account->fresh()->webhook_secret);
        $this->assertNotNull($this->account->fresh()->webhooks_registered_at);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST' && str_ends_with($request->url(), '/webhook')
            && $request['url'] === $this->account->webhookUrl() && $request['object_types'] === ['Infraction']);
        // O segredo não fica em texto aberto no banco.
        $this->assertStringNotContainsString('segredo-novo', (string) $this->account->fresh()->getRawOriginal('webhook_secret'));
    }

    public function test_dossier_reads_the_received_pix_and_checks_the_payer_against_the_order(): void
    {
        config([
            'services.unicopag.key' => 'chave-med',
            'services.unicopag.sale_endpoint' => '/api/med/sales/lookup?correlation_id={correlation_id}&end_to_end_id={end_to_end_id}&txid={txid}',
        ]);
        $dispute = $this->openCase(['status' => DisputeStatus::PENDING_SELLER, 'seller_submitted_at' => null, 'seller_statement' => null]);

        $this->fakeApi([
            'api.transfeera.test/pix/cashin/*/refund' => Http::response(['entries' => []]),
            'api.transfeera.test/pix/cashin/*' => Http::response([
                'id' => '7109aea8', 'value' => 250.00, 'type' => 'DEPOSIT', 'end2end_id' => self::E2E,
                'txid' => '6e43085c2bee41cb83029bb898f90da6', 'integration_id' => 'pedido-42',
                'receipt_file_url' => 'https://api.transfeera.com/pub/receipt/abc',
                'payer' => ['name' => 'João da Silva', 'document' => '***.368.460-**', 'bank' => ['name' => 'Banco ltda']],
                'receiver' => ['name' => 'UNICO PAG LTDA'],
            ]),
            'api.transfeera.com/pub/receipt/*' => Http::response('%PDF-1.4 comprovante transfeera', 200, ['Content-Type' => 'application/pdf']),
            'unico-api.test/api/med/sales/lookup*' => Http::response(['data' => [
                'matched_by' => 'correlation_id', 'account_id' => '4321', 'amount_total' => 25000, 'payment_status' => 'paid',
                'paid_at' => '2026-11-20T18:45:00.000Z',
                'customer' => ['name' => 'João da Silva', 'document' => '591.368.460-58'],
                'order' => ['id' => '42', 'items' => [['name' => 'Fone bluetooth X1', 'quantity' => 1, 'unit_amount' => 25000]]],
                'fulfillment' => ['status' => 'shipped', 'tracking_code' => 'QB123456789BR'],
            ]]),
            '*' => Http::response([], 404),
        ]);

        $dispute = app(BuildDossierAction::class)->handle($dispute);

        $transaction = $dispute->sourcePayload(DossierSource::WOOVI_TRANSACTION);
        $this->assertSame(25000, $transaction['value']);
        $this->assertSame('2026-11-20T18:45:00+00:00', $transaction['time']);
        // O documento do Pix veio mascarado: vale o da infração.
        $this->assertSame(self::PAYER_CPF, $transaction['payer']['taxID']['taxID']);
        $this->assertSame('SCAM', $dispute->sourcePayload(DossierSource::WOOVI_FUNDS_RECOVERY)['situationType']);
        $this->assertSame(['refunds' => []], $dispute->sourcePayload(DossierSource::WOOVI_REFUNDS));

        $checks = collect($dispute->dossier_match['checks'])->keyBy('key');
        foreach (['transaction_found', 'value_matches', 'charge_linked', 'payer_is_customer', 'no_refund', 'sale_found', 'sale_amount_matches', 'delivery'] as $key) {
            $this->assertSame('ok', $checks[$key]['status'], $key);
        }
        $this->assertSame('Transação localizada na Transfeera', $checks['transaction_found']['label']);
        $this->assertSame('O CPF/CNPJ de quem pagou é o mesmo do cliente cadastrado no pedido.', $checks['payer_is_customer']['fact']);
        $this->assertTrue($dispute->dossier_match['ready']);

        $receipt = $dispute->evidences()->where('kind', EvidenceKind::PIX_RECEIPT->value)->sole();
        $this->assertSame('Comprovante emitido pela Transfeera, instituição do recebedor', $receipt->description);
        $this->assertTrue($dispute->evidences()->where('kind', EvidenceKind::SALE_RECORD->value)->exists());

        // A venda foi buscada pelo integration_id e pelo txid do Pix.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'sales/lookup')
            && str_contains($request->url(), 'correlation_id=pedido-42')
            && str_contains($request->url(), 'txid=6e43085c2bee41cb83029bb898f90da6'));
    }

    public function test_payer_different_from_the_order_customer_is_a_divergence(): void
    {
        config(['services.unicopag.key' => 'chave-med', 'services.unicopag.sale_endpoint' => '/api/med/sales/lookup?end_to_end_id={end_to_end_id}']);
        $dispute = $this->openCase();

        $this->fakeApi([
            'api.transfeera.test/pix/cashin/*/refund' => Http::response(['entries' => [['type' => 'DEPOSIT_REFUND', 'value' => -250, 'error_code' => null, 'return_id' => 'D1']]]),
            'api.transfeera.test/pix/cashin/*' => Http::response(['value' => 250, 'end2end_id' => self::E2E, 'txid' => '', 'payer' => ['document' => self::PAYER_CPF]]),
            'unico-api.test/api/med/sales/lookup*' => Http::response(['data' => ['amount_total' => 25000, 'customer' => ['document' => '12345678909']]]),
            '*' => Http::response([], 404),
        ]);

        $checks = collect(app(BuildDossierAction::class)->handle($dispute)->dossier_match['checks'])->keyBy('key');

        $this->assertSame('divergent', $checks['payer_is_customer']['status']);
        // Devolução já feita na Transfeera trava como na Woovi.
        $this->assertSame('divergent', $checks['no_refund']['status']);
        // Sem txid, o Pix é avulso.
        $this->assertSame('missing', $checks['charge_linked']['status']);
    }

    public function test_batch_answer_uses_the_deadline_of_the_infraction(): void
    {
        config(['med.resposta_automatica.incluir_nao_defender' => true]);
        // Aberto há 10 dias: o prazo de 96h da Woovi não se aplica aqui.
        $open = $this->openCase(['opened_at' => now()->subDays(10), 'respond_by' => now()->addDay()]);
        $late = $this->openCase(['woovi_dispute_id' => 'vencida', 'opened_at' => now()->subDay(), 'respond_by' => now()->subHour()]);
        $delayed = $this->openCase(['woovi_dispute_id' => 'atrasada', 'respond_by' => now()->addDay(), 'raw' => ['analysisStatus' => 'delayed']]);

        $this->assertNotContains('prazo da Woovi expirado', RespondDisputesCommand::blockers($open, true));
        $this->assertNotContains('prazo da Transfeera expirado', RespondDisputesCommand::blockers($open, true));
        $this->assertContains('prazo da Transfeera expirado', RespondDisputesCommand::blockers($late, true));
        $this->assertContains('análise na Transfeera não está mais pendente (delayed)', RespondDisputesCommand::blockers($delayed, true));
    }
}
