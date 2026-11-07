<?php

namespace Tests\Feature\Console;

use App\Enums\WooviAccountSource;
use App\Models\WooviAccount;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ImportPartnerAccountsTest extends TestCase
{
    use RefreshDatabase;

    private const LOJA = '65914571000187';

    private const ACADEMIA = '14380200000121';

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['services.woovi.master_app_id' => 'app-mestre']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function fakeWoovi(array $overrides = []): void
    {
        Http::fake(array_merge([
            // Duas páginas de afiliadas; a segunda tem uma empresa sem CNPJ.
            'api.woovi.test/api/v1/partner/affiliate?*' => Http::sequence()
                ->push(['affiliates' => [
                    ['company' => ['id' => 'Q29tcGFueTox', 'name' => 'Loja do Zé LTDA', 'taxID' => self::LOJA], 'account' => ['clientId' => 'Client_Id_1']],
                    ['company' => ['id' => 'Q29tcGFueToy', 'name' => 'Academia Corpo em Forma', 'taxID' => ['taxID' => '14.380.200/0001-21', 'type' => 'BR:CNPJ']]],
                ], 'pageInfo' => ['skip' => 0, 'limit' => 100, 'hasNextPage' => true]])
                ->push(['affiliates' => [
                    ['company' => ['id' => 'Q29tcGFueToz', 'name' => 'Sem CNPJ', 'taxID' => '']],
                ], 'pageInfo' => ['skip' => 100, 'limit' => 100, 'hasNextPage' => false]]),
            // Pré-cadastros: um completa o contato da Loja; o outro ainda não virou empresa.
            'api.woovi.test/api/v1/partner/company?*' => Http::response(['preRegistrations' => [
                ['preRegistration' => ['name' => 'Loja do Zé LTDA', 'taxID' => ['taxID' => self::LOJA, 'type' => 'BR:CNPJ']], 'user' => ['firstName' => 'José', 'lastName' => 'Silva', 'email' => 'jose@lojadoze.com.br', 'phone' => '+5521999990000'], 'company' => ['id' => 'Q29tcGFueTox', 'name' => 'Loja do Zé LTDA', 'taxID' => ['taxID' => self::LOJA]]],
                ['preRegistration' => ['name' => 'Ainda sem conta', 'taxID' => ['taxID' => '11111111000191', 'type' => 'BR:CNPJ']], 'user' => ['firstName' => 'Ana', 'email' => 'ana@exemplo.com']],
            ], 'pageInfo' => ['hasNextPage' => false]]),
            'api.woovi.test/api/v1/partner/application' => Http::sequence()
                ->push(['application' => ['name' => 'UnicoPag MED', 'isActive' => true, 'type' => 'API', 'clientId' => 'Client_Id_a', 'clientSecret' => 'Secret_a', 'scopes' => ['DISPUTE_GET']]], 201)
                ->push(['application' => ['name' => 'UnicoPag MED', 'isActive' => true, 'type' => 'API', 'clientId' => 'Client_Id_b', 'clientSecret' => 'Secret_b']], 201),
            'api.woovi.test/api/v1/webhook?*' => Http::response(['webhooks' => []]),
            'api.woovi.test/api/v1/webhook' => Http::response(['webhook' => ['id' => 'wh-1']]),
            'api.woovi.test/api/v1/dispute?*' => Http::response(['disputes' => [], 'pageInfo' => ['hasNextPage' => false]]),
        ], $overrides));
    }

    private function applicationRequests(): Collection
    {
        return collect(Http::recorded())
            ->map(fn (array $pair): Request => $pair[0])
            ->filter(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/partner/application'));
    }

    public function test_imports_every_subaccount_creates_one_application_each_and_wires_them_up(): void
    {
        $this->fakeWoovi();

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('Subcontas na Woovi: 2 · criadas aqui: 2 · já existiam: 0 · sem empresa ainda: 2 · falhas: 0')
            ->assertSuccessful();

        $loja = WooviAccount::query()->where('tax_id', self::LOJA)->sole();
        $academia = WooviAccount::query()->where('tax_id', self::ACADEMIA)->sole();

        $this->assertSame(WooviAccountSource::PARTNER, $loja->source);
        $this->assertSame(base64_encode('Client_Id_a:Secret_a'), $loja->app_id);
        $this->assertSame(base64_encode('Client_Id_b:Secret_b'), $academia->app_id);
        $this->assertSame('Q29tcGFueTox', $loja->woovi_company_id);
        $this->assertSame('José Silva', $loja->contact_name);
        $this->assertSame('jose@lojadoze.com.br', $loja->contact_email);
        $this->assertSame('+5521999990000', $loja->contact_phone);
        $this->assertNull($academia->contact_email);
        $this->assertTrue($loja->is_active);
        $this->assertNotNull($loja->webhooks_registered_at);
        $this->assertNotNull($loja->last_synced_at);

        // O aplicativo é criado pela conta mestre, por CNPJ, só com os escopos do MED.
        $request = $this->applicationRequests()->first();
        $this->assertSame('app-mestre', $request->header('Authorization')[0]);
        $this->assertSame(['taxID' => self::LOJA, 'type' => 'BR:CNPJ'], $request->data()['taxID']);
        $this->assertSame('UnicoPag MED', $request->data()['application']['name']);
        $this->assertSame('API', $request->data()['application']['type']);
        $this->assertContains('DISPUTE_POST', $request->data()['application']['scopes']);
        $this->assertSame(2, $this->applicationRequests()->count());

        // Webhooks e MEDs já pelo AppID da subconta, nunca pelo mestre.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/dispute?') && $r->header('Authorization')[0] === base64_encode('Client_Id_a:Secret_a'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/dispute?') && $r->header('Authorization')[0] === base64_encode('Client_Id_b:Secret_b'));
        $this->assertSame(8, collect(Http::recorded())->filter(fn (array $pair): bool => $pair[0]->method() === 'POST' && str_ends_with($pair[0]->url(), '/api/v1/webhook'))->count());
        Http::assertNotSent(fn (Request $r): bool => str_contains($r->url(), '/api/v1/dispute') && $r->header('Authorization')[0] === 'app-mestre');
    }

    public function test_second_run_creates_no_new_key_for_an_account_that_already_exists(): void
    {
        $this->fakeWoovi();
        $this->artisan('med:importar-contas')->assertSuccessful();

        // Fábrica nova: os stubs antigos (sequência de páginas esgotada) continuariam
        // sendo executados pelo Http::fake, e o registro recomeça do zero.
        Http::swap(new Factory);
        $this->fakeWoovi();
        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('criadas aqui: 0 · já existiam: 2')
            ->assertSuccessful();

        $this->assertSame(2, WooviAccount::query()->count());
        $this->assertSame(0, $this->applicationRequests()->count());
    }

    public function test_account_registered_by_hand_keeps_its_key_and_only_gets_the_blanks_filled(): void
    {
        $manual = WooviAccount::factory()->create([
            'tax_id' => self::LOJA,
            'app_id' => 'chave-cadastrada-a-mao',
            'contact_email' => null,
            'contact_name' => 'Zé',
            'woovi_company_id' => null,
        ]);
        $this->fakeWoovi();

        $this->artisan('med:importar-contas')->assertSuccessful();

        $manual->refresh();
        $this->assertSame('chave-cadastrada-a-mao', $manual->app_id);
        $this->assertSame(WooviAccountSource::MANUAL, $manual->source);
        $this->assertSame('Zé', $manual->contact_name);
        $this->assertSame('jose@lojadoze.com.br', $manual->contact_email);
        $this->assertSame('Q29tcGFueTox', $manual->woovi_company_id);
        $this->assertSame(1, $this->applicationRequests()->count());
        $this->assertSame(self::ACADEMIA, $this->applicationRequests()->first()->data()['taxID']['taxID']);
    }

    public function test_one_subaccount_failing_does_not_stop_the_others_and_the_run_is_marked_failed(): void
    {
        $this->fakeWoovi([
            'api.woovi.test/api/v1/partner/application' => Http::sequence()
                ->push(['error' => 'Company not found'], 400)
                ->push(['application' => ['clientId' => 'Client_Id_b', 'clientSecret' => 'Secret_b']], 201),
        ]);

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('Loja do Zé LTDA: Woovi recusou a criação do aplicativo na subconta (HTTP 400): Company not found')
            ->expectsOutputToContain('criadas aqui: 1 · já existiam: 0 · sem empresa ainda: 2 · falhas: 1')
            ->assertFailed();

        $this->assertFalse(WooviAccount::query()->where('tax_id', self::LOJA)->exists());
        $this->assertTrue(WooviAccount::query()->where('tax_id', self::ACADEMIA)->exists());
    }

    public function test_scope_list_refused_by_woovi_stops_the_run_and_never_creates_a_full_access_key(): void
    {
        $this->fakeWoovi([
            'api.woovi.test/api/v1/partner/application' => Http::response(['error' => 'Invalid scopes: FUNDS_RECOVERY_GET'], 400),
        ]);

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('Criação de contas interrompida')
            ->assertFailed();

        $this->assertSame(0, WooviAccount::query()->count());
        // Parou na primeira recusa: não repete a mesma chamada para cada subconta.
        $this->assertSame(1, $this->applicationRequests()->count());
        $this->assertArrayHasKey('scopes', $this->applicationRequests()->first()->data()['application']);
    }

    public function test_master_without_application_permission_stops_after_the_first_refusal(): void
    {
        $this->fakeWoovi([
            'api.woovi.test/api/v1/partner/application' => Http::response(['error' => 'Missing scope PARTNER_APPLICATION_POST'], 403),
        ]);

        $this->artisan('med:importar-contas')->assertFailed();

        $this->assertSame(1, $this->applicationRequests()->count());
        $this->assertSame(0, WooviAccount::query()->count());
    }

    public function test_active_account_without_tax_id_blocks_creation_so_the_seller_is_never_duplicated(): void
    {
        WooviAccount::factory()->create(['name' => 'Cadastrada sem CNPJ', 'tax_id' => null, 'woovi_company_id' => null]);
        $this->fakeWoovi();

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('contas ativas sem CPF/CNPJ (preencha antes de importar): Cadastrada sem CNPJ')
            ->assertFailed();

        $this->assertSame(1, WooviAccount::query()->count());
        $this->assertSame(0, $this->applicationRequests()->count());
    }

    public function test_account_left_without_webhooks_is_wired_again_on_the_next_run(): void
    {
        $this->fakeWoovi(['api.woovi.test/api/v1/webhook?*' => Http::response(['error' => 'instável'], 500)]);
        $this->artisan('med:importar-contas')->assertFailed();
        $this->assertNull(WooviAccount::query()->where('tax_id', self::LOJA)->sole()->webhooks_registered_at);

        Http::swap(new Factory);
        $this->fakeWoovi();
        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('criadas aqui: 0 · já existiam: 2')
            ->assertSuccessful();

        $this->assertNotNull(WooviAccount::query()->where('tax_id', self::LOJA)->sole()->webhooks_registered_at);
        $this->assertSame(0, $this->applicationRequests()->count());
    }

    public function test_a_second_import_while_one_is_running_does_nothing(): void
    {
        $this->fakeWoovi();
        $lock = Cache::lock('med:importar-contas', 60);
        $lock->get();

        try {
            $this->artisan('med:importar-contas')
                ->expectsOutputToContain('Já há uma importação rodando')
                ->assertSuccessful();
        } finally {
            $lock->release();
        }

        Http::assertNothingSent();
    }

    public function test_unico_api_sending_an_imported_cnpj_adopts_the_account_instead_of_duplicating(): void
    {
        $this->fakeWoovi();
        $this->artisan('med:importar-contas')->assertSuccessful();
        config(['services.api.key' => 'segredo']);

        $this->putJson('/api/woovi-accounts', [
            'account_id' => '4321',
            'name' => 'Loja do Zé',
            'app_id' => 'chave-do-unico-api',
            'tax_id' => '65.914.571/0001-87',
        ], ['X-API-Key' => 'segredo'])->assertOk();

        $this->assertSame(2, WooviAccount::query()->count());
        $loja = WooviAccount::query()->where('tax_id', self::LOJA)->sole();
        $this->assertSame('4321', $loja->unicopag_account_id);
        $this->assertSame(WooviAccountSource::UNICO_API, $loja->source);
    }

    public function test_master_without_partner_feature_aborts_with_the_woovi_message(): void
    {
        $this->fakeWoovi([
            'api.woovi.test/api/v1/partner/affiliate?*' => Http::response(['error' => 'You need feature PARTNER to access this endpoint'], 403),
        ]);

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('Importação abortada: Woovi recusou a listagem das subcontas (parceiro) (HTTP 403): You need feature PARTNER')
            ->assertFailed();

        $this->assertSame(0, WooviAccount::query()->count());
    }

    public function test_preregistrations_unavailable_do_not_block_the_affiliates(): void
    {
        $this->fakeWoovi([
            'api.woovi.test/api/v1/partner/company?*' => Http::response(['error' => 'forbidden'], 403),
        ]);

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('Pré-cadastros não lidos')
            ->expectsOutputToContain('criadas aqui: 2')
            ->assertSuccessful();

        $this->assertNull(WooviAccount::query()->where('tax_id', self::LOJA)->sole()->contact_email);
    }

    public function test_without_the_master_app_id_the_command_explains_and_fails(): void
    {
        config(['services.woovi.master_app_id' => null]);
        Http::fake();

        $this->artisan('med:importar-contas')
            ->expectsOutputToContain('WOOVI_MASTER_APP_ID vazio')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_import_is_scheduled_hourly_only_when_the_master_app_id_is_set(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($event): bool => str_contains((string) $event->command, 'med:importar-contas'));

        $this->assertNotNull($event);
        $this->assertSame('0 * * * *', $event->expression);
        $this->assertTrue($event->filtersPass(app()));

        config(['services.woovi.master_app_id' => '']);
        $this->assertFalse($event->filtersPass(app()));
    }
}
