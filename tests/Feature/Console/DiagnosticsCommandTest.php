<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Models\WooviAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DiagnosticsCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config([
            'app.url' => 'https://med.unicopag.com.br',
            'app.debug' => false,
            'queue.default' => 'database',
            'services.api.key' => 'segredo',
            'mail.default' => 'smtp',
        ]);
        User::factory()->create();
        WooviAccount::factory()->create(['webhooks_registered_at' => now()]);
    }

    public function test_healthy_environment_passes(): void
    {
        Cache::forever('med:scheduler:heartbeat', now()->toIso8601String());

        $this->artisan('med:diagnostico')
            ->expectsOutputToContain('Ambiente ok.')
            ->assertSuccessful();
    }

    public function test_scheduler_that_never_ran_fails(): void
    {
        $this->artisan('med:diagnostico')
            ->expectsOutputToContain('nunca rodou')
            ->assertFailed();
    }

    public function test_the_scheduled_heartbeat_is_what_makes_it_pass(): void
    {
        Artisan::call('schedule:run');

        $this->artisan('med:diagnostico')->assertSuccessful();
    }

    public function test_stale_scheduler_and_stuck_queue_fail(): void
    {
        Cache::forever('med:scheduler:heartbeat', now()->subMinutes(30)->toIso8601String());
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subMinutes(20)->timestamp,
            'created_at' => now()->subMinutes(20)->timestamp,
        ]);

        $this->artisan('med:diagnostico')
            ->expectsOutputToContain('parado')
            ->expectsOutputToContain('daemon queue:work não está rodando')
            ->assertFailed();
    }

    public function test_production_with_unsafe_settings_fails(): void
    {
        Cache::forever('med:scheduler:heartbeat', now()->toIso8601String());
        app()->detectEnvironment(fn () => 'production');
        config(['app.debug' => true, 'mail.default' => 'log', 'services.woovi.webhook.verify_signature' => false]);

        $this->artisan('med:diagnostico')
            ->expectsOutputToContain('DESLIGADA')
            ->assertFailed();
    }
}
