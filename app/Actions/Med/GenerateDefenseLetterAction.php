<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Services\DisputeEventRecorder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Carta de defesa em PDF: o relato do seller, o parecer do analista e a lista
 * das evidências, num documento só. A API de evidência da Woovi não tem campo
 * de texto livre (só `description` por documento, 500 caracteres), então o
 * argumento da defesa vai como documento.
 *
 * Enquanto não foi enviada, a carta é regenerada a cada envio (o relato pode ter
 * recebido complemento); depois de enviada, fica como está e uma nova é criada.
 */
class GenerateDefenseLetterAction
{
    public function __construct(private readonly DisputeEventRecorder $events) {}

    public function handle(Dispute $dispute): ?DisputeEvidence
    {
        if (blank($dispute->seller_statement) && blank($dispute->analyst_notes)) {
            return null;
        }

        $dispute->loadMissing(['wooviAccount', 'evidences']);

        $evidences = $dispute->evidences
            ->reject(fn (DisputeEvidence $evidence): bool => $evidence->kind === EvidenceKind::DEFENSE_LETTER)
            ->values();

        $pdf = Pdf::loadView('pdf.defense-letter', [
            'dispute' => $dispute,
            'account' => $dispute->wooviAccount,
            'evidences' => $evidences,
            'generatedAt' => now()->timezone('America/Sao_Paulo'),
        ])->setPaper('a4')->output();

        $disk = (string) config('med.evidencias.disk');

        $letter = $dispute->evidences()
            ->where('kind', EvidenceKind::DEFENSE_LETTER->value)
            ->whereNull('sent_at')
            ->first();

        $path = $letter?->path ?? "med/{$dispute->id}/carta-de-defesa-".Str::uuid().'.pdf';
        Storage::disk($disk)->put($path, $pdf);

        $attributes = [
            'kind' => EvidenceKind::DEFENSE_LETTER,
            'description' => 'Relato do recebedor e lista das evidências anexadas',
            'disk' => $disk,
            'path' => $path,
            'original_name' => "carta-de-defesa-{$dispute->end_to_end_id}.pdf",
            'mime_type' => 'application/pdf',
            'size' => strlen($pdf),
            'uploaded_by' => 'system',
        ];

        if ($letter) {
            $letter->fill($attributes)->save();
        } else {
            $letter = $dispute->evidences()->create($attributes);
        }

        $this->events->record(DisputeEventType::DEFENSE_LETTER_GENERATED, $dispute, 'Carta de defesa gerada.', ['evidence_id' => $letter->id]);

        return $letter;
    }
}
