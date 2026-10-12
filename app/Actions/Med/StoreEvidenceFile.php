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
 *
 * Arquivo com o mesmo conteúdo (sha256) de uma evidência que o caso já tem - do
 * dossiê, de um envio anterior do seller - não entra de novo: devolve null. Só
 * pega cópia idêntica; o mesmo documento reexportado passa (quem confere é o analista).
 */
class StoreEvidenceFile
{
    public function handle(Dispute $dispute, UploadedFile $file, EvidenceKind $kind, string $description, string $uploadedBy): ?DisputeEvidence
    {
        $hash = hash_file('sha256', $file->getRealPath()) ?: null;

        if ($hash !== null && $dispute->evidences()->withTrashed()->where('content_hash', $hash)->exists()) {
            return null;
        }

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
            'content_hash' => $hash,
            'uploaded_by' => $uploadedBy,
        ]);
    }
}
