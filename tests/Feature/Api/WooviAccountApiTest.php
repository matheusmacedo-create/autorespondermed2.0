<?php

namespace Tests\Feature\Api;

use App\Enums\DisputeStatus;
use App\Enums\WooviAccountSource;
use App\Models\Dispute;
use App\Models\WooviAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WooviAccountApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.api.key' => 'segredo-do-unico-api']);
    }

    private function headers(?string $key = 'segredo-do-unico-api'): array
    {
        return ['X-API-Key' => (string) $key, 'Accept' => 'application/json'];
    }

    public function test_routes_deny_without_the_key_and_when_no_key_is_configured(): void
    {
        $this->putJson('/api/woovi-accounts', [], $this->headers('errada'))->assertUnauthorized();
        $this->getJson('/api/woovi-accounts/1/disputes', $this->headers(null))->assertUnauthorized();

        config(['services.api.key' => '']);
        $this->putJson('/api/woovi-accounts', [], $this->headers(''))->assertUnauthorized();
    }

    public function test_unico_api_creates_the_account_and_gets_the_webhook_url(): void
    {
        $response = $this->putJson('/api/woovi-accounts', [
            'account_id' => '4321',
            'name' => 'Loja do José LTDA',
            'app_id' => 'Q2xpZW50X0lkX2FwcElk',
            'tax_id' => '12.345.678/0001-90',
            'contact_email' => 'jose@loja.com.br',
        ], $this->headers());

        $response->assertCreated()->assertJsonPath('data.account_id', '4321');

        $account = WooviAccount::sole();
        $this->assertSame('Q2xpZW50X0lkX2FwcElk', $account->app_id);
        $this->assertSame('12345678000190', $account->tax_id);
        $this->assertSame(WooviAccountSource::UNICO_API, $account->source);
        $this->assertSame($account->webhookUrl(), $response->json('data.webhook_url'));

        // AppID criptografado no banco, nunca em claro.
        $this->assertNotSame('Q2xpZW50X0lkX2FwcElk', \DB::table('woovi_accounts')->value('app_id'));
        $this->assertArrayNotHasKey('app_id', $account->toArray());
    }

    public function test_second_call_updates_the_same_account(): void
    {
        $account = WooviAccount::factory()->create(['unicopag_account_id' => '4321', 'contact_email' => 'antigo@loja.com.br']);
        $webhookKey = $account->webhook_key;

        $this->putJson('/api/woovi-accounts', [
            'account_id' => '4321',
            'name' => 'Nome novo',
            'app_id' => 'nova-chave',
        ], $this->headers())->assertOk();

        $account->refresh();
        $this->assertSame(1, WooviAccount::count());
        $this->assertSame('Nome novo', $account->name);
        $this->assertSame('nova-chave', $account->app_id);
        $this->assertSame('antigo@loja.com.br', $account->contact_email);
        // A URL do webhook não muda: a Woovi continua chamando o mesmo endereço.
        $this->assertSame($webhookKey, $account->webhook_key);
    }

    public function test_validation_errors_come_as_json(): void
    {
        $this->putJson('/api/woovi-accounts', ['account_id' => '1'], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'app_id']);
    }

    public function test_lists_the_account_disputes_with_the_seller_link_while_open(): void
    {
        $account = WooviAccount::factory()->create(['unicopag_account_id' => '4321']);
        $open = Dispute::factory()->for($account)->create();
        Dispute::factory()->for($account)->create(['status' => DisputeStatus::REJECTED, 'closed_at' => now()]);
        Dispute::factory()->create();

        $response = $this->getJson('/api/woovi-accounts/4321/disputes', $this->headers())->assertOk();

        $this->assertCount(2, $response->json('data'));

        $byId = collect($response->json('data'))->keyBy('id');
        $this->assertSame($open->sellerLink(), $byId[$open->id]['seller_link']);
        $this->assertSame('pending_seller', $byId[$open->id]['status']);
        $this->assertNull($byId->firstWhere('status', 'rejected')['seller_link']);
    }

    public function test_unknown_account_returns_404(): void
    {
        $this->getJson('/api/woovi-accounts/999/disputes', $this->headers())->assertNotFound();
    }
}
