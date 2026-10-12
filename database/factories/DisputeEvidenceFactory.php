<?php

namespace Database\Factories;

use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @extends Factory<DisputeEvidence>
 */
class DisputeEvidenceFactory extends Factory
{
    public function definition(): array
    {
        $path = 'med/test/'.Str::uuid().'.pdf';

        return [
            'dispute_id' => Dispute::factory(),
            'kind' => EvidenceKind::INVOICE,
            'description' => 'Nota fiscal da venda',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'nota.pdf',
            'mime_type' => 'application/pdf',
            'size' => 9,
            'uploaded_by' => 'seller',
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (DisputeEvidence $evidence): void {
            Storage::disk($evidence->disk)->put($evidence->path, '%PDF-1.4 test');
        });
    }
}
