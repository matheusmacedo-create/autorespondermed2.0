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

    public function test_charge_keeps_the_debtor_and_the_settlement_time(): void
    {
        $e2e = 'E18236120202611201845s0133872GZA';
        $qrCode = [
            'id' => 'qr-1', 'qrcode_type' => 'COBRANCA_IMEDIATA', 'status' => 'CONCLUIDA', 'txid' => 'tx-1', 'original_value' => 197.74,
            'payer_question' => 'Processamento de pagamento', 'payer' => ['name' => 'Maria', 'document' => '123.456.789-09'],
            'additional_info' => [['key' => 'Pedido', 'value' => '42'], ['sem' => 'chave']],
            'created_at' => '2026-11-20T18:45:17.000Z', 'settled_at' => '2026-11-20T15:45:58-03:00',
            'pix_received' => ['end2end_id' => $e2e],
        ];

        $charge = InfractionMapper::toChargeSource($qrCode, $e2e);

        $this->assertSame('qr-1', $charge['correlationID']);
        $this->assertSame(19774, $charge['value']);
        $this->assertSame('12345678909', $charge['customer']['taxID']['taxID']);
        $this->assertSame('2026-11-20T15:45:58-03:00', $charge['paidAt']);
        $this->assertSame([['key' => 'Pedido', 'value' => '42']], $charge['additionalInfo']);

        // Pago por outro Pix, sem pagamento registrado ou sem id: não é a cobrança deste caso.
        $this->assertSame([], InfractionMapper::toChargeSource($qrCode, 'E00000000202611201845outroPix00000'));
        $this->assertSame([], InfractionMapper::toChargeSource(['pix_received' => null] + $qrCode, $e2e));
        $this->assertSame([], InfractionMapper::toChargeSource(array_diff_key($qrCode, ['pix_received' => true]), $e2e));
        $this->assertSame([], InfractionMapper::toChargeSource(['txid' => 'tx-1', 'pix_received' => ['end2end_id' => $e2e]], $e2e));
        // Documento mascarado não vira devedor conferível.
        $masked = InfractionMapper::toChargeSource(['id' => 'qr-2', 'payer' => ['name' => 'Maria', 'document' => '***.456.789-**'], 'pix_received' => ['end2end_id' => $e2e]], $e2e);
        $this->assertSame('qr-2', $masked['correlationID']);
        $this->assertArrayNotHasKey('taxID', $masked['customer']);
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
