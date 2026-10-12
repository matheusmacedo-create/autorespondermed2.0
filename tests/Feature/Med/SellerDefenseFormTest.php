<?php

namespace Tests\Feature\Med;

use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Livewire\Med\SellerDefenseForm;
use App\Models\Dispute;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class SellerDefenseFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_link_opens_the_form_and_records_the_first_visit(): void
    {
        $dispute = Dispute::factory()->create();

        $this->get(route('med.seller', $dispute->token))
            ->assertOk()
            ->assertSee('Contestação de Pix (MED)')
            ->assertSee('R$ 150,00')
            ->assertSee($dispute->end_to_end_id);

        $this->assertNotNull($dispute->fresh()->seller_opened_at);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::SELLER_OPENED_LINK->value]);
    }

    public function test_invalid_token_shows_an_error(): void
    {
        $this->get(route('med.seller', 'token-que-nao-existe'))
            ->assertOk()
            ->assertSee('Link inválido');
    }

    public function test_seller_submits_the_defense_with_evidence(): void
    {
        $dispute = Dispute::factory()->create();

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->set('contactName', 'Maria Seller')
            ->set('statement', 'Vendi um celular ao comprador e entreguei em mãos com recibo assinado no dia 10.')
            ->set('declaration', true)
            ->set('evidences.0.kind', 'invoice')
            ->set('evidences.0.description', 'Nota fiscal da venda do celular')
            ->set('evidences.0.file', UploadedFile::fake()->create('nota.pdf', 120, 'application/pdf'))
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true)
            ->assertSee('Recebemos sua defesa');

        $dispute->refresh();

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertSame('Maria Seller', $dispute->seller_contact_name);
        $this->assertNotNull($dispute->seller_submitted_at);
        $this->assertSame(1, $dispute->evidences()->count());

        $evidence = $dispute->evidences()->sole();
        $this->assertSame('seller', $evidence->uploaded_by);
        Storage::disk('local')->assertExists($evidence->path);

        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::SELLER_SUBMITTED->value]);
    }

    public function test_first_defense_requires_at_least_one_file(): void
    {
        $dispute = Dispute::factory()->create();

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->set('contactName', 'Maria Seller')
            ->set('statement', 'Vendi um celular ao comprador e entreguei em mãos com recibo assinado.')
            ->set('declaration', true)
            ->call('submit')
            ->assertHasErrors('evidences');

        $this->assertSame(DisputeStatus::PENDING_SELLER, $dispute->fresh()->status);
    }

    public function test_rejects_file_types_the_woovi_does_not_accept(): void
    {
        $dispute = Dispute::factory()->create();

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->set('evidences.0.file', UploadedFile::fake()->create('planilha.xlsx', 10, 'application/vnd.ms-excel'))
            ->assertHasErrors('evidences.0.file');
    }

    public function test_complement_is_appended_to_the_previous_statement(): void
    {
        $dispute = Dispute::factory()->submitted()->create([
            'status' => DisputeStatus::PENDING_SELLER,
            'complement_request' => 'Envie o comprovante de entrega.',
        ]);

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->assertSee('Envie o comprovante de entrega.')
            ->set('contactName', 'Maria Seller')
            ->set('statement', 'Segue o comprovante de entrega dos Correios com a assinatura do comprador.')
            ->set('declaration', true)
            ->set('evidences.0.kind', 'delivery_proof')
            ->set('evidences.0.description', 'Rastreio dos Correios entregue')
            ->set('evidences.0.file', UploadedFile::fake()->image('rastreio.png'))
            ->call('submit')
            ->assertHasNoErrors();

        $dispute->refresh();

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertNull($dispute->complement_request);
        $this->assertStringContainsString('entreguei em mãos', $dispute->seller_statement);
        $this->assertStringContainsString('--- Complemento em', $dispute->seller_statement);
        $this->assertStringContainsString('comprovante de entrega dos Correios', $dispute->seller_statement);
    }

    public function test_expired_link_does_not_accept_a_defense(): void
    {
        $dispute = Dispute::factory()->create(['token_expires_at' => now()->subMinute()]);

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->assertSee('O prazo para enviar a defesa por este link terminou');
    }

    public function test_closed_case_shows_the_result_instead_of_the_form(): void
    {
        $dispute = Dispute::factory()->create(['status' => DisputeStatus::REJECTED, 'closed_at' => now()]);

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->assertSee('Este caso já foi encerrado')
            ->assertDontSee('Enviar defesa');
    }

    public function test_tab_left_open_after_the_case_moved_on_does_not_overwrite_it(): void
    {
        $dispute = Dispute::factory()->create();

        $component = Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token]);

        // Enquanto a aba estava aberta, o caso foi encerrado pela Woovi.
        $dispute->forceFill(['status' => DisputeStatus::ACCEPTED, 'closed_at' => now()])->save();

        $component
            ->set('contactName', 'Maria Seller')
            ->set('statement', 'Vendi um celular ao comprador e entreguei em mãos com recibo assinado.')
            ->set('declaration', true)
            ->set('evidences.0.kind', 'invoice')
            ->set('evidences.0.description', 'Nota fiscal da venda')
            ->set('evidences.0.file', UploadedFile::fake()->create('nota.pdf', 10, 'application/pdf'))
            ->call('submit')
            ->assertSet('submitted', false)
            ->assertSee('Este caso já foi encerrado');

        $this->assertSame(DisputeStatus::ACCEPTED, $dispute->fresh()->status);
        $this->assertSame(0, $dispute->evidences()->count());
    }
}
