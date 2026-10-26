<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\MerchantStoreAction;
use App\Enums\EvidenceKind;
use App\Filament\Resources\MerchantStores\Pages\ListMerchantStores;
use App\Models\Dispute;
use App\Models\MerchantStore;
use App\Models\User;
use App\Services\Defense\DefenseWriter;
use App\Services\Stores\StoreCapturer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Políticas do site da loja: capturadas uma vez por loja, anexadas a cada MED dela e
 * lidas pela IA.
 */
class MerchantStoreTest extends DossierTestCase
{
    private object $capturer;

    protected function setUp(): void
    {
        parent::setUp();

        $data = json_decode((string) $this->fakes['sale']->wait()->getBody(), true)['data'];
        $data['account_id'] = '4321';
        $data['seller'] = ['name' => 'Loja do Zé LTDA', 'trade_name' => 'Loja do Zé', 'document' => '12345678000190'];
        $this->fakes['sale'] = Http::response(['data' => $data]);
        $this->fakes['register'] = Http::response(['data' => ['account_id' => '4321', 'register' => ['trade_name' => 'Loja do Zé', 'website' => 'lojadoze.com.br']]]);

        $this->capturer = new class implements StoreCapturer
        {
            public int $calls = 0;

            public bool $available = true;

            public ?string $website = null;

            public bool $noPolicies = false;

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function capture(string $website, array $hints = []): array
            {
                $this->calls++;
                $this->website = $website;

                if ($this->noPolicies) {
                    return ['final_url' => $website, 'errors' => ['shipping: página não encontrada'], 'pages' => []];
                }

                return ['final_url' => $website, 'errors' => [], 'pages' => [
                    ['kind' => 'shipping', 'url' => $website.'/politica-de-entrega', 'title' => 'Entrega', 'pdf' => '%PDF-1.4 entrega', 'text' => 'Prazo de entrega: 7 a 12 dias úteis após o despacho.'],
                    ['kind' => 'exchange', 'url' => $website.'/trocas', 'title' => 'Trocas', 'pdf' => '%PDF-1.4 trocas', 'text' => 'Trocas em até 7 dias corridos.'],
                ]];
            }
        };
        $this->app->instance(StoreCapturer::class, $this->capturer);
    }

    public function test_store_site_comes_from_the_platform_register_and_policies_are_attached(): void
    {
        $dispute = $this->receive();

        $store = MerchantStore::query()->sole();
        $this->assertSame('4321', $store->merchant_account_id);
        $this->assertSame('https://lojadoze.com.br', $store->website);
        $this->assertSame('unicopag_register', $store->website_source);
        $this->assertSame('partial', $store->capture_status);
        Storage::disk('local')->assertExists($store->capture('shipping')['path']);

        $policies = $dispute->evidences()->where('kind', EvidenceKind::STORE_POLICY->value)->get();
        $this->assertCount(2, $policies);
        $this->assertStringContainsString('Política de entrega publicada no site da loja (https://lojadoze.com.br/politica-de-entrega)', $policies->firstWhere('source', 'store_shipping')->description);
    }

    public function test_next_case_of_the_same_store_reuses_the_capture(): void
    {
        $this->receive();
        $other = Dispute::factory()->for($this->account)->create(['opened_at' => now(), 'merchant_account_id' => '4321', 'merchant' => ['trade_name' => 'Loja do Zé']]);

        app(MerchantStoreAction::class)->forDispute($other);

        $this->assertSame(1, $this->capturer->calls);
        $this->assertSame(2, $other->evidences()->where('kind', EvidenceKind::STORE_POLICY->value)->count());
    }

    public function test_old_capture_is_taken_again(): void
    {
        $this->receive();
        MerchantStore::query()->update(['captured_at' => now()->subDays(40), 'capture_attempted_at' => now()->subDays(40)]);

        app(MerchantStoreAction::class)->forDispute(Dispute::query()->first());

        $this->assertSame(2, $this->capturer->calls);
    }

