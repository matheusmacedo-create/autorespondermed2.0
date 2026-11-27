<?php

namespace Tests\Unit;

use App\Services\Transfeera\InfractionMapper;
use App\Services\Transfeera\WebhookSignatureVerifier;
use Tests\TestCase;

class TransfeeraSignatureVerifierTest extends TestCase
{
    /** Vetor de teste da própria doc da Transfeera. */
    private const BODY = '{"testing":true,"someString":"string-value"}';

    private const HEADER = 't=1580306991086,v1=348a92ec7864e30fc9cf3ea91b2e6e1392a14c8379103cb1d8e48e39334a4fd8';

    public function test_accepts_the_example_from_the_documentation(): void
    {
        $this->assertTrue((new WebhookSignatureVerifier)->verify(self::BODY, self::HEADER, 'my-secret'));
    }

    public function test_rejects_changed_body_wrong_secret_and_missing_parts(): void
    {
        $verifier = new WebhookSignatureVerifier;

        $this->assertFalse($verifier->verify(self::BODY.' ', self::HEADER, 'my-secret'));
        $this->assertFalse($verifier->verify(self::BODY, self::HEADER, 'outro-segredo'));
        $this->assertFalse($verifier->verify(self::BODY, self::HEADER, null));
        $this->assertFalse($verifier->verify(self::BODY, null, 'my-secret'));
        $this->assertFalse($verifier->verify(self::BODY, 'v1=348a92ec7864e30fc9cf3ea91b2e6e1392a14c8379103cb1d8e48e39334a4fd8', 'my-secret'));
        // Só o esquema v1 vale.
        $this->assertFalse($verifier->verify(self::BODY, 't=1580306991086,v0=348a92ec7864e30fc9cf3ea91b2e6e1392a14c8379103cb1d8e48e39334a4fd8', 'my-secret'));
    }

    public function test_pix_time_comes_from_the_end_to_end_id_in_utc(): void
    {
        $this->assertSame('2026-11-20T18:45:00+00:00', InfractionMapper::paidAt('E18236120202611201845s0133872GZA')?->toIso8601String());
        $this->assertNull(InfractionMapper::paidAt('E3524a995bbd54034b6d07c1c36014557'));
    }

    public function test_masked_payer_document_never_becomes_a_tax_id(): void
    {
        $masked = InfractionMapper::toTransactionSource(['value' => 50.54, 'payer' => ['name' => 'João', 'document' => '***.274.581-**']], 'E18236120202611201845s0133872GZA');
        $this->assertSame(5054, $masked['value']);
        $this->assertArrayNotHasKey('taxID', $masked['payer']);

        $full = InfractionMapper::toTransactionSource(['value' => 50.54, 'payer' => ['document' => '***.274.581-**']], 'E18236120202611201845s0133872GZA', '59136846058');
        $this->assertSame('59136846058', $full['payer']['taxID']['taxID']);
    }
}
