<?php

namespace App\Http\Controllers;

use App\Models\MerchantStore;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StoreCaptureDownloadController extends Controller
{
    public function __invoke(MerchantStore $store, string $kind): StreamedResponse
    {
        $capture = $store->capture($kind);

        abort_unless($capture && Storage::disk($capture['disk'])->exists($capture['path']), 404);

        return Storage::disk($capture['disk'])->response($capture['path'], "politica-{$kind}.pdf", ['Content-Type' => 'application/pdf']);
    }
}
