<?php

namespace Tests\Feature\Api;

use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\WooviAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rotas de leitura do RiskOps do KYC: contagem por conta e casos de uma conta.
 */
class RiskOpsDisputesTest extends TestCase
{
    use RefreshDatabase;

    private WooviAccount $master;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.riskops.key' => 'riskops-secret', 'services.api.key' => 'unico-api-secret']);
        $this->master = WooviAccount::factory()->create(['unicopag_account_id' => null]);
    }

    private function case(array $attributes = []): Dispute
    {
        return Dispute::factory()->for($this->master)->create(array_merge([
            'merchant_account_id' => '6139',
            'merchant' => ['trade_name' => 'Loja X'],
            'opened_at' => now()->subDays(2),
            'payer_name' => 'Fulano Pagador',
            'payer_email' => 'fulano@pagador.test',
            'payer_phone' => '+5511999990000',
        ], $attributes));
    }

    private function riskops(string $uri, string $key = 'riskops-secret')
    {
        return $this->getJson($uri, ['X-API-Key' => $key]);
    }

    public function test_wrong_or_missing_key_is_refused(): void
    {
        $since = urlencode(now()->subMonth()->toIso8601String());

        $this->riskops("/api/riskops/disputas?desde={$since}", 'errada')->assertUnauthorized();
        $this->riskops("/api/riskops/disputas?desde={$since}", 'unico-api-secret')->assertUnauthorized();
        $this->getJson("/api/riskops/disputas/6139?desde={$since}")->assertUnauthorized();

        config(['services.riskops.key' => null]);
        $this->riskops("/api/riskops/disputas?desde={$since}", '')->assertUnauthorized();
    }

    public function test_groups_by_account_and_status(): void
    {
        $this->case(['status' => DisputeStatus::ACCEPTED, 'value' => 5000]);
        $this->case(['status' => DisputeStatus::REJECTED, 'value' => 1000]);
        $this->case(['status' => DisputeStatus::EVIDENCE_SENT, 'value' => 2000, 'type' => 'DISPUTE']);
        $this->case(['status' => DisputeStatus::CANCELED, 'value' => 1000]);
        $this->case(['status' => DisputeStatus::PENDING_SELLER, 'value' => 9900, 'type' => 'CHARGEBACK']);
        // Outra conta, e um caso sem conta nenhuma (fica de fora).
        $this->case(['merchant_account_id' => '7000', 'merchant' => ['trade_name' => 'Loja Y']]);
        $this->case(['merchant_account_id' => null, 'merchant' => null]);

        $response = $this->riskops('/api/riskops/disputas?desde='.urlencode(now()->subMonth()->toIso8601String()))->assertOk();

        $this->assertCount(2, $response->json('data'));
        $this->assertSame([
            'conta' => 6139, 'nome' => 'Loja X', 'med' => 4, 'med_valor' => 9000, 'perdidos' => 1, 'perdidos_valor' => 5000,
            'ganhos' => 1, 'abertos' => 2, 'cancelados' => 1, 'chargebacks' => 1, 'chargebacks_valor' => 9900,
        ], collect($response->json('data'))->firstWhere('conta', 6139));

        $only = $this->riskops('/api/riskops/disputas?contas=7000&desde='.urlencode(now()->subMonth()->toIso8601String()))->assertOk();
        $this->assertSame([7000], array_column($only->json('data'), 'conta'));
    }

    public function test_account_of_the_woovi_subaccount_counts_when_the_sale_has_none(): void
    {
        $sub = WooviAccount::factory()->create(['unicopag_account_id' => '8100']);
        Dispute::factory()->for($sub)->create(['opened_at' => now()->subDay(), 'merchant_account_id' => null]);

        $response = $this->riskops('/api/riskops/disputas?desde='.urlencode(now()->subMonth()->toIso8601String()))->assertOk();

        $this->assertSame([8100], array_column($response->json('data'), 'conta'));
    }

    public function test_filters_by_case_date(): void
    {
        $this->case(['opened_at' => now()->subDays(40)]);
        $recent = $this->case(['opened_at' => now()->subDays(3)]);
        $noOpening = $this->case(['opened_at' => null]);
        $noOpening->forceFill(['created_at' => now()->subDays(50)])->save();

        $since = urlencode(now()->subDays(30)->toIso8601String());

        $this->assertSame(1, $this->riskops("/api/riskops/disputas?desde={$since}")->json('data.0.med'));
        $this->assertSame([$recent->id], array_column($this->riskops("/api/riskops/disputas/6139?desde={$since}")->json('data'), 'id'));
        $this->riskops('/api/riskops/disputas')->assertUnprocessable();
    }

    public function test_account_cases_newest_first_with_no_payer_data(): void
    {
        $old = $this->case(['opened_at' => now()->subDays(5), 'type' => 'MED', 'dispute_reason' => 'Golpe', 'value' => 1500]);
        $new = $this->case(['opened_at' => now()->subDay(), 'type' => 'CHARGEBACK', 'status' => DisputeStatus::REJECTED]);

        $response = $this->riskops('/api/riskops/disputas/6139?desde='.urlencode(now()->subMonth()->toIso8601String()))->assertOk();

        $this->assertSame([$new->id, $old->id], array_column($response->json('data'), 'id'));
        $this->assertSame(['id', 'tipo', 'status', 'valor', 'motivo', 'aberto_em'], array_keys($response->json('data.1')));
        $this->assertSame(['tipo' => 'MED', 'status' => 'pending_seller', 'valor' => 1500, 'motivo' => 'Golpe'], array_intersect_key($response->json('data.1'), array_flip(['tipo', 'status', 'valor', 'motivo'])));

        $summary = $this->riskops('/api/riskops/disputas?desde='.urlencode(now()->subMonth()->toIso8601String()))->getContent();

        foreach ([$response->getContent(), $summary] as $body) {
            $this->assertStringNotContainsString('Fulano', $body);
            $this->assertStringNotContainsString('pagador.test', $body);
            $this->assertStringNotContainsString('99999', $body);
        }
    }
}
