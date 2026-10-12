<?php

namespace Tests\Feature\WhatsApp;

use App\Actions\Med\IngestDisputeAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use App\Models\WhatsAppMessage;
use App\Models\WhatsAppOptOut;
use App\Models\WooviAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WhatsAppFlowTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '5521998765432';

    private WooviAccount $account;

    private int $sent = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config([
            'services.kapso.api_key' => 'kapso-key',
            'services.kapso.phone_number_id' => '111',
            'services.kapso.base_url' => 'https://api.kapso.test',
            'services.kapso.webhook_secret' => 'segredo-med',
            'med.whatsapp.template' => 'aviso_med_v1',
        ]);

        Http::fake([
            'api.kapso.test/meta/whatsapp/*/111/messages' => function () {
                $this->sent++;

                return Http::response(['messages' => [['id' => "wamid.MED{$this->sent}"]]]);
            },
        ]);

        $this->account = WooviAccount::factory()->create([
            'contact_name' => 'josé da silva',
            'contact_phone' => '(21) 99876-5432',
        ]);
    }

    private function receive(string $endToEndId = 'E-MED-1'): Dispute
    {
        return app(IngestDisputeAction::class)->handle($this->account, [
            'status' => 'OPENED',
            'endToEndId' => $endToEndId,
            'value' => 25000,
            'disputeReason' => 'Golpe',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function payloadOf(int $index): array
    {
        return Http::recorded()
            ->map(fn (array $pair) => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/messages'))
            ->values()[$index]
            ->data();
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function kapsoWebhook(string $event, array $body, ?string $secret = 'segredo-med'): TestResponse
    {
        $raw = json_encode($body, JSON_THROW_ON_ERROR);

        return $this->call('POST', route('webhooks.kapso'), server: array_filter([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_WEBHOOK_EVENT' => $event,
            'HTTP_X_WEBHOOK_SIGNATURE' => $secret !== null ? hash_hmac('sha256', $raw, $secret) : null,
        ]), content: $raw);
    }

    /**
     * Resposta do seller como a Kapso manda (formato conferido no KYC).
     *
     * @return array<string, mixed>
     */
    private function reply(string $text, ?string $quoting = null, string $from = self::PHONE): array
    {
        return [
            'message' => array_filter([
                'id' => 'wamid.IN'.md5($text.$from),
                'timestamp' => (string) now()->timestamp,
                'type' => 'text',
                'from' => $from,
                'context' => $quoting ? ['from' => '5521923674860', 'id' => $quoting] : null,
                'text' => ['body' => $text],
                'kapso' => ['direction' => 'inbound', 'status' => 'received', 'content' => $text],
            ]),
            'conversation' => ['id' => 'conv-1', 'phone_number' => $from],
        ];
    }

    public function test_new_dispute_sends_the_template_and_the_email(): void
    {
        $dispute = $this->receive();

        $payload = $this->payloadOf(0);
        $this->assertSame(self::PHONE, $payload['to']);
        $this->assertSame('template', $payload['type']);
        $this->assertSame('aviso_med_v1', $payload['template']['name']);
        $this->assertSame('pt_BR', $payload['template']['language']['code']);
        $this->assertSame('José', $payload['template']['components'][0]['parameters'][0]['text']);

        $message = WhatsAppMessage::sole();
        $this->assertSame(WhatsAppMessage::TYPE_TEMPLATE, $message->type);
        $this->assertSame('wamid.MED1', $message->wamid);
        $this->assertSame($dispute->id, $message->dispute_id);

        Mail::assertSent(DisputeOpenedMail::class);
        $this->assertSame(2, $dispute->events()->where('event', DisputeEventType::SELLER_NOTIFIED->value)->count());
    }

    public function test_seller_reply_quoting_the_template_releases_the_link(): void
    {
        $dispute = $this->receive();

        $this->kapsoWebhook('whatsapp.message.received', $this->reply('Pode mandar', quoting: 'wamid.MED1'))
            ->assertOk()
            ->assertJson(['processados' => 1]);

        $link = $this->payloadOf(1);
        $this->assertSame('text', $link['type']);
        $this->assertStringContainsString($dispute->sellerLink(), $link['text']['body']);
        $this->assertStringContainsString('R$ 250,00', $link['text']['body']);

        $template = WhatsAppMessage::query()->where('type', WhatsAppMessage::TYPE_TEMPLATE)->sole();
        $this->assertNotNull($template->replied_at);
        $this->assertNotNull($template->link_sent_at);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', WhatsAppMessage::TYPE_LINK)->count());
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::WHATSAPP_REPLIED->value]);

        // A mesma resposta de novo (retry da Kapso) não manda o link duas vezes.
        $this->kapsoWebhook('whatsapp.message.received', $this->reply('Pode mandar', quoting: 'wamid.MED1'))->assertOk();
        $this->assertSame(2, $this->sent);
    }

    public function test_reply_without_quote_answers_the_pending_templates_of_that_phone(): void
    {
        $this->receive('E-1');
        $this->receive('E-2');

        $this->kapsoWebhook('whatsapp.message.received', $this->reply('oi'))->assertJson(['processados' => 2]);

        // 2 templates + 2 links, um por caso.
        $this->assertSame(4, $this->sent);
        $this->assertSame(2, WhatsAppMessage::query()->where('type', WhatsAppMessage::TYPE_LINK)->count());
    }

    public function test_reply_quoting_another_system_message_is_left_alone(): void
    {
        $this->receive();

        // Resposta a uma mensagem do KYC/atendimento no mesmo número: não é do MED.
        $this->kapsoWebhook('whatsapp.message.received', $this->reply('Sim', quoting: 'wamid.DO_KYC'))
            ->assertJson(['processados' => 0]);

        $this->assertSame(1, $this->sent);
    }

    public function test_reply_from_another_phone_is_ignored(): void
    {
        $this->receive();

        $this->kapsoWebhook('whatsapp.message.received', $this->reply('oi', from: '5511912345678'))
            ->assertJson(['processados' => 0]);

        $this->assertSame(1, $this->sent);
    }

    public function test_stop_request_blocks_whatsapp_for_good_but_keeps_the_email(): void
    {
        $dispute = $this->receive('E-1');

        $this->kapsoWebhook('whatsapp.message.received', $this->reply('Pare, por favor', quoting: 'wamid.MED1'))->assertOk();

        $this->assertTrue(WhatsAppOptOut::blocks(self::PHONE));
        $this->assertSame(1, $this->sent);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::WHATSAPP_OPT_OUT->value]);

        $this->receive('E-2');

        $this->assertSame(1, $this->sent);
        Mail::assertSentCount(2);
    }

    public function test_window_open_sends_the_link_directly_without_template(): void
    {
        $this->receive('E-1');
        $this->kapsoWebhook('whatsapp.message.received', $this->reply('ok', quoting: 'wamid.MED1'))->assertOk();

        $this->receive('E-2');

        // template + link do primeiro + link direto do segundo.
        $this->assertSame(3, $this->sent);
        $this->assertSame('text', $this->payloadOf(2)['type']);
        $this->assertSame(1, WhatsAppMessage::query()->where('type', WhatsAppMessage::TYPE_TEMPLATE)->count());
    }

    public function test_reply_to_a_case_already_answered_does_not_send_the_link(): void
    {
        $dispute = $this->receive();
        $dispute->forceFill(['status' => DisputeStatus::SELLER_SUBMITTED])->save();

        $this->kapsoWebhook('whatsapp.message.received', $this->reply('oi', quoting: 'wamid.MED1'))->assertOk();

        $this->assertSame(1, $this->sent);
        $this->assertNotNull(WhatsAppMessage::sole()->link_sent_at);
    }

    public function test_delivery_status_only_moves_forward_and_failure_is_recorded(): void
    {
        $dispute = $this->receive();

        $this->kapsoWebhook('whatsapp.message.read', ['message' => ['id' => 'wamid.MED1']])->assertJson(['processados' => 1]);
        $this->kapsoWebhook('whatsapp.message.delivered', ['message' => ['id' => 'wamid.MED1']])->assertJson(['processados' => 0]);
        $this->assertSame('read', WhatsAppMessage::sole()->delivery_status);

        $this->kapsoWebhook('whatsapp.message.failed', ['message' => [
            'id' => 'wamid.MED1',
            'kapso' => ['statuses' => [['status' => 'failed', 'errors' => [['code' => 131026, 'title' => 'Message undeliverable']]]]],
        ]])->assertJson(['processados' => 1]);

        $message = WhatsAppMessage::sole();
        $this->assertSame('failed', $message->delivery_status);
        $this->assertStringContainsString('Número sem WhatsApp', $message->error);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::WHATSAPP_DELIVERY_FAILED->value]);
    }

    public function test_webhook_requires_the_signature_and_the_secret(): void
    {
        $this->kapsoWebhook('whatsapp.message.received', $this->reply('oi'), secret: 'errado')->assertUnauthorized();

        config(['services.kapso.webhook_secret' => '']);
        $this->kapsoWebhook('whatsapp.message.received', $this->reply('oi'), secret: '')->assertStatus(503);
    }

    public function test_without_template_or_valid_phone_only_the_email_goes(): void
    {
        config(['med.whatsapp.template' => null]);
        $this->receive('E-1');

        config(['med.whatsapp.template' => 'aviso_med_v1']);
        $this->account->forceFill(['contact_phone' => '123'])->save();
        $dispute = $this->receive('E-2');

        $this->assertSame(0, $this->sent);
        Mail::assertSentCount(2);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::SELLER_NOTIFICATION_FAILED->value, 'level' => 'warning']);
    }

    public function test_kapso_refusal_is_recorded_and_the_email_still_goes(): void
    {
        Http::swap(new Factory);
        Http::fake(['api.kapso.test/*' => Http::response(['error' => ['message' => 'Template name does not exist', 'code' => 132001]], 400)]);

        $dispute = $this->receive();

        $message = WhatsAppMessage::sole();
        $this->assertSame('failed', $message->status);
        $this->assertStringContainsString('Template não existe', $message->error);
        Mail::assertSent(DisputeOpenedMail::class);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::SELLER_NOTIFICATION_FAILED->value, 'level' => 'error']);
    }
}
