<?php

namespace App\Services\Transfeera;

use RuntimeException;
use ZipArchive;

/**
 * A Transfeera aceita um arquivo só por análise ("Only one file can be uploaded", visto
 * em produção em 27/11/2026), e a análise não pode ser completada depois. Com mais de um
 * anexo, vão todos num .zip, na ordem recebida (quem envia põe a carta de defesa primeiro).
 *
 * Se o zip não puder ser montado (servidor sem a extensão zip do PHP, disco cheio),
 * nada é enviado: mandar só uma parte deixaria o resto de fora para sempre. O erro sobe
 * para o envio, que o registra na linha do tempo e no alerta.
 */
class AttachmentBundler
{
    public const NAME = 'defesa-e-evidencias.zip';

    /**
     * @param  array<int, array{contents: string, name: string, mime: string}>  $attachments
     * @return array<int, array{contents: string, name: string, mime: string}> nenhum ou um arquivo
     */
    public function bundle(array $attachments): array
    {
        $attachments = array_values($attachments);

        if (count($attachments) <= 1) {
            return $attachments;
        }

        if (! $this->zipAvailable()) {
            throw new RuntimeException(sprintf(
                'A Transfeera aceita um arquivo só por resposta e o servidor não tem a extensão zip do PHP para juntar os %d anexos. Instale a extensão (php-zip) e envie de novo.',
                count($attachments),
            ));
        }

        $path = tempnam(sys_get_temp_dir(), 'med-zip-');

        if ($path === false) {
            throw new RuntimeException('Não foi possível criar o arquivo temporário para juntar os anexos num zip.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Não foi possível criar o zip dos anexos.');
            }

            foreach ($attachments as $index => $file) {
                // Prefixo numérico: mantém a ordem e evita dois arquivos com o mesmo nome.
                if (! $zip->addFromString(sprintf('%02d-%s', $index + 1, basename($file['name'])), $file['contents'])) {
                    throw new RuntimeException("Não foi possível incluir {$file['name']} no zip dos anexos.");
                }
            }

            if (! $zip->close()) {
                throw new RuntimeException('Não foi possível gravar o zip dos anexos.');
            }

            // Confere o que ficou gravado antes de mandar: a resposta não tem segunda chance.
            $entries = $this->countEntries($path);

            if ($entries === null) {
                throw new RuntimeException('Não foi possível conferir o zip dos anexos depois de gravado.');
            }

            if ($entries !== count($attachments)) {
                throw new RuntimeException('O zip dos anexos saiu incompleto.');
            }

            $contents = file_get_contents($path);

            if ($contents === false || $contents === '') {
                throw new RuntimeException('O zip dos anexos saiu vazio.');
            }
        } finally {
            @unlink($path);
        }

        return [['contents' => $contents, 'name' => self::NAME, 'mime' => 'application/zip']];
    }

    protected function zipAvailable(): bool
    {
        return class_exists(ZipArchive::class);
    }

    /** Quantos arquivos o zip gravado tem; null quando ele não abre para a conferência. */
    private function countEntries(string $path): ?int
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return null;
        }

        $count = $zip->numFiles;
        $zip->close();

        return $count;
    }
}
