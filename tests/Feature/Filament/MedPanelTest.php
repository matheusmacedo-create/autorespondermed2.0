<?php

namespace Tests\Feature\Filament;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\WooviAccounts\Pages\CreateWooviAccount;
use App\Filament\Resources\WooviAccounts\Pages\EditWooviAccount;
use App\Filament\Widgets\MedResultsWidget;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use App\Models\WooviAccount;
use App\Services\DisputeEventRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class MedPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_dashboard_and_queue_render(): void
    {
        Dispute::factory()->submitted()->count(2)->create();
        Dispute::factory()->create();

        $this->get('/admin')->assertOk()->assertSee('MEDs para defender');

        // Primeira aba: tudo que ainda pede defesa (aberto ou com resposta do lojista).
        Livewire::test(ListDisputes::class)
            ->assertOk()
            ->assertCountTableRecords(3);
    }

    public function test_results_widget_counts_only_what_we_answered(): void
    {
        Dispute::factory()->count(2)->create(['status' => DisputeStatus::EVIDENCE_SENT, 'evidence_sent_at' => now()->subDay()]);
        Dispute::factory()->create(['status' => DisputeStatus::REJECTED, 'woovi_status' => 'REJECTED', 'evidence_sent_at' => now()->subDays(2), 'closed_at' => now()]);
        Dispute::factory()->create(['status' => DisputeStatus::ACCEPTED, 'woovi_status' => 'ACCEPTED', 'evidence_sent_at' => now()->subDays(2), 'closed_at' => now()]);
        // Decidido sem defesa nossa: fica só na comparação.
        Dispute::factory()->create(['status' => DisputeStatus::REJECTED, 'woovi_status' => 'REJECTED', 'closed_at' => now()]);

        Livewire::test(MedResultsWidget::class)
            ->assertOk()
            ->assertSeeInOrder(['Respondidos à Woovi', '4', 'R$ 600,00'])
            ->assertSeeInOrder(['Aguardando decisão', '2', 'R$ 300,00'])
            ->assertSeeInOrder(['Ganhos', '1', 'R$ 150,00 mantidos · 50% dos decididos'])
            ->assertSeeInOrder(['Perdidos', '1', 'R$ 150,00 devolvidos ao pagador'])
            ->assertSee('a Woovi decidiu 1 MED(s) da janela - 1 ganho(s) e 0 perdido(s)');

        $this->get('/admin')->assertOk()->assertSee('Resultado das respostas');
    }

    public function test_case_page_shows_the_defense_evidence_and_timeline(): void
    {
        $dispute = Dispute::factory()->submitted()->create();
        $evidence = DisputeEvidence::factory()->for($dispute)->create(['original_name' => 'nota-fiscal.pdf']);
        app(DisputeEventRecorder::class)->record(DisputeEventType::SELLER_SUBMITTED, $dispute, 'Seller enviou a defesa com 1 arquivo(s).', source: 'seller');

        $this->get("/admin/disputes/{$dispute->id}")
            ->assertOk()
            ->assertSee('MED R$ 150,00 - lojista não identificado')
            ->assertSee('entreguei em mãos')
            ->assertSee('nota-fiscal.pdf')
            ->assertSee('Seller enviou a defesa com 1 arquivo(s).')
            ->assertSee($dispute->end_to_end_id);

        $this->get(route('admin.evidence.download', $evidence))->assertOk();
    }

    public function test_evidence_download_requires_login(): void
    {
        $evidence = DisputeEvidence::factory()->create();

        auth()->logout();

        $this->get(route('admin.evidence.download', $evidence))->assertRedirect('/admin/login');
    }

    public function test_request_complement_action_returns_the_case_to_the_seller(): void
    {
        Mail::fake();
        $dispute = Dispute::factory()->submitted()->create();

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('requestComplement', data: ['message' => 'Envie o comprovante de entrega assinado.'])
            ->assertHasNoActionErrors();

        $dispute->refresh();
        $this->assertSame(DisputeStatus::PENDING_SELLER, $dispute->status);
        $this->assertSame('Envie o comprovante de entrega assinado.', $dispute->complement_request);
        Mail::assertSent(DisputeOpenedMail::class, fn (DisputeOpenedMail $mail) => $mail->complementRequest === 'Envie o comprovante de entrega assinado.');
    }

    public function test_analyst_attaches_evidence_from_the_case_page(): void
    {
        $dispute = Dispute::factory()->submitted()->create();

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('attachEvidence', data: [
                'kind' => 'delivery_proof',
                'description' => 'Rastreio dos Correios entregue ao comprador',
                'file' => UploadedFile::fake()->create('rastreio.pdf', 50, 'application/pdf'),
            ])
            ->assertHasNoActionErrors();

        $evidence = $dispute->evidences()->sole();
        $this->assertSame('analyst', $evidence->uploaded_by);
        $this->assertSame('rastreio.pdf', $evidence->original_name);
        Storage::disk('local')->assertExists($evidence->path);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => 'analyst_evidence_added']);
    }

    public function test_send_action_is_hidden_for_closed_cases(): void
    {
        $dispute = Dispute::factory()->create(['status' => DisputeStatus::ACCEPTED, 'closed_at' => now()]);

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->assertActionHidden('sendToWoovi')
            ->assertActionHidden('requestComplement');
    }

    public function test_create_account_from_the_panel(): void
    {
        Livewire::test(CreateWooviAccount::class)
            ->fillForm([
                'name' => 'Loja Teste',
                'tax_id' => '123.456.789-09',
                'app_id' => 'app-id-secreto',
                'contact_email' => 'loja@teste.com',
                'is_active' => true,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $account = WooviAccount::sole();
        $this->assertSame('app-id-secreto', $account->app_id);
        $this->assertSame('12345678909', $account->tax_id);
    }

    public function test_editing_without_app_id_keeps_the_current_key(): void
    {
        $account = WooviAccount::factory()->create(['app_id' => 'chave-atual']);

        Livewire::test(EditWooviAccount::class, ['record' => $account->getRouteKey()])
            ->assertFormSet(['app_id' => null])
            ->fillForm(['name' => 'Nome editado'])
            ->call('save')
            ->assertHasNoFormErrors();

        $account->refresh();
        $this->assertSame('Nome editado', $account->name);
        $this->assertSame('chave-atual', $account->app_id);
    }
}
