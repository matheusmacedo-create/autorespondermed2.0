<?php

namespace Tests\Feature\Dossier;

use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\WooviAccounts\Pages\EditWooviAccount;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

class DossierPanelTest extends DossierTestCase
{
    public function test_case_page_shows_the_dossier_and_the_conference(): void
    {
        $dispute = $this->receive();
        $this->actingAs(User::factory()->create());

        $this->get("/admin/disputes/{$dispute->id}")
            ->assertOk()
            ->assertSee('Conferência do dossiê')
            ->assertSee('Pronto para envio')
            ->assertSee('Quem pagou é o cliente da cobrança')
            ->assertSee('Dossiê automático')
            ->assertSee('Transação Pix (Woovi)')
            // Dado do pagador não aparece fora da seção recolhida do pagador.
            ->assertDontSee(self::PAYER_CPF);

        Livewire::test(ListDisputes::class, ['activeTab' => 'todos'])
            ->assertSee('Conferido');
    }

    public function test_rebuild_action_refetches_and_reviewer_permission_is_required(): void
    {
        $dispute = $this->receive();

        $viewer = User::factory()->create();
        User::factory()->create()->syncRoles([Permissions::ROLE_SUPER_ADMIN]);
        $viewer->syncRoles([Permissions::ROLE_VIEWER]);
        $this->actingAs($viewer);
        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])->assertActionHidden('rebuildDossier');

        $analyst = User::factory()->create();
        $analyst->syncRoles([Permissions::ROLE_ANALYST]);
        $this->actingAs($analyst);
        $this->fakeAll();

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->callAction('rebuildDossier')
            ->assertHasNoActionErrors();

        Http::assertSent(fn ($r): bool => str_contains($r->url(), '/api/v1/transaction/'));
        $this->assertSame(2, $dispute->events()->where('event', 'dossier_built')->count());
    }

    public function test_auto_send_is_opted_in_per_account(): void
    {
        $this->actingAs(User::factory()->create());

        Livewire::test(EditWooviAccount::class, ['record' => $this->account->getRouteKey()])
            ->fillForm(['auto_send' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($this->account->fresh()->auto_send);
    }

    public function test_diagnostics_reports_the_dossier_and_auto_send(): void
    {
        config(['med.envio_automatico.habilitado' => true]);

        $this->artisan('med:diagnostico')
            ->expectsOutputToContain('Woovi + unico-api + KYC')
            ->expectsOutputToContain('0 conta(s) com opt-in');

        config(['med.dossie.habilitado' => false]);

        $this->artisan('med:diagnostico')
            ->expectsOutputToContain('nunca vai enviar');
    }
}
