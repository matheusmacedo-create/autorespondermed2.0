<?php

namespace App\Services\Stores;

/**
 * Captura as páginas de política do site de uma loja. Testes trocam por um falso
 * (o navegador não roda na suíte).
 */
interface StoreCapturer
{
    public function isAvailable(): bool;

    /**
     * @param  array{shipping_url?: ?string, terms_url?: ?string, exchange_url?: ?string}  $hints
     * @return array{final_url: ?string, pages: array<int, array{kind: string, url: string, title: ?string, pdf: string, text: string}>, errors: array<int, string>}
     *
     * @throws StoreCaptureException quando a captura não rodou
     */
    public function capture(string $website, array $hints = []): array;
}
