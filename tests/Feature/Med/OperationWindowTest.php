<?php

namespace Tests\Feature\Med;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use App\Models\WooviAccount;
use App\Services\SellerNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Operação organizada antes de falar com o lojista: só os MEDs da janela de
 * trabalho aparecem, o resto sai com `med:podar`, e nenhum aviso sai com
 * MED_AVISO_LOJISTA desligado.
 */
class OperationWindowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['med.janela_dias' => 14]);
    }

    public function test_panel_shows_only_cases_opened_inside_the_window(): void
    {
        $this->actingAs(User::factory()->create());
        $recent = Dispute::factory()->create(['opened_at' => now()->subDays(3)]);
        $old = Dispute::factory()->create(['opened_at' => now()->subDays(20)]);

        Livewire::test(ListDisputes::class)
            ->assertCanSeeTableRecords([$recent])
            ->assertCanNotSeeTableRecords([$old]);

        $this->get("/admin/disputes/{$old->id}")->assertNotFound();
    }

    public function test_queue_shows_the_merchant_from_the_sale_and_flags_the_unknown_ones(): void
    {
        $this->actingAs(User::factory()->create());
        Dispute::factory()->create(['opened_at' => now(), 'merchant_account_id' => '4321', 'merchant' => ['trade_name' => 'Loja do Zé', 'tax_id' => '12345678000190']]);
        Dispute::factory()->create(['opened_at' => now()]);

        Livewire::test(ListDisputes::class)
            ->assertSee('Loja do Zé')
            ->assertSee('Não identificado');

        Livewire::test(ListDisputes::class, ['activeTab' => 'sem_lojista'])->assertCountTableRecords(1);
    }

    public function test_prune_removes_old_and_demo_cases_and_keeps_what_was_sent(): void
    {
        $demo = WooviAccount::factory()->create(['name' => 'Loja demo']);
        $demoCase = Dispute::factory()->for($demo)->create(['opened_at' => now()]);
        $file = DisputeEvidence::factory()->for($demoCase)->create();
        Storage::disk($file->disk)->put($file->path, 'x');
        $old = Dispute::factory()->create(['opened_at' => now()->subDays(30)]);
        $oldSent = Dispute::factory()->create(['opened_at' => now()->subDays(30), 'evidence_sent_at' => now()->subDays(29)]);
        $recent = Dispute::factory()->create(['opened_at' => now()->subDay()]);

        $this->artisan('med:podar', ['--conta' => [$demo->id]])->assertSuccessful();
        $this->assertSame(4, Dispute::query()->count(), 'sem --confirmar nada sai');

        $this->artisan('med:podar', ['--conta' => [$demo->id], '--confirmar' => true])->assertSuccessful();

        $this->assertModelMissing($demoCase);
        $this->assertModelMissing($demo);
        $this->assertModelMissing($old);
        $this->assertModelExists($oldSent);
        $this->assertModelExists($recent);
        Storage::disk($file->disk)->assertMissing($file->path);
        $this->assertDatabaseMissing('dispute_events', ['dispute_id' => $old->id]);
    }

    public function test_no_notice_leaves_while_notices_are_off(): void
    {
        config(['med.aviso_lojista' => false]);
        Mail::fake();
        $dispute = Dispute::factory()->create(['opened_at' => now()]);

        $this->assertFalse(app(SellerNotifier::class)->disputeOpened($dispute));
        app(SellerNotifier::class)->disputeClosed($dispute);

        Mail::assertNothingSent();
        $this->assertNull($dispute->fresh()->seller_notified_at);
    }

    public function test_queue_shows_the_nearest_deadline_first_and_leaves_the_overdue_out(): void
    {
        $this->actingAs(User::factory()->create());
        $later = Dispute::factory()->create(['opened_at' => now()->subDay(), 'respond_by' => now()->addHours(40)]);
        $sooner = Dispute::factory()->create(['opened_at' => now()->subDay(), 'respond_by' => now()->addHours(3)]);
        $overdue = Dispute::factory()->create(['opened_at' => now()->subDays(4), 'respond_by' => now()->subHours(5)]);

        Livewire::test(ListDisputes::class)
            ->assertCanSeeTableRecords([$sooner, $later], inOrder: true)
            ->assertCanNotSeeTableRecords([$overdue]);

        Livewire::test(ListDisputes::class, ['activeTab' => 'vencidos'])
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$sooner, $later]);
    }
}
