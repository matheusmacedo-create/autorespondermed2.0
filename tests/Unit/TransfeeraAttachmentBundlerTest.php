<?php

namespace Tests\Unit;

use App\Services\Transfeera\AttachmentBundler;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

class TransfeeraAttachmentBundlerTest extends TestCase
{
    /**
     * @return array{contents: string, name: string, mime: string}
     */
    private function pdf(string $name, string $contents = '%PDF-1.4 teste'): array
    {
        return ['contents' => $contents, 'name' => $name, 'mime' => 'application/pdf'];
    }

    public function test_nothing_and_a_single_file_pass_through_untouched(): void
    {
        $bundler = new AttachmentBundler;

        $this->assertSame([], $bundler->bundle([]));
        $this->assertSame([$this->pdf('carta.pdf')], $bundler->bundle([$this->pdf('carta.pdf')]));
    }

    public function test_several_files_become_one_zip_in_order_and_with_unique_names(): void
    {
        $bundle = (new AttachmentBundler)->bundle([
            $this->pdf('carta.pdf', '%PDF carta'),
            $this->pdf('nota.pdf', '%PDF nota 1'),
            // Mesmo nome de outro arquivo e caminho no nome: nada sobrescreve nem sai da raiz.
            $this->pdf('../nota.pdf', '%PDF nota 2'),
        ]);

        $this->assertCount(1, $bundle);
        $this->assertSame(AttachmentBundler::NAME, $bundle[0]['name']);
        $this->assertSame('application/zip', $bundle[0]['mime']);

        $path = tempnam(sys_get_temp_dir(), 'zip-test-');
        file_put_contents($path, $bundle[0]['contents']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));

        try {
            $this->assertSame(3, $zip->numFiles);
            $this->assertSame(['01-carta.pdf', '02-nota.pdf', '03-nota.pdf'], [$zip->getNameIndex(0), $zip->getNameIndex(1), $zip->getNameIndex(2)]);
            $this->assertSame('%PDF carta', $zip->getFromIndex(0));
            $this->assertSame('%PDF nota 2', $zip->getFromIndex(2));
        } finally {
            $zip->close();
            unlink($path);
        }
    }

    public function test_without_the_zip_extension_several_files_are_refused(): void
    {
        $bundler = new class extends AttachmentBundler
        {
            protected function zipAvailable(): bool
            {
                return false;
            }
        };

        $this->assertSame([$this->pdf('carta.pdf')], $bundler->bundle([$this->pdf('carta.pdf')]));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('2 anexos');

        $bundler->bundle([$this->pdf('carta.pdf'), $this->pdf('nota.pdf')]);
    }
}