    public function test_without_the_browser_nothing_is_captured_and_the_dossier_still_builds(): void
    {
        $this->capturer->available = false;

        $dispute = $this->receive();

        $this->assertSame(0, $this->capturer->calls);
        $this->assertNotNull($dispute->dossier_built_at);
        $this->assertSame('https://lojadoze.com.br', MerchantStore::query()->sole()->website);
    }

    public function test_store_without_site_is_marked_for_the_analyst(): void
    {
        $this->fakes['register'] = Http::response(['data' => ['account_id' => '4321', 'register' => ['trade_name' => 'Loja do Zé']]]);

        $this->receive();

        $this->assertSame('no_website', MerchantStore::query()->sole()->capture_status);
        $this->assertSame(0, $this->capturer->calls);
    }

    public function test_site_falls_back_to_the_kyc(): void
    {
        $this->fakes['register'] = Http::response([], 404);
        config(['services.kyc.store_endpoint' => '/api/med/stores/{account_id}', 'services.kyc.med_key' => 'kyc-med']);
        $this->fakes['kyc_store'] = Http::response(['data' => ['account_id' => '4321', 'website' => 'https://zeloja.com.br']]);

        $this->receive();

        $this->assertSame('https://zeloja.com.br', MerchantStore::query()->sole()->website);
        $this->assertSame('kyc', MerchantStore::query()->sole()->website_source);
        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/api/med/stores/4321') && $r->header('X-API-Key') === ['kyc-med']);
    }

    public function test_the_ai_reads_the_store_policy(): void
    {
        config(['med.ia_automatica' => true]);
        $writer = new class implements DefenseWriter
        {
            public array $received = [];

            public function write(array $case): array
            {
                $this->received = $case;

                return ['draft' => ['narrative' => 'x', 'arguments' => [], 'weaknesses' => [], 'missing_evidence' => [], 'recommendation' => 'defender', 'recommendation_reason' => 'x'], 'model' => 'fake', 'usage' => []];
            }

            public function isConfigured(): bool
            {
                return true;
            }
        };
        $this->app->instance(DefenseWriter::class, $writer);

        $this->receive();

        $this->assertSame('Prazo de entrega: 7 a 12 dias úteis após o despacho.', $writer->received['store_policies']['Política de entrega (https://lojadoze.com.br/politica-de-entrega)']);
    }

    public function test_stores_page_lists_the_store_with_its_cases(): void
    {
        $this->actingAs(User::factory()->create());
        $this->receive();

        Livewire::test(ListMerchantStores::class)
            ->assertOk()
            ->assertSee('Loja do Zé')
            ->assertSee('Parcial');
    }

    public function test_site_without_policies_removes_what_was_attached_before(): void
    {
        $dispute = $this->receive();
        $store = MerchantStore::query()->sole();
        $this->assertSame(2, $dispute->evidences()->where('kind', EvidenceKind::STORE_POLICY->value)->count());

        $this->capturer->noPolicies = true;
        $stores = app(MerchantStoreAction::class);
        $stores->spread($stores->capture($store));

        $this->assertSame('failed', $store->fresh()->capture_status);
        $this->assertSame([], $store->fresh()->captures);
        $this->assertSame(0, $dispute->evidences()->where('kind', EvidenceKind::STORE_POLICY->value)->count());

        // Não volta a percorrer o mesmo site a cada MED novo.
        $stores->forDispute($dispute->fresh());
        $this->assertSame(2, $this->capturer->calls);
    }

    public function test_url_normalization(): void
    {
        $this->assertSame('https://loja.com.br', MerchantStoreAction::normalizeUrl(' loja.com.br '));
        $this->assertSame('http://loja.com.br/x', MerchantStoreAction::normalizeUrl('http://loja.com.br/x'));
        $this->assertNull(MerchantStoreAction::normalizeUrl('não tenho'));
        $this->assertNull(MerchantStoreAction::normalizeUrl(''));
    }
}
