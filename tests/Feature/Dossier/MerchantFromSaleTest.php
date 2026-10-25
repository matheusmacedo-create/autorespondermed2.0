<?php

namespace Tests\Feature\Dossier;

use App\Actions\Med\BuildDossierAction;
use App\Actions\Med\GenerateDefenseLetterAction;
use App\Enums\DisputeEventType;
use App\Enums\DossierSource;
use App\Enums\SourceStatus;
use App\Mail\DisputeOpenedMail;
use App\Models\Dispute;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\View;

/**
 * Conta mestre: todos os MEDs caem na mesma conta Woovi, sem lojista nem contato.
 * A venda no unico-api (pelo correlationID da cobrança) é que diz quem recebeu.
 */
class MerchantFromSaleTest extends DossierTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->account->forceFill(['name' => 'UNICO PAG LTDA (conta mestre)', 'unicopag_account_id' => null, 'contact_email' => null, 'contact_phone' => null, 'contact_name' => null])->save();
        config(['services.unicopag.sale_endpoint' => '/api/med/sales/lookup?correlation_id={correlation_id}&end_to_end_id={end_to_end_id}&txid={txid}']);

        $data = json_decode((string) $this->fakes['sale']->wait()->getBody(), true)['data'];
        $data['account_id'] = '4321';
        $data['seller'] = [
            'name' => 'Loja do Zé LTDA', 'trade_name' => 'Loja do Zé', 'document' => '12.345.678/0001-90',
            'contact_name' => 'José', 'email' => 'jose@lojadoze.com.br', 'phone' => '+5521999990000', 'kyc_status' => 'approved',
        ];
        $this->fakes['sale'] = Http::response(['data' => $data]);
    }

    public function test_sale_is_looked_up_without_account_and_the_case_learns_its_merchant(): void
    {
        $dispute = $this->receive();

        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/med/sales/lookup?correlation_id=pedido-42'));
        $this->assertSame('4321', $dispute->merchant_account_id);
        $this->assertSame('Loja do Zé', $dispute->merchant['trade_name']);
        $this->assertSame('12345678000190', $dispute->merchant['tax_id']);
        $this->assertSame('jose@lojadoze.com.br', $dispute->seller()['email']);
        $this->assertDatabaseHas('dispute_events', ['dispute_id' => $dispute->id, 'event' => DisputeEventType::MERCHANT_IDENTIFIED->value]);

        // Cadastro e KYC pelo lojista da venda, não pela conta mestre.
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), '/api/kyc-panel/accounts/4321/register'));
        Http::assertSent(fn (Request $r): bool => str_contains($r->url(), 'onboarding/status') && str_contains($r->url(), 'account_id=4321'));
        $this->assertSame(SourceStatus::OK, $dispute->sources->firstWhere('source', DossierSource::KYC_STATUS)->status);
    }

    public function test_merchant_found_later_is_notified_once_with_its_own_contact(): void
    {
        $dispute = $this->receive();

        // Na entrada a conta mestre não tinha e-mail; com a venda, o aviso sai para o lojista.
        Mail::assertSent(DisputeOpenedMail::class, fn (DisputeOpenedMail $mail): bool => $mail->hasTo('jose@lojadoze.com.br'));
        $this->assertNotNull($dispute->fresh()->seller_notified_at);

        app(BuildDossierAction::class)->handle($dispute->fresh(), refresh: true);
        Mail::assertSent(DisputeOpenedMail::class, 1);
    }

    public function test_kyc_status_comes_from_the_sale_when_the_kyc_service_is_not_configured(): void
    {
        config(['services.kyc.base_url' => null]);

        $dispute = $this->receive();

        $kyc = $dispute->sources->firstWhere('source', DossierSource::KYC_STATUS);
        $this->assertSame(SourceStatus::OK, $kyc->status);
        $this->assertSame('approved', $kyc->payload['status']);
        $this->assertSame('ok', collect($dispute->dossier_match['checks'])->firstWhere('key', 'kyc_approved')['status']);
    }

    public function test_letter_and_seller_link_name_the_merchant_not_the_master_account(): void
    {
        $dispute = $this->receive();
        $dispute->forceFill(['seller_statement' => 'Entreguei o produto.'])->save();
        $data = null;
        View::composer('pdf.defense-letter', function ($view) use (&$data): void {
            $data = $view->getData();
        });

        app(GenerateDefenseLetterAction::class)->handle(Dispute::query()->find($dispute->id));

        $this->assertSame('Loja do Zé LTDA', $data['accountName']);
        $this->assertSame('Loja do Zé', $data['tradeName']);
        $this->assertSame('12.345.678/0001-90', $data['accountTaxId']);
    }

    public function test_without_a_sale_the_case_keeps_the_account_and_nobody_is_guessed(): void
    {
        $this->fakes['sale'] = Http::response(['message' => 'not found'], 404);

        $dispute = $this->receive();

        $this->assertNull($dispute->merchant);
        $this->assertSame('UNICO PAG LTDA (conta mestre)', $dispute->seller()['name']);
        Mail::assertNotSent(DisputeOpenedMail::class);
    }
}
