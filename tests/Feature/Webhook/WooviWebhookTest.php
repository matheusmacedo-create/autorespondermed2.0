<?php

namespace Tests\Feature\Webhook;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Mail\DisputeClosedMail;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use App\Models\WooviAccount;
use App\Models\WooviWebhookCall;
use App\Services\Woovi\WebhookSignatureVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\SignsWooviWebhooks;
use Tests\TestCase;

class WooviWebhookTest extends TestCase
{
    use RefreshDatabase, SignsWooviWebhooks;

    private WooviAccount $account;

    protected function setUp(): void
    {
        parent::setUp();

        $this->useWooviTestKeys();
        Mail::fake();
        $this->account = WooviAccount::factory()->create();
    }

    /**
     * Payload do exemplo da doc da Woovi (OPENPIX:DISPUTE_CREATED).
     *
     * @return array<string, mixed>
     */
    private function createdPayload(array $dispute = []): array
    {
        return [
            'event' => 'OPENPIX:DISPUTE_CREATED',
            'dispute' => array_merge([
                'status' => 'OPENED',
                'endToEndId' => 'E3524a995bbd54034b6d07c1c36014557',
                'name' => 'Seu jorge',
                'email' => 'test@email.com.br',
                'phoneNumber' => '+5519989673846',
                'value' => 1000,
                'disputeReason' => 'Golpe',
            ], $dispute),
        ];
    }

    public function test_dispute_created_opens_a_case_and_notifies_the_seller(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())
            ->assertOk()
            ->assertJson(['received' => true]);

        $dispute = Dispute::sole();

