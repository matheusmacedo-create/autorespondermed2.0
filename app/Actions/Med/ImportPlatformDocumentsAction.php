<?php

namespace App\Actions\Med;

use App\Enums\DisputeEventType;
use App\Enums\EvidenceKind;
use App\Models\Dispute;
use App\Services\DisputeEventRecorder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Baixa os documentos que a plataforma já tem da venda (nota fiscal, comprovante
 * de entrega, contrato) e os guarda como evidência do dossiê. As URLs vêm do
 * unico-api; mesmo assim só https, só os formatos e o tamanho que a Woovi aceita.
 */
class ImportPlatformDocumentsAction
{
    private const MAX_DOCUMENTS = 5;

    private const KINDS = [
        'invoice' => EvidenceKind::INVOICE,
        'nota_fiscal' => EvidenceKind::INVOICE,
        'delivery_proof' => EvidenceKind::DELIVERY_PROOF,
        'service_proof' => EvidenceKind::SERVICE_PROOF,
        'contract' => EvidenceKind::CONTRACT,
    ];

    private const MIMES = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'image/webp' => 'webp',
    ];

    public function __construct(
        private readonly StoreSystemEvidence $storeSystemEvidence,
        private readonly DisputeEventRecorder $events,
    ) {}

    /**
     * @param  array<int, mixed>  $documents  [{kind, url, description}]
     */
    public function handle(Dispute $dispute, array $documents): int
    {
        $imported = 0;

        foreach (array_slice(array_values(array_filter($documents, 'is_array')), 0, self::MAX_DOCUMENTS) as $document) {
            $url = (string) ($document['url'] ?? '');

            if (! str_starts_with(strtolower($url), 'https://')) {
                continue;
            }

            try {
                $response = Http::timeout(15)->withOptions(['allow_redirects' => false])->get($url);
                $body = $response->body();
                // Tipo pelos bytes, não pelo header: a Woovi confere os bytes iniciais no
                // upload, e storage que serve octet-stream não pode recusar um PDF bom.
                $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);

                if (! $response->successful() || ! isset(self::MIMES[$mime]) || strlen($body) === 0 || strlen($body) > (int) config('med.evidencias.max_kb') * 1024) {
                    throw new \RuntimeException("arquivo recusado (HTTP {$response->status()}, {$mime}, ".strlen($body).' bytes)');
                }
            } catch (Throwable $e) {
                $this->events->record(DisputeEventType::DOSSIER_SOURCE_FAILED, $dispute, 'Documento da plataforma não importado: '.$e->getMessage(), ['url_host' => parse_url($url, PHP_URL_HOST)], level: 'warning');

                continue;
            }

            $kind = self::KINDS[strtolower((string) ($document['kind'] ?? ''))] ?? EvidenceKind::OTHER;

            $this->storeSystemEvidence->handle(
                $dispute,
                'unicopag_doc_'.substr(hash('sha256', $this->stableKey($document, $url)), 0, 16),
                $kind,
                $body,
                Str::limit(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_FILENAME) ?: 'documento', 80, '').'.'.self::MIMES[$mime],
                $mime,
                (string) ($document['description'] ?? 'Documento da venda registrado na plataforma ÚnicoPag'),
            );

            $imported++;
        }

        return $imported;
    }

    /**
     * Identidade do documento entre montagens: o id que o unico-api der, senão o
     * tipo + a URL sem a query - URL pré-assinada (S3) muda a assinatura a cada
     * resposta, e com ela a mesma nota fiscal viraria outra evidência.
     *
     * @param  array<string, mixed>  $document
     */
    private function stableKey(array $document, string $url): string
    {
        if (filled($document['id'] ?? null)) {
            return 'id:'.$document['id'];
        }

        $parts = parse_url($url);

        return strtolower((string) ($document['kind'] ?? '')).'|'.strtolower((string) ($parts['host'] ?? '')).($parts['path'] ?? '');
    }
}
