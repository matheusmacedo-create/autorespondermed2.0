<?php

namespace App\Actions\Med;

use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Guarda um arquivo de evidência no disco do MED e cria a linha. Usado pelo
 * formulário do seller e pelo anexo do analista.
 */
class StoreEvidenceFile
{
    public function handle(Dispute $dispute, UploadedFile $file, EvidenceKind $kind, string $description, string $uploadedBy): DisputeEvidence
    {
        $disk = (string) config('med.evidencias.disk');
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'bin');
        $path = $file->storeAs("med/{$dispute->id}", Str::uuid().'.'.$extension, $disk);

        return $dispute->evidences()->create([
            'kind' => $kind,
            'description' => Str::limit(trim($description), 450, ''),
            'disk' => $disk,
            'path' => $path,
            'original_name' => Str::limit($file->getClientOriginalName(), 250, ''),
            'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'uploaded_by' => $uploadedBy,
        ]);
    }
}
