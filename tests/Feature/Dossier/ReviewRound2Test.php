<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\MatchDossierAction;
use App\Actions\Med\SubmitSellerDefenseAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\DossierSource;
use App\Enums\EvidenceKind;
use App\Enums\SourceStatus;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Models\Dispute;
use App\Models\User;
use App\Support\DisputeLock;
use Dotenv\Dotenv;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * Um teste por achado confirmado na segunda rodada da revisão do dossiê (12/10/2026).
 */
class ReviewRound2Test extends DossierTestCase
{
    private function refake(): void
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->fakeAll();
    }

    private function defend(Dispute $dispute): Dispute
    {
        return app(SubmitSellerDefenseAction::class)->handle(
            $dispute->fresh(), 'José Seller', 'Vendi o fone ao comprador, que retirou o produto com nota fiscal emitida.', [], [], [],
        )->fresh();
    }

    private function enableAutoSend(): void
    {
        config(['med.envio_automatico.habilitado' => true]);
        $this->account->forceFill(['auto_send' => true])->save();
    }

    private function skipReason(Dispute $dispute): string
    {
        return (string) $dispute->events()->where('event', DisputeEventType::AUTO_SEND_SKIPPED->value)->sole()->message;
    }

    private function saleWithPlatformDeliveryProof(): void
    {
        $this->fakes['sale'] = Http::response(['data' => [
            'amount_total' => 25000,
            'fulfillment' => ['status' => 'pending'],
            'documents' => [['kind' => 'delivery_proof', 'url' => 'https://files.unicopag.test/entrega-42.pdf']],
        ]]);
    }

    public function test_discarding_the_platform_delivery_proof_takes_it_out_of_the_letter(): void
    {
        $this->saleWithPlatformDeliveryProof();
        $dispute = $this->defend($this->receive());
        $proof = $dispute->evidences()->where('kind', EvidenceKind::DELIVERY_PROOF->value)->sole();
        $this->assertSame(MatchDossierAction::OK, $this->checks($dispute)['delivery']['status']);

        $facts = null;
        View::composer('pdf.defense-letter', function ($view) use (&$facts): void {
            $facts = $view->getData()['facts'] ?? null;
        });

        $this->actingAs(User::factory()->create());
        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('discardEvidence', data: ['evidence_id' => $proof->id, 'reason' => 'Comprovante de outro comprador.'])
            ->assertHasNoActionErrors();

        $this->assertNotSame(MatchDossierAction::OK, $this->checks($dispute)['delivery']['status']);

        $this->refake();
        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])->callAction('sendToWoovi');

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $dispute->fresh()->status);
        $this->assertIsArray($facts);
        $this->assertFalse(collect($facts)->contains(fn ($fact): bool => str_contains((string) $fact, 'comprovante de entrega')));
    }

    public function test_discard_waits_for_nothing_while_the_case_is_being_sent(): void
    {
        $dispute = $this->defend($this->receive());
        $invoice = $dispute->evidences()->where('kind', EvidenceKind::INVOICE->value)->sole();
        $this->actingAs(User::factory()->create());
        $lock = Cache::lock(DisputeLock::key($dispute), 300);
        $lock->get();

        try {
            Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
                ->callAction('discardEvidence', data: ['evidence_id' => $invoice->id, 'reason' => 'Nota de outro pedido.'])
                ->assertNotified('Caso ocupado');
        } finally {
            $lock->release();
        }

        $this->assertFalse($invoice->fresh()->trashed());
    }

    public function test_rebuild_button_on_a_busy_case_warns_instead_of_failing(): void
    {
        $dispute = $this->receive();
        $this->actingAs(User::factory()->create());
        $lock = Cache::lock(DisputeLock::key($dispute), 300);
        $lock->get();

        try {
            Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
                ->callAction('rebuildDossier')
                ->assertNotified('Caso ocupado');
        } finally {
            $lock->release();
        }
    }

    public function test_build_command_skips_a_busy_case_and_goes_on(): void
    {
        $busy = $this->receive();
        $other = $this->receive(['endToEndId' => 'E18236120202610121530s0133872OUT']);
        $lock = Cache::lock(DisputeLock::key($busy), 300);
        $lock->get();
        $this->refake();

        try {
            $this->artisan('med:montar-dossie', ['--caso' => [$busy->id, $other->id], '--refazer' => true])
                ->expectsOutputToContain("Caso {$other->id}")
                ->assertFailed();
        } finally {
            $lock->release();
        }
    }

    public function test_claim_types_outside_the_allowlist_go_to_the_analyst(): void
    {
        $this->enableAutoSend();

        foreach (['UNKNOWN', 'OTHER', 'NEW_WOOVI_TYPE'] as $i => $situation) {
            $e2e = "E18236120202610121530s01338720{$i}";
            $this->fakes['funds'] = Http::response([['type' => 'MED', 'situationType' => $situation, 'endToEndId' => $e2e]]);
            Http::swap(new Factory);
            $dispute = $this->defend($this->receive(['endToEndId' => $e2e]));

            $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status, $situation);
            $this->assertStringContainsString("alegação {$situation}", $this->skipReason($dispute));
        }
    }

    public function test_account_without_platform_link_does_not_skip_the_kyc_check(): void
    {
        $this->enableAutoSend();
        $this->account->forceFill(['unicopag_account_id' => null])->save();

        $dispute = $this->defend($this->receive());

        $this->assertSame(SourceStatus::SKIPPED, $dispute->sources->firstWhere('source', DossierSource::KYC_STATUS)->status);
        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('KYC não conferido', $this->skipReason($dispute));
    }

    public function test_hijacked_account_reason_from_funds_recovery_blocks_the_auto_send(): void
    {
        $this->enableAutoSend();
        $this->fakes['funds'] = Http::response([[
            'type' => 'MED', 'situationType' => 'SCAM', 'endToEndId' => self::E2E,
            'disputeReason' => 'Invasão da conta do pagador pelo aplicativo.',
        ]]);

        $dispute = $this->defend($this->receive(['disputeReason' => null]));

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->status);
        $this->assertStringContainsString('conta invadida', $this->skipReason($dispute));
    }

    public function test_env_example_parses_and_keeps_the_reason_list(): void
    {
        $values = Dotenv::parse((string) file_get_contents(base_path('.env.example')));

        $this->assertStringContainsString('acesso indevido', $values['MED_ENVIO_AUTOMATICO_MOTIVOS_BLOQUEADOS']);
        $this->assertSame('SCAM', $values['MED_ENVIO_AUTOMATICO_SITUACOES_PERMITIDAS']);
    }

    public function test_failed_platform_download_does_not_record_the_presigned_url(): void
    {
        $this->fakes['sale'] = Http::response(['data' => [
            'amount_total' => 25000,
            'documents' => [['kind' => 'invoice', 'url' => 'https://files.unicopag.test/nf-42.pdf?X-Amz-Signature=segredo123']],
        ]]);
        $this->fakes['invoice'] = fn () => throw new ConnectionException('cURL error 28: timeout for https://files.unicopag.test/nf-42.pdf?X-Amz-Signature=segredo123');

        $dispute = $this->receive();

        $messages = $dispute->events()->pluck('message')->implode("\n");
        $this->assertStringContainsString('Documento da plataforma não importado', $messages);
        $this->assertStringNotContainsString('segredo123', $messages);
        $this->assertStringNotContainsString('nf-42.pdf', $messages);
    }

    public function test_source_error_from_a_failed_reread_clears_on_the_next_build(): void
    {
        $dispute = $this->receive();
        $this->fakes['kyc'] = Http::response('erro', 500);
        $this->refake();
        app(BuildDossierAction::class)->handle($dispute->fresh(), refresh: true);
        $this->assertSame('partial', $dispute->fresh()->dossier_status);

        $this->fakes['kyc'] = Http::response(['status' => 'approved']);
        $this->refake();
        app(BuildDossierAction::class)->handle($dispute->fresh());

        $kyc = $dispute->fresh()->sources->firstWhere('source', DossierSource::KYC_STATUS);
        $this->assertNull($kyc->error);
        $this->assertSame('ready', $dispute->fresh()->dossier_status);
    }

    public function test_analyst_reattaching_a_discarded_file_brings_it_back(): void
    {
        $dispute = $this->defend($this->receive());
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 contrato assinado');

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('attachEvidence', data: ['kind' => EvidenceKind::CONTRACT->value, 'description' => 'Contrato', 'file' => $file]);
        $contract = $dispute->evidences()->where('kind', EvidenceKind::CONTRACT->value)->sole();

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('discardEvidence', data: ['evidence_id' => $contract->id, 'reason' => 'Anexei por engano.']);
        $this->assertTrue($contract->fresh()->trashed());

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('attachEvidence', data: ['kind' => EvidenceKind::CONTRACT->value, 'description' => 'Contrato', 'file' => UploadedFile::fake()->createWithContent('contrato.pdf', '%PDF-1.4 contrato assinado')])
            ->assertNotified('Evidência anexada');

        $this->assertFalse($contract->fresh()->trashed());
        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/evidence'));
    }
}
