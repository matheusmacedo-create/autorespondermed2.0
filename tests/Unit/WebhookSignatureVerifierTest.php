<?php

namespace Tests\Unit;

use App\Services\Woovi\WebhookSignatureVerifier;
use Tests\TestCase;

class WebhookSignatureVerifierTest extends TestCase
{
    /**
     * Exemplo oficial da doc da Woovi (webhook-signature-validation): payload,
     * assinatura e chave pública reais. Se isto quebrar, o formato da
     * verificação não é mais o da Woovi.
     */
    private const DOC_SIGNATURE = 'lL2nnXgmLFGgxJ8+jCDguqouU4ucrIxYJcU5SPrJFaNcJajTJHYVldqc/z4YFIjAjtPEALe699WosgPY08W7CLpidvtm06Qwa4YMB0l/DcTS93O91NdSH/adjugEKiOb76Zj/0jB8mqOmWCFYbweOBa17bssuEkd5Lw7Q5L314Y=';

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.woovi.webhook.public_keys_url' => '']);
        WebhookSignatureVerifier::forgetCachedKeys();
    }

    public function test_accepts_the_signature_example_from_the_woovi_docs(): void
    {
        $payload = file_get_contents(__DIR__.'/fixtures/woovi-doc-webhook-payload.txt');

        $this->assertTrue((new WebhookSignatureVerifier)->verify($payload, self::DOC_SIGNATURE));
    }

    public function test_rejects_the_docs_signature_over_a_different_body(): void
    {
        $payload = file_get_contents(__DIR__.'/fixtures/woovi-doc-webhook-payload.txt');

        $this->assertFalse((new WebhookSignatureVerifier)->verify($payload.' ', self::DOC_SIGNATURE));
    }

    public function test_rejects_missing_or_malformed_signature(): void
    {
        $verifier = new WebhookSignatureVerifier;

        $this->assertFalse($verifier->verify('{}', null));
        $this->assertFalse($verifier->verify('{}', ''));
        $this->assertFalse($verifier->verify('{}', '%%% não é base64 %%%'));
    }

    public function test_verification_can_be_disabled_for_local_development(): void
    {
        config(['services.woovi.webhook.verify_signature' => false]);

        $this->assertTrue((new WebhookSignatureVerifier)->verify('{}', null));
    }
}
