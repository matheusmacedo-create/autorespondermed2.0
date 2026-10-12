<?php

namespace Tests\Feature\Med;

use App\Actions\Med\SendEvidenceToWooviAction;
use App\Enums\DisputeEventType;
use App\Enums\DisputeStatus;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Services\Woovi\WooviException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Tests\TestCase;

class SendEvidenceToWooviTest extends TestCase
{
    use RefreshDatabase;

    private Dispute $dispute;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->dispute = Dispute::factory()->submitted()->create();
        DisputeEvidence::factory()->for($this->dispute)->create();
    }

    public function test_uploads_files_and_sends_the_evidence_by_file_id(): void
    {
        $uploads = 0;

        Http::fake([
            'api.woovi.test/api/v1/files' => function () use (&$uploads) {
                $uploads++;

                return Http::response(['file' => ['id' => "file-{$uploads}", 'url' => 'https://s3/x']], 201);
            },
            'api.woovi.test/api/v1/dispute/*/evidence' => Http::response(['documents' => []]),
        ]);

        $sent = app(SendEvidenceToWooviAction::class)->handle($this->dispute, userId: null);

        // A evidência do seller + a carta de defesa gerada.
        $this->assertSame(2, $sent);
        $this->assertSame(2, $uploads);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/evidence')) {
                return false;
            }

            $documents = $request->data()['documents'];

            return $request->url() === 'https://api.woovi.test/api/v1/dispute/'.$this->dispute->end_to_end_id.'/evidence'
                && $request->header('Authorization')[0] === $this->dispute->wooviAccount->app_id
                && count($documents) === 2
                && collect($documents)->every(fn (array $doc): bool => isset($doc['fileId'], $doc['description'], $doc['correlationID']) && ! isset($doc['url']));
        });

        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/files')
            && $request->isMultipart()
            && collect($request->data())->contains(fn (array $part): bool => $part['name'] === 'purpose' && $part['contents'] === 'DISPUTE_EVIDENCE'));

        $this->dispute->refresh();
        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $this->dispute->status);
        $this->assertNotNull($this->dispute->evidence_sent_at);
        $this->assertSame(0, $this->dispute->evidences()->whereNull('sent_at')->count());
        $this->assertTrue($this->dispute->evidences()->where('kind', EvidenceKind::DEFENSE_LETTER->value)->exists());
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $this->dispute->id, 'event' => DisputeEventType::EVIDENCE_SENT->value]);
    }

    public function test_falls_back_to_signed_url_when_the_account_lacks_the_file_id_feature(): void
    {
        Http::fake([
            'api.woovi.test/api/v1/files' => Http::response([
                'error' => 'Your account does not have the permission to send a evidence by fileId',
                'errorCode' => 'DISPUTE_EVIDENCE_FILE_ID_NOT_ALLOWED',
            ], 403),
            'api.woovi.test/api/v1/dispute/*/evidence' => Http::response(['documents' => []]),
        ]);

        app(SendEvidenceToWooviAction::class)->handle($this->dispute);

        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/evidence')) {
                return false;
            }

            return collect($request->data()['documents'])
                ->every(fn (array $doc): bool => str_contains($doc['url'] ?? '', '/med/evidencias/') && str_contains($doc['url'], 'signature=') && ! isset($doc['fileId']));
        });

        // Depois do primeiro 403, os próximos arquivos já vão direto por URL.
        Http::assertSentCount(2);
        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $this->dispute->fresh()->status);
    }

    public function test_the_signed_evidence_url_serves_the_file_and_an_unsigned_one_does_not(): void
    {
        $evidence = $this->dispute->evidences()->sole();

        $signed = URL::temporarySignedRoute('med.evidence.download', now()->addHour(), ['evidence' => $evidence->id]);

        $this->get($signed)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get(route('med.evidence.download', $evidence))->assertForbidden();
    }

    public function test_woovi_refusal_keeps_everything_pending_for_a_retry(): void
    {
        $uploads = 0;

        Http::fake([
            'api.woovi.test/api/v1/files' => function () use (&$uploads) {
                $uploads++;

                return Http::response(['file' => ['id' => "file-{$uploads}"]], 201);
            },
            'api.woovi.test/api/v1/dispute/*/evidence' => Http::sequence()
                ->push(['error' => 'Internal server error occurred'], 500)
                ->push(['documents' => []]),
        ]);

        try {
            app(SendEvidenceToWooviAction::class)->handle($this->dispute);
            $this->fail('Era para a Woovi recusar.');
        } catch (WooviException $e) {
            $this->assertStringContainsString('HTTP 500', $e->getMessage());
        }

        $this->dispute->refresh();
        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $this->dispute->status);
        $this->assertSame(2, $this->dispute->evidences()->whereNull('sent_at')->count());
        $this->assertNotNull($this->dispute->evidences()->first()->last_error);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $this->dispute->id, 'event' => DisputeEventType::EVIDENCE_SEND_FAILED->value, 'level' => 'error']);

        app(SendEvidenceToWooviAction::class)->handle($this->dispute->fresh());

        $this->assertSame(DisputeStatus::EVIDENCE_SENT, $this->dispute->fresh()->status);
        // Na segunda tentativa os arquivos já subidos não sobem de novo.
        $this->assertSame(2, $uploads);
        $this->assertNull($this->dispute->evidences()->first()->last_error);
    }

    public function test_only_new_evidence_goes_in_a_second_send(): void
    {
        Http::fake([
            'api.woovi.test/api/v1/files' => Http::response(['file' => ['id' => 'f']], 201),
            'api.woovi.test/api/v1/dispute/*/evidence' => Http::response(['documents' => []]),
        ]);

        app(SendEvidenceToWooviAction::class)->handle($this->dispute);

        DisputeEvidence::factory()->for($this->dispute)->create(['kind' => EvidenceKind::DELIVERY_PROOF, 'uploaded_by' => 'analyst']);

        // A carta já enviada não é regerada por cima: nasce uma nova com a lista atualizada.
        $this->assertSame(2, app(SendEvidenceToWooviAction::class)->handle($this->dispute->fresh()));
        $this->assertSame(2, $this->dispute->evidences()->where('kind', EvidenceKind::DEFENSE_LETTER->value)->count());
    }

    public function test_closed_case_cannot_be_sent(): void
    {
        $this->dispute->forceFill(['status' => DisputeStatus::ACCEPTED])->save();

        $this->expectException(RuntimeException::class);

        app(SendEvidenceToWooviAction::class)->handle($this->dispute);
    }
}
