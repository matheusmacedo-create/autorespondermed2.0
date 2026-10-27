<?php

namespace Tests\Feature\Console;

use App\Actions\Med\GenerateAiDefenseAction;
use App\Enums\DisputeStatus;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        $checks = collect(['sale_found', 'sale_amount_matches', 'no_refund', 'payer_is_customer'])
            ->map(fn (string $key): array => ['key' => $key, 'label' => $key, 'status' => 'ok', 'detail' => ''])->all();

        $dispute = Dispute::factory()->create(array_merge([
            'opened_at' => now()->subDay(),
            'respond_by' => now()->addDays(2),
            'woovi_status' => 'ACKNOWLEDGED',
            'dossier_match' => ['ready' => true, 'checks' => $checks],
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
            ->expectsOutputToContain('rascunho da IA de regras antigas')
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
}
