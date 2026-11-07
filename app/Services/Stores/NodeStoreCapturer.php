<?php

namespace App\Services\Stores;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

/**
 * Roda scripts/capture-store.cjs (Chromium sem tela pelo playwright-core instalado em
 * `med.lojas.browser_dir`) e devolve os PDFs e o texto de cada política.
 */
class NodeStoreCapturer implements StoreCapturer
{
    public function isAvailable(): bool
    {
        $dir = (string) config('med.lojas.browser_dir');

        return (bool) config('med.lojas.captura_habilitada')
            && is_dir($dir.'/node_modules/playwright-core')
            && is_dir($dir.'/ms-playwright');
    }

    public function capture(string $website, array $hints = []): array
    {
        if (! $this->isAvailable()) {
            throw new StoreCaptureException('Captura desligada ou navegador não instalado em '.config('med.lojas.browser_dir').'.');
        }

        $dir = storage_path('app/tmp/store-capture-'.Str::uuid());
        File::ensureDirectoryExists($dir);

        $browserDir = (string) config('med.lojas.browser_dir');
        $process = new Process(
            [(string) config('med.lojas.node'), base_path('scripts/capture-store.cjs'), json_encode(array_filter([
                'website' => $website,
                'shipping_url' => $hints['shipping_url'] ?? null,
                'terms_url' => $hints['terms_url'] ?? null,
                'exchange_url' => $hints['exchange_url'] ?? null,
                'out_dir' => $dir,
            ]))],
            base_path(),
            ['MED_BROWSER_DIR' => $browserDir, 'PLAYWRIGHT_BROWSERS_PATH' => $browserDir.'/ms-playwright'],
            null,
            (float) config('med.lojas.timeout_segundos', 180),
        );

        try {
            try {
                $process->run();
            } catch (ProcessTimedOutException) {
                throw new StoreCaptureException('A captura passou do tempo limite ('.config('med.lojas.timeout_segundos').'s).');
            }

            $result = json_decode($process->getOutput(), true);

            if (! is_array($result)) {
                throw new StoreCaptureException('A captura não devolveu resultado: '.Str::limit(trim($process->getErrorOutput()), 300));
            }

            $pages = [];

            foreach ((array) ($result['pages'] ?? []) as $page) {
                $file = (string) ($page['file'] ?? '');

                // Só arquivo que o script gerou na pasta desta captura.
                if (! str_starts_with(realpath($file) ?: '', realpath($dir)) || ! is_file($file)) {
                    continue;
                }

                $pages[] = [
                    'kind' => (string) $page['kind'],
                    'url' => (string) $page['url'],
                    'title' => $page['title'] ?? null,
                    'pdf' => (string) file_get_contents($file),
                    'text' => (string) ($page['text'] ?? ''),
                ];
            }

            return ['final_url' => $result['final_url'] ?? null, 'pages' => $pages, 'errors' => array_values((array) ($result['errors'] ?? []))];
        } finally {
            File::deleteDirectory($dir);
        }
    }
}
