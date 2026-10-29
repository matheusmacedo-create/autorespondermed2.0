<?php

namespace Tests\Feature\Console;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use App\Models\DisputeEvent;
use App\Models\WooviAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MedCommandsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_sync_picks_up_a_dispute_the_webhook_missed_and_closes_a_decided_one(): void
    {
        $account = WooviAccount::factory()->create();
        $open = Dispute::factory()->for($account)->create(['status' => DisputeStatus::EVIDENCE_SENT]);

        Http::fake([
            'api.woovi.test/api/v1/dispute?*' => Http::response([
                'disputes' => [
                    ['status' => 'IN_REVIEW', 'endToEndId' => 'E-NOVO-1', 'value' => 5000, 'disputeReason' => 'Golpe', 'type' => 'MED', 'createdAt' => now()->subHour()->toIso8601String()],
                ],
                'pageInfo' => ['hasNextPage' => false],
            ]),
            'api.woovi.test/api/v1/dispute/E-NOVO-1' => Http::response(['dispute' => ['status' => 'IN_REVIEW', 'endToEndId' => 'E-NOVO-1', 'type' => 'MED']]),
            'api.woovi.test/api/v1/dispute/'.$open->end_to_end_id => Http::response(['dispute' => ['status' => 'REJECTED', 'endToEndId' => $open->end_to_end_id, 'type' => 'MED', 'id' => '67e3170b38e62d5935ed4faf']]),
        ]);

        $this->artisan('med:sincronizar')->assertSuccessful();

        $new = Dispute::query()->where('end_to_end_id', 'E-NOVO-1')->sole();
        $this->assertSame(DisputeStatus::PENDING_SELLER, $new->status);
        $this->assertSame(5000, $new->value);
        Mail::assertSent(DisputeOpenedMail::class);

        $open->refresh();
        $this->assertSame(DisputeStatus::REJECTED, $open->status);
        $this->assertSame('67e3170b38e62d5935ed4faf', $open->woovi_dispute_id);
        $this->assertNotNull($account->fresh()->last_synced_at);

        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/dispute?')
            && isset($request->data()['start'], $request->data()['end']));
    }

    public function test_med_and_chargeback_of_the_same_pix_are_two_cases_that_stay_put(): void
    {
        WooviAccount::factory()->create();
        $med = ['id' => 'med-1', 'type' => 'MED', 'status' => 'REJECTED', 'endToEndId' => 'E-PIX-1', 'value' => 19391, 'createdAt' => now()->subDays(4)->toIso8601String()];
        $chargeback = ['id' => 'cb-1', 'type' => 'CHARGEBACK', 'status' => 'OPENED', 'endToEndId' => 'E-PIX-1', 'value' => 19391, 'createdAt' => now()->subDay()->toIso8601String()];

        Http::fake([
            'api.woovi.test/api/v1/dispute?*' => Http::response(['disputes' => [$med, $chargeback], 'pageInfo' => ['hasNextPage' => false]]),
            // O GET por endToEndId devolve a disputa mais antiga do Pix, seja qual for o caso.
            'api.woovi.test/api/v1/dispute/E-PIX-1' => Http::response(['dispute' => Arr::only($med, ['id', 'type', 'status', 'endToEndId'])]),
        ]);

        $this->artisan('med:sincronizar')->assertSuccessful();
        $this->artisan('med:sincronizar')->assertSuccessful();

        $cases = Dispute::query()->where('end_to_end_id', 'E-PIX-1')->get()->keyBy('woovi_dispute_id');
        $this->assertCount(2, $cases);
        $this->assertSame('MED', $cases['med-1']->type);
        $this->assertSame(DisputeStatus::REJECTED, $cases['med-1']->status);
        $this->assertSame('CHARGEBACK', $cases['cb-1']->type);
        $this->assertSame('OPENED', $cases['cb-1']->woovi_status);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $cases['cb-1']->status);
        $this->assertSame(0, DisputeEvent::query()->where('event', DisputeEventType::WOOVI_STATUS_CHANGED->value)->count());

        // A releitura do chargeback (o GET devolveu o MED) veio da listagem do dia em que ele abriu.
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v1/dispute?')
            && ($request->data()['start'] ?? null) === $cases['cb-1']->opened_at->copy()->subDay()->toIso8601ZuluString());
    }

    public function test_scoreboard_shows_answered_results_and_what_is_still_open(): void
    {
        Dispute::factory()->count(2)->create(['status' => DisputeStatus::EVIDENCE_SENT, 'evidence_sent_at' => now()->subDay()]);
        Dispute::factory()->create(['status' => DisputeStatus::REJECTED, 'woovi_status' => 'REJECTED', 'evidence_sent_at' => now()->subDays(2), 'closed_at' => now()->subHour(), 'merchant' => ['trade_name' => 'Mega Store']]);
        Dispute::factory()->create(['status' => DisputeStatus::ACCEPTED, 'woovi_status' => 'ACCEPTED', 'evidence_sent_at' => now()->subDays(2), 'closed_at' => now()->subDays(3)]);
        Dispute::factory()->create(['opened_at' => now()->subHours(2)]);
        Dispute::factory()->create(['opened_at' => now()->subDays(5)]);

        $this->artisan('med:placar')
            ->expectsOutputToContain('Respondidos à Woovi desde')
            ->expectsOutputToContain('aguardando decisão        2 · R$ 300,00')
            ->expectsOutputToContain('ganhos                    1 · R$ 150,00')
            ->expectsOutputToContain('perdidos                  1 · R$ 150,00')
            ->expectsOutputToContain('taxa de ganho: 50% dos decididos')
            ->expectsOutputToContain('no prazo da Woovi         1 · R$ 150,00')
            ->expectsOutputToContain('prazo vencido             1 · R$ 150,00')
            ->expectsOutputToContain('Decisões nas respondidas, últimas 24h: 1')
            ->expectsOutputToContain('Mega Store - Contestação rejeitada')
            ->assertSuccessful();
    }

    public function test_one_account_failing_does_not_stop_the_others(): void
    {
        $broken = WooviAccount::factory()->create(['app_id' => 'chave-quebrada']);
        $ok = WooviAccount::factory()->create();

        Http::fake(function (Request $request) {
            if ($request->header('Authorization')[0] === 'chave-quebrada') {
                return Http::response(['error' => 'appID inválido'], 401);
            }

            return Http::response(['disputes' => [], 'pageInfo' => ['hasNextPage' => false]]);
        });

        $this->artisan('med:sincronizar')->assertFailed();

        $this->assertNull($broken->fresh()->last_synced_at);
        $this->assertNotNull($ok->fresh()->last_synced_at);
    }

    public function test_deadline_warning_reminds_the_seller_once(): void
    {
        $dueSoon = Dispute::factory()->create(['respond_by' => now()->addHours(5)]);
        Dispute::factory()->create(['respond_by' => now()->addDays(3)]);

        $this->artisan('med:alertar-prazos')->assertSuccessful();
        $this->artisan('med:alertar-prazos')->assertSuccessful();

        Mail::assertSentCount(1);
        Mail::assertSent(DisputeOpenedMail::class, fn (DisputeOpenedMail $mail) => $mail->dispute->is($dueSoon));
        $this->assertSame(1, $dueSoon->events()->where('event', DisputeEventType::DEADLINE_WARNING->value)->count());
    }

    public function test_register_webhooks_creates_only_the_missing_events(): void
    {
        $account = WooviAccount::factory()->create();

        Http::fake([
            'api.woovi.test/api/v1/webhook?*' => Http::response(['webhooks' => [
                ['event' => 'OPENPIX:DISPUTE_CREATED', 'url' => $account->webhookUrl()],
            ]]),
            'api.woovi.test/api/v1/webhook' => Http::response(['webhook' => ['id' => 'w']]),
        ]);

        $this->artisan('med:registrar-webhooks')->assertSuccessful();

        $created = collect(Http::recorded())
            ->map(fn ($pair) => $pair[0])
            ->filter(fn (Request $request): bool => $request->method() === 'POST')
            ->map(fn (Request $request): string => $request->data()['webhook']['event'])
            ->values()
            ->all();

        $this->assertSame(['OPENPIX:DISPUTE_ACCEPTED', 'OPENPIX:DISPUTE_REJECTED', 'OPENPIX:DISPUTE_CANCELED'], $created);
        $this->assertNotNull($account->fresh()->webhooks_registered_at);
    }
}
