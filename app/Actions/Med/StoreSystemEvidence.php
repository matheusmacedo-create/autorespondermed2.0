<?php

namespace App\Actions\Med;

use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Guarda um documento que o sistema gera (carta de defesa, registro da venda,
 * comprovante da Woovi), um por `source`.
 *
 * O `fingerprint` diz se o conteúdo mudou - para PDF do dompdf é o hash dos DADOS,
 * porque os bytes mudam a cada geração (data de criação embutida):
 *  - igual ao último: nada muda (nem conta como evidência nova para enviar);
 *  - mudou e o último ainda não foi enviado: troca o arquivo E o correlationID - o
 *    upload na Woovi é idempotente por correlationID, e com o id antigo ela
 *    devolveria o arquivo velho;
 *  - mudou e o último já foi enviado: nasce outra linha (o que foi enviado não muda).
 */
class StoreSystemEvidence
{
    public function handle(
        Dispute $dispute,
        string $source,
        EvidenceKind $kind,
        string $contents,
        string $fileName,
        string $mimeType,
        string $description,
        ?string $fingerprint = null,
    ): DisputeEvidence {
        $fingerprint ??= hash('sha256', $contents);

        // withTrashed: documento descartado pelo analista conta como "já existe" - a
        // remontagem não o traz de volta enquanto o conteúdo for o mesmo.
        $latest = $dispute->evidences()->withTrashed()->where('source', $source)->latest('id')->first();

        if ($latest && $latest->content_hash === $fingerprint) {
            return $latest;
        }

        $disk = (string) config('med.evidencias.disk');
        $attributes = [
            'kind' => $kind,
            'description' => Str::limit($description, 450, ''),
            'disk' => $disk,
            'original_name' => $fileName,
            'mime_type' => $mimeType,
            'size' => strlen($contents),
            'content_hash' => $fingerprint,
            'uploaded_by' => 'system',
            'source' => $source,
        ];

        if ($latest && $latest->sent_at === null && ! $latest->trashed()) {
            Storage::disk($latest->disk)->put($latest->path, $contents);

            $latest->fill($attributes + [
                'correlation_id' => (string) Str::uuid(),
                'woovi_file_id' => null,
                'woovi_url' => null,
                'last_error' => null,
            ])->save();

            return $latest;
        }

        // Mesmo conteúdo que o caso já tem (de outra fonte, ou o seller já mandou):
        // não cria outra evidência igual para ir duas vezes à Woovi.
        $sameContent = $dispute->evidences()->withTrashed()->where('content_hash', $fingerprint)->first();

        if ($sameContent) {
            return $sameContent;
        }

        $extension = pathinfo($fileName, PATHINFO_EXTENSION) ?: 'pdf';
        $path = "med/{$dispute->id}/{$source}-".Str::uuid().'.'.$extension;
        Storage::disk($disk)->put($path, $contents);

        return $dispute->evidences()->create($attributes + ['path' => $path]);
    }
}
