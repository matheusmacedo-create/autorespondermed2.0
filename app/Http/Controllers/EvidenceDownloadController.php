<?php

namespace App\Http\Controllers;

use App\Models\DisputeEvidence;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Entrega o arquivo de uma evidência. Duas portas, mesma resposta:
 * - link temporário assinado (middleware `signed`), que a Woovi baixa no modo `url`;
 * - painel admin (auth), para o analista abrir o arquivo.
 */
class EvidenceDownloadController extends Controller
{
    public function __invoke(DisputeEvidence $evidence): StreamedResponse
    {
        abort_unless(Storage::disk($evidence->disk)->exists($evidence->path), 404);

        return Storage::disk($evidence->disk)->response(
            $evidence->path,
            $evidence->original_name,
            ['Content-Type' => $evidence->mime_type],
        );
    }
}
