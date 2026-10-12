<?php

namespace Tests;

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    /**
     * Aponta o storage para o diretório exclusivo deste processo (ver
     * tests/bootstrap.php): `Storage::fake()` monta o root a partir de
     * `storage_path()` e limpa o diretório, e dois runs simultâneos apagariam os
     * arquivos um do outro.
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        if ($storage = getenv('TEST_STORAGE_PATH')) {
            $app->useStoragePath($storage);
        }

        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Nenhum teste fala com a Woovi ou o Discord de verdade: chamada sem
        // Http::fake correspondente estoura em vez de sair para a rede.
        Http::preventStrayRequests();

        // Os testes não dependem do build do front (npm run build).
        $this->withoutVite();
    }
}
