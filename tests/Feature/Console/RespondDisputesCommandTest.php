<?php

namespace Tests\Feature\Console;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Console\Commands\RespondDisputesCommand;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Jobs\GenerateAiDefense;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\MerchantStore;
use App\Services\Defense\DefenseWriter;
use App\Services\DisputeEventRecorder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * med:responder responde em lote só o que já tem tudo, e relê na Woovi antes.
 */
class RespondDisputesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['med.janela_dias' => 14]);
        Http::fake([
            'api.woovi.test/api/v1/dispute/E*/evidence' => Http::response(['documents' => []]),
            'api.woovi.test/api/v1/dispute/ECLOSED*' => Http::response(['dispute' => ['status' => 'ACCEPTED', 'type' => 'MED']]),
            'api.woovi.test/api/v1/dispute/*' => Http::response(['dispute' => ['status' => 'ACKNOWLEDGED', 'type' => 'MED']]),
            'api.woovi.test/api/v1/files' => Http::response(['file' => ['id' => 'file-1', 'url' => 'https://woovi-files.s3.amazonaws.com/x']], 201),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function ready(array $overrides = []): Dispute
    {
        $checks = collect(['transaction_found', 'value_matches', 'sale_found', 'sale_amount_matches', 'no_refund', 'payer_is_customer', 'kyc_approved'])
            ->map(fn (string $key): array => ['key' => $key, 'label' => $key, 'status' => 'ok', 'detail' => ''])->all();

        $dispute = Dispute::factory()->create(array_merge([
            'opened_at' => now()->subDay(),
            'respond_by' => now()->addDays(2),
            'woovi_status' => 'ACKNOWLEDGED',
            'dossier_match' => ['ready' => true, 'checks' => $checks],
            'merchant_account_id' => null,
            'ai_defense' => ['recommendation' => 'defender', 'narrative' => 'Defesa.', 'rules_version' => GenerateAiDefenseAction::RULES_VERSION],
            'defense_text' => 'Defesa.',
        ], $overrides));
        DisputeEvidence::factory()->for($dispute)->create();

        return $dispute;
    }

    public function test_lists_without_sending_and_says_why_each_case_stays(): void
    {
        $ok = $this->ready();
        $this->ready(['ai_defense' => ['recommendation' => 'nao_defender', 'rules_version' => 2]]);
        $this->ready(['ai_defense' => ['recommendation' => 'defender']]);
        $this->ready(['type' => 'CHARGEBACK']);

        $this->artisan('med:responder')
            ->expectsOutputToContain("#{$ok->id} R$ 150,00")
            ->expectsOutputToContain('IA não recomenda defender')
            ->expectsOutputToContain('rascunho da IA ausente ou de regras antigas')
            ->expectsOutputToContain('não é MED (CHARGEBACK)')
            ->expectsOutputToContain('1 de 4 prontos para responder.')
            ->assertSuccessful();

        Http::assertNotSent(fn (Request $r): bool => str_ends_with($r->url(), '/evidence'));
    }

    public function test_sends_only_the_ready_cases_and_rechecks_woovi_first(): void
    {
        $ok = $this->ready();
        $divergent = $this->ready(['dossier_match' => ['ready' => false, 'checks' => []]]);
        $closedOnWoovi = $this->ready(['end_to_end_id' => 'ECLOSED'.str_repeat('X', 25)]);
        $overdue = $this->ready(['respond_by' => now()->subHour()]);

        $this->artisan('med:responder', ['--confirmar' => true])->assertSuccessful();

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $ok->fresh()->status);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $divergent->fresh()->status);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $overdue->fresh()->status);
        // A Woovi já tinha decidido: a releitura encerra o caso e nada é enviado.
        $this->assertSame(DisputeStatus::ACCEPTED, $closedOnWoovi->fresh()->status);
        $evidencePosts = collect(Http::recorded())->filter(fn (array $pair): bool => str_ends_with($pair[0]->url(), '/evidence'));
        $this->assertCount(1, $evidencePosts, 'só o caso pronto foi respondido');
        Http::assertSent(fn (Request $r): bool => $r->method() === 'GET' && str_contains($r->url(), '/dispute/ECLOSED'));
    }

    public function test_overdue_cases_go_only_when_asked(): void
    {
        $overdue = $this->ready(['respond_by' => now()->subHour()]);

        $this->artisan('med:responder', ['--confirmar' => true, '--incluir-vencidos' => true])->assertSuccessful();

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $overdue->fresh()->status);
    }

    /**
     * @param  array<string, string>  $statuses
     */
    private function withChecks(Dispute $dispute, array $statuses): Dispute
    {
        $checks = collect($dispute->dossier_match['checks'])->map(fn (array $c): array => array_key_exists($c['key'], $statuses) ? ['status' => $statuses[$c['key']]] + $c : $c)->all();
        $dispute->forceFill(['dossier_match' => ['ready' => false, 'checks' => $checks]])->save();

        return $dispute->fresh();
    }

    public function test_weak_cases_go_only_when_the_operation_decided_to_contest_them(): void
    {
        $weak = $this->withChecks($this->ready(['ai_defense' => ['recommendation' => 'nao_defender', 'rules_version' => GenerateAiDefenseAction::RULES_VERSION]]), ['payer_is_customer' => 'divergent']);
        $wrongSale = $this->withChecks($this->ready(), ['sale_amount_matches' => 'divergent']);
        $refunded = $this->withChecks($this->ready(), ['no_refund' => 'divergent']);

        $this->artisan('med:responder')->expectsOutputToContain('quem pagou não é o cliente da cobrança')->assertSuccessful();

        config(['med.resposta_automatica.incluir_nao_defender' => true]);
        $this->artisan('med:responder', ['--confirmar' => true])->assertSuccessful();

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $weak->fresh()->status);
        // Dado incoerente nunca sai: pode ser o pedido errado, ou o dinheiro já voltou.
        $this->assertSame(DisputeStatus::PENDING_SELLER, $wrongSale->fresh()->status);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $refunded->fresh()->status);
        $this->assertContains('valor da venda diferente do Pix (pode ser outro pedido)', RespondDisputesCommand::blockers($wrongSale));
        $this->assertContains('já houve devolução', RespondDisputesCommand::blockers($refunded));
    }

    public function test_never_tries_after_woovi_deadline_or_after_woovi_refused_for_it(): void
    {
        $old = $this->ready(['opened_at' => now()->subDays(5), 'respond_by' => now()->subDays(2)]);
        $refused = $this->ready();
        app(DisputeEventRecorder::class)->record(DisputeEventType::EVIDENCE_SEND_FAILED, $refused, 'Woovi recusou o envio das evidências (HTTP 400): Você não pode enviar uma evidência após o prazo ter expirado', level: 'error');
        $failing = $this->ready();
        foreach (range(1, 3) as $i) {
            app(DisputeEventRecorder::class)->record(DisputeEventType::EVIDENCE_SEND_FAILED, $failing, 'Woovi não respondeu.', level: 'error');
        }

        $this->assertContains('prazo da Woovi expirado', RespondDisputesCommand::blockers($old, includeOverdue: true));
        $this->assertContains('a Woovi recusou por prazo expirado', RespondDisputesCommand::blockers($refused, true));
        $this->assertContains('falhou 3 vezes nas últimas 24h - ver a ficha', RespondDisputesCommand::blockers($failing, true));
    }

    public function test_waits_for_the_store_policy_capture_running_now(): void
    {
        $dispute = $this->ready(['merchant_account_id' => '4321']);
        $store = MerchantStore::query()->create(['merchant_account_id' => '4321', 'name' => 'Loja', 'website' => 'https://loja.com.br']);

        $this->assertContains('captura das políticas da loja em andamento', RespondDisputesCommand::blockers($dispute->fresh(), true));

        // Captura que não termina em 2h não segura o caso para sempre.
        $store->forceFill(['updated_at' => now()->subHours(3)])->saveQuietly();
        $this->assertNotContains('captura das políticas da loja em andamento', RespondDisputesCommand::blockers($dispute->fresh(), true));
    }

    public function test_automatic_run_answers_as_the_system_and_keeps_the_recommendation(): void
    {
        $overdueInternally = $this->ready(['respond_by' => now()->subHour(), 'ai_defense' => ['recommendation' => 'defender_com_ressalvas', 'narrative' => 'Defesa.', 'rules_version' => GenerateAiDefenseAction::RULES_VERSION]]);

        $this->artisan('med:responder', ['--automatico' => true, '--confirmar' => true])->assertSuccessful();

        $dispute = $overdueInternally->fresh();
        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $dispute->status);
        $this->assertNull($dispute->reviewed_by);
        $event = $dispute->events()->where('event', DisputeEventType::EVIDENCE_SENT->value)->sole();
        $this->assertSame('system', $event->source);
        $this->assertSame('defender_com_ressalvas', $event->context['ai_recommendation']);
    }

    public function test_hourly_schedule_runs_only_when_turned_on(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e): bool => str_contains((string) $e->command, 'med:responder --automatico --confirmar'));

        $this->assertNotNull($event);
        $this->assertSame('20 * * * *', $event->expression);
        config(['med.resposta_automatica.habilitada' => false]);
        $this->assertFalse($event->filtersPass(app()));
        config(['med.resposta_automatica.habilitada' => true]);
        $this->assertTrue($event->filtersPass(app()));
    }

    public function test_results_cross_woovi_decisions_with_the_recommendation(): void
    {
        $won = $this->ready();
        $lost = $this->ready(['ai_defense' => ['recommendation' => 'nao_defender', 'narrative' => 'x', 'rules_version' => GenerateAiDefenseAction::RULES_VERSION]]);
        config(['med.resposta_automatica.incluir_nao_defender' => true]);
        $this->artisan('med:responder', ['--confirmar' => true])->assertSuccessful();
        $won->forceFill(['woovi_status' => 'REJECTED'])->save();
        $lost->forceFill(['woovi_status' => 'ACCEPTED'])->save();

        $this->artisan('med:resultados')
            ->expectsOutputToContain('Defesas enviadas (MEDs dos últimos 30 dias): 2')
            ->expectsTable(['Recomendação da IA', 'Enviados', 'Ganhos', 'Perdidos', 'Aguardando', 'Taxa de ganho'], [
                ['defender', 1, 1, 0, 0, '100%'],
                ['não defender', 1, 0, 1, 0, '0%'],
            ])
            ->assertSuccessful();
    }

    public function test_automatic_run_asks_a_new_draft_when_only_the_draft_is_missing(): void
    {
        Queue::fake([GenerateAiDefense::class]);
        $this->app->instance(DefenseWriter::class, new class implements DefenseWriter
        {
            public function write(array $case): array
            {
                return [];
            }

            public function isConfigured(): bool
            {
                return true;
            }
        });
        $old = $this->ready(['ai_defense' => ['recommendation' => 'defender', 'rules_version' => 1]]);
        $wrongSale = $this->withChecks($this->ready(['ai_defense' => ['recommendation' => 'defender', 'rules_version' => 1]]), ['sale_amount_matches' => 'divergent']);

        $this->artisan('med:responder', ['--automatico' => true, '--confirmar' => true])->assertSuccessful();

        Queue::assertPushed(GenerateAiDefense::class, fn (GenerateAiDefense $job): bool => $job->disputeId === $old->id);
        // Caso com outra trava não ganha rascunho à toa.
        Queue::assertNotPushed(GenerateAiDefense::class, fn (GenerateAiDefense $job): bool => $job->disputeId === $wrongSale->id);
        $this->assertSame(DisputeStatus::PENDING_SELLER, $old->fresh()->status);
    }
}