        $this->assertSame($this->account->id, $dispute->woovi_account_id);
        $this->assertSame('E3524a995bbd54034b6d07c1c36014557', $dispute->end_to_end_id);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $dispute->status);
        $this->assertSame(1000, $dispute->value);
        $this->assertSame('Golpe', $dispute->dispute_reason);
        $this->assertSame('MED', $dispute->type);
        $this->assertNotNull($dispute->respond_by);
        $this->assertEqualsWithDelta(72, $dispute->opened_at->diffInHours($dispute->respond_by), 0.01);
        $this->assertNotNull($dispute->seller_notified_at);

        Mail::assertSent(DisputeOpenedMail::class, fn (DisputeOpenedMail $mail) => $mail->hasTo($this->account->contact_email));

        $call = WooviWebhookCall::sole();
        $this->assertTrue($call->signature_valid);
        $this->assertNotNull($call->processed_at);
        $this->assertSame($dispute->id, $call->dispute_id);

        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::RECEIVED->value]);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::SELLER_NOTIFIED->value]);
    }

    public function test_the_same_webhook_twice_does_not_duplicate_the_case_or_the_notice(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())->assertOk();
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())->assertOk();

        $this->assertSame(1, Dispute::count());
        $this->assertSame(2, WooviWebhookCall::count());
        Mail::assertSentCount(1);
    }

    public function test_invalid_signature_is_rejected_and_logged(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload(), signature: base64_encode('forjada'))
            ->assertUnauthorized();

        $this->assertSame(0, Dispute::count());

        $call = WooviWebhookCall::sole();
        $this->assertFalse($call->signature_valid);
        $this->assertNotNull($call->error);
        $this->assertDatabaseHas('dispute_events', [
            'woovi_account_id' => $this->account->id,
            'event' => DisputeEventType::WEBHOOK_REJECTED->value,
        ]);
    }

    public function test_body_altered_after_signing_is_rejected(): void
    {
        $signature = $this->signWooviBody(json_encode($this->createdPayload(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload(['value' => 1]), signature: $signature)
            ->assertUnauthorized();

        $this->assertSame(0, Dispute::count());
    }

    public function test_unknown_webhook_key_returns_404(): void
    {
        $this->postWooviWebhook('chave-que-nao-existe', $this->createdPayload())->assertNotFound();

        $this->assertSame(0, WooviWebhookCall::count());
    }

    public function test_the_same_end_to_end_id_in_two_accounts_are_two_cases(): void
    {
        $other = WooviAccount::factory()->create();

        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())->assertOk();
        $this->postWooviWebhook($other->webhook_key, $this->createdPayload())->assertOk();

        $this->assertSame(2, Dispute::count());
    }

    public function test_second_dispute_of_the_same_pix_is_a_new_case_and_each_decision_closes_only_its_own(): void
    {
        // Formato real do webhook: traz id e type (o exemplo da doc não traz).
        $med = ['id' => 'med-a', 'type' => 'MED'];
        $chargeback = ['id' => 'cb-b', 'type' => 'CHARGEBACK'];

        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload($med))->assertOk();
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload($med))->assertOk();
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload($chargeback))->assertOk();

        $this->postWooviWebhook($this->account->webhook_key, [
            'event' => 'OPENPIX:DISPUTE_REJECTED',
            'dispute' => $this->createdPayload(['status' => 'REJECTED'] + $med)['dispute'],
        ])->assertOk();

        $cases = Dispute::query()->get()->keyBy('woovi_dispute_id');
        $this->assertCount(2, $cases);
        $this->assertSame(DisputeStatus::REJECTED, $cases['med-a']->status);
        $this->assertSame('CHARGEBACK', $cases['cb-b']->type);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $cases['cb-b']->status);
        $this->assertSame('OPENED', $cases['cb-b']->woovi_status);
    }

    public function test_webhook_without_id_does_not_guess_between_two_cases_of_the_same_pix(): void
    {
        $e2e = $this->createdPayload()['dispute']['endToEndId'];
        Dispute::factory()->for($this->account)->count(2)->sequence(['woovi_dispute_id' => 'antigo'], ['woovi_dispute_id' => 'novo'])->create(['end_to_end_id' => $e2e]);

        $this->withoutExceptionHandling();

        try {
            $this->postWooviWebhook($this->account->webhook_key, [
                'event' => 'OPENPIX:DISPUTE_ACCEPTED',
                'dispute' => $this->createdPayload(['status' => 'ACCEPTED'])['dispute'],
            ]);
        } catch (\InvalidArgumentException) {
            // A fila síncrona dos testes propaga a exceção do job.
        }

        $this->assertSame(0, Dispute::query()->where('status', DisputeStatus::ACCEPTED->value)->count());
        $this->assertStringContainsString('sem id', (string) WooviWebhookCall::sole()->error);
    }

    public function test_dispute_rejected_closes_the_case_in_favor_of_the_seller(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())->assertOk();

        $this->postWooviWebhook($this->account->webhook_key, [
            'event' => 'OPENPIX:DISPUTE_REJECTED',
            'dispute' => $this->createdPayload(['status' => 'REJECTED'])['dispute'],
        ])->assertOk();

        $dispute = Dispute::sole();
        $this->assertSame(DisputeStatus::REJECTED, $dispute->status);
        $this->assertSame('REJECTED', $dispute->woovi_status);
        $this->assertNotNull($dispute->closed_at);
        $this->assertTrue($dispute->isTokenExpired());

        Mail::assertSent(DisputeClosedMail::class);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::CLOSED->value]);
    }

    public function test_final_event_without_status_field_uses_the_event_name(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())->assertOk();

        $dispute = $this->createdPayload()['dispute'];
        unset($dispute['status']);

        $this->postWooviWebhook($this->account->webhook_key, ['event' => 'OPENPIX:DISPUTE_ACCEPTED', 'dispute' => $dispute])->assertOk();

        $this->assertSame(DisputeStatus::ACCEPTED, Dispute::sole()->status);
    }

    public function test_dispute_that_arrives_already_closed_does_not_ask_for_a_defense(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, [
            'event' => 'OPENPIX:DISPUTE_CANCELED',
            'dispute' => $this->createdPayload(['status' => 'CANCELED'])['dispute'],
        ])->assertOk();

        $this->assertSame(DisputeStatus::CANCELED, Dispute::sole()->status);
        Mail::assertNothingSent();
    }

    public function test_woovi_registration_test_call_gets_200_even_unsigned(): void
    {
        // Corpo do teste que a Woovi manda ao cadastrar o webhook (doc webhook-test).
        $test = ['data_criacao' => '2024-01-23T20:32:14.429Z', 'event' => 'OPENPIX:DISPUTE_CREATED'];

        $this->postWooviWebhook($this->account->webhook_key, $test)->assertOk();
        $this->postWooviWebhook($this->account->webhook_key, $test, signature: '')->assertOk();

        $this->assertSame(0, Dispute::count());
        $this->assertSame(2, WooviWebhookCall::whereNotNull('processed_at')->count());
        Mail::assertNothingSent();
    }

    public function test_non_dispute_event_is_acknowledged_and_ignored(): void
    {
        $this->postWooviWebhook($this->account->webhook_key, ['event' => 'OPENPIX:CHARGE_COMPLETED', 'dispute' => []])->assertOk();

        $this->assertSame(0, Dispute::count());
        $this->assertNotNull(WooviWebhookCall::sole()->processed_at);
    }

    public function test_processing_failure_keeps_the_call_with_the_error(): void
    {
        $payload = $this->createdPayload();
        unset($payload['dispute']['endToEndId']);

        $this->withoutExceptionHandling();

        try {
            $this->postWooviWebhook($this->account->webhook_key, $payload);
        } catch (\InvalidArgumentException) {
            // A fila síncrona dos testes propaga a exceção do job.
        }

        $call = WooviWebhookCall::sole();
        $this->assertNull($call->processed_at);
        $this->assertStringContainsString('endToEndId', (string) $call->error);
    }

    public function test_signature_check_accepts_any_published_key(): void
    {
        $current = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        config(['services.woovi.webhook.public_keys_url' => 'https://api.woovi.test/api/v1/webhook/public-keys']);
        WebhookSignatureVerifier::forgetCachedKeys();

        Http::fake([
            'api.woovi.test/api/v1/webhook/public-keys' => Http::response(['public_keys' => [
                ['key_identifier' => 'nova', 'is_current' => true, 'key' => openssl_pkey_get_details($current)['key']],
            ]]),
        ]);

        // Assinado com a chave "antiga" (a de fallback): continua valendo durante a rotação.
        $this->postWooviWebhook($this->account->webhook_key, $this->createdPayload())->assertOk();

        $this->assertSame(1, Dispute::count());
    }
}
