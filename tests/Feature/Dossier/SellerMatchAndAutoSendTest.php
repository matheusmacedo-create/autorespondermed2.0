<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\GenerateDefenseLetterAction;
use App\Actions\Med\SendEvidenceToWooviAction;
use App\Actions\Med\SubmitSellerDefenseAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\EvidenceKind;
use App\Livewire\Med\SellerDefenseForm;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Support\DisputeLock;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use RuntimeException;

class SellerMatchAndAutoSendTest extends DossierTestCase
{
    private function defend(Dispute $dispute, array $files = [], array $details = []): Dispute
    {
        return app(SubmitSellerDefenseAction::class)->handle(
            $dispute,
            'José Seller',
            'Vendi o fone ao comprador, que retirou o produto com nota fiscal emitida.',
            $files,
            ['ip_address' => '10.0.0.1'],
            $details,
        )->fresh();
    }

    public function test_seller_form_shows_what_we_already_have_and_files_become_optional(): void
    {
        $dispute = $this->receive();

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->assertSee('A ÚnicoPag já reuniu para a sua defesa')
            ->assertSee('Comprovante oficial do Pix recebido (Woovi)')
            ->assertSee('Pedido localizado na plataforma ÚnicoPag')
            // Entrega e pedido já conferidos: o formulário não pede de novo.
            ->assertDontSee('Código de rastreio')
            ->assertDontSee('Número do pedido')
            // E não mostra dado do pagador no link público.
            ->assertDontSee('Maria Compradora')
            ->set('contactName', 'José Seller')
            ->set('statement', 'Vendi o fone ao comprador, que retirou o produto com nota fiscal emitida.')
            ->set('declaration', true)
            ->set('evidences', [])
            ->call('submit')
            ->assertHasNoErrors()
            ->assertSet('submitted', true);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->fresh()->status);
    }

    public function test_form_asks_only_what_the_dossier_could_not_find(): void
    {
        config(['services.unicopag.sale_endpoint' => null]);
        $dispute = $this->receive();

        Livewire::test(SellerDefenseForm::class, ['token' => $dispute->token])
            ->assertSee('Número do pedido')
            ->assertSee('Código de rastreio')
            ->set('contactName', 'José Seller')
            ->set('statement', 'Vendi o fone ao comprador e enviei pelos Correios com rastreio.')
            ->set('declaration', true)
            ->set('trackingCode', 'QB999999999BR')
            ->set('orderReference', '42')
            ->set('evidences', [])
            ->call('submit')
            ->assertHasNoErrors();

        $dispute->refresh();
        $this->assertSame(['order_reference' => '42', 'tracking_code' => 'QB999999999BR'], $dispute->seller_details);
        // O rastreio do seller completou a conferência de entrega.
        $this->assertSame('ok', $this->checks($dispute)['delivery']['status']);
    }

    public function test_file_identical_to_one_the_case_already_has_is_skipped(): void
    {
        $dispute = $this->receive();
        $invoice = $dispute->evidences->firstWhere('kind', EvidenceKind::INVOICE);
        $same = UploadedFile::fake()->createWithContent('minha-nf.pdf', '%PDF-1.4 nota fiscal 42');
        $new = UploadedFile::fake()->createWithContent('conversa.pdf', '%PDF-1.4 conversa whatsapp');

        $dispute = $this->defend($dispute, [
            ['kind' => 'invoice', 'description' => 'Nota fiscal da venda', 'file' => $same],
            ['kind' => 'conversation', 'description' => 'Conversa com o comprador', 'file' => $new],
        ]);

        $this->assertNotNull($invoice);
        $this->assertSame(1, $dispute->evidences()->where('uploaded_by', 'seller')->count());
        $this->assertSame(1, $dispute->seller_submission_metadata['duplicates_skipped']);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::DUPLICATE_EVIDENCE_SKIPPED->value]);
    }

    public function test_seller_defense_is_matched_and_sent_automatically_when_every_guard_passes(): void
    {
        config(['med.envio_automatico.habilitado' => true]);
        $this->account->forceFill(['auto_send' => true])->save();
        $dispute = $this->receive();

        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $dispute->status);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::AUTO_SENT->value]);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::EVIDENCE_SENT->value, 'source' => 'system']);

        // Um envio só, consolidado: comprovante, registro da venda, nota da plataforma e a carta.
        $evidenceCalls = collect(Http::recorded())->filter(fn ($pair) => str_ends_with($pair[0]->url(), '/evidence'));
        $this->assertCount(1, $evidenceCalls);
        $this->assertCount(4, $evidenceCalls->first()[0]->data()['documents']);
        $this->assertSame(0, $dispute->evidences()->whereNull('sent_at')->count());
    }

    public function test_auto_send_stays_with_the_analyst_when_a_guard_fails(): void
    {
        config(['med.envio_automatico.habilitado' => true, 'med.envio_automatico.teto_centavos' => 10000]);
        $this->account->forceFill(['auto_send' => true])->save();

        $dispute = $this->defend($this->receive());

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $event = $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole();
        $this->assertStringContainsString('valor acima do teto', $event->message);
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/evidence'));
    }

    public function test_hijacked_account_claims_always_go_to_the_analyst(): void
    {
        config(['med.envio_automatico.habilitado' => true]);
        $this->account->forceFill(['auto_send' => true])->save();

        $dispute = $this->receive(['situationType' => 'ACCOUNT_TAKEOVER']);
        $dispute = $this->defend($dispute);

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('ACCOUNT_TAKEOVER', $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole()->message);
    }

    public function test_account_without_opt_in_never_auto_sends_and_makes_no_noise(): void
    {
        config(['med.envio_automatico.habilitado' => true]);

        $dispute = $this->defend($this->receive());

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertSame(0, $dispute->events()->whereIn('event', [DisputeEventType::AUTO_SENT->value, DisputeEventType::AUTO_SEND_SKIPPED->value])->count());
    }

    public function test_regenerated_letter_gets_a_new_correlation_id_before_it_is_sent(): void
    {
        $dispute = $this->defend($this->receive());
        $letters = app(GenerateDefenseLetterAction::class);

        $first = $letters->handle($dispute->fresh());
        $first->forceFill(['woovi_file_id' => 'file-velho'])->save();
        $correlation = $first->correlation_id;

        $dispute->forceFill(['analyst_notes' => 'Venda conferida pela operação.'])->save();
        $second = $letters->handle($dispute->fresh());

        // Mesma linha (não enviada), mas outro correlationID: com o antigo, o upload
        // idempotente da Woovi devolveria o PDF velho.
        $this->assertSame($first->id, $second->id);
        $this->assertNotSame($correlation, $second->correlation_id);
        $this->assertNull($second->woovi_file_id);

        // Nada mudou: nem linha nova nem correlationID novo.
        $third = $letters->handle($dispute->fresh());
        $this->assertSame($second->correlation_id, $third->correlation_id);
    }

    public function test_letter_already_sent_is_not_resent_when_nothing_changed(): void
    {
        $dispute = $this->defend($this->receive());
        app(SendEvidenceToWooviAction::class)->handle($dispute->fresh());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Nenhuma evidência nova');

        app(SendEvidenceToWooviAction::class)->handle($dispute->fresh());
    }

    public function test_two_sends_of_the_same_case_cannot_run_at_once(): void
    {
        $dispute = $this->defend($this->receive());
        $lock = Cache::lock(DisputeLock::key($dispute), 300);
        $lock->get();

        try {
            app(SendEvidenceToWooviAction::class)->handle($dispute);
            $this->fail('Era para recusar o envio concorrente.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('sendo processado', $e->getMessage());
        } finally {
            $lock->release();
        }

        $this->assertSame(0, DisputeEvidence::query()->whereNotNull('sent_at')->count());
    }
}
