<?php

namespace Tests\Feature\Filament;

use App\Enums\DisputeStatus;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\Dispute;
use App\Models\DisputeEvidence;
use App\Models\User;
use App\Models\WooviAccount;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        // O primeiro usuário vira Super Admin; os papéis testados vêm depois dele.
        User::factory()->create();
    }

    private function userWithRole(?string $role): User
    {
        $user = User::factory()->create();

        if ($role) {
            $user->syncRoles([$role]);
        }

        return $user;
    }

    public function test_first_user_becomes_super_admin_and_the_next_ones_get_no_role(): void
    {
        $this->assertTrue(User::query()->oldest('id')->first()->hasRole(Permissions::ROLE_SUPER_ADMIN));
        $this->assertSame(0, User::factory()->create()->roles()->count());
    }

    public function test_user_without_role_cannot_enter_the_panel(): void
    {
        $this->actingAs($this->userWithRole(null))->get('/admin')->assertForbidden();
    }

    public function test_viewer_reads_cases_but_cannot_act_on_them(): void
    {
        $dispute = Dispute::factory()->submitted()->create();
        $this->actingAs($this->userWithRole(Permissions::ROLE_VIEWER));

        $this->get('/admin/disputes')->assertOk();
        $this->get("/admin/disputes/{$dispute->id}")->assertOk();

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->assertActionHidden('sendToWoovi')
            ->assertActionHidden('requestComplement')
            ->assertActionHidden('attachEvidence')
            ->assertActionHidden('editAnalystNotes')
            ->assertActionHidden('syncWithWoovi');

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->fresh()->status);
    }

    public function test_forced_call_to_a_hidden_action_does_nothing(): void
    {
        Mail::fake();
        $dispute = Dispute::factory()->submitted()->create();
        $this->actingAs($this->userWithRole(Permissions::ROLE_VIEWER));

        // Direto nos métodos do Livewire, como faria um cliente forjando a requisição
        // (o helper callAction se recusa a chamar ação escondida).
        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->call('mountAction', 'requestComplement')
            ->set('mountedActions.0.data.message', 'Tentativa sem permissão de pedir complemento.')
            ->call('callMountedAction');

        $this->assertSame(DisputeStatus::SELLER_SUBMITTED, $dispute->fresh()->status);
        $this->assertNull($dispute->fresh()->complement_request);
        Mail::assertNothingSent();
    }

    public function test_viewer_sees_accounts_but_cannot_create_or_edit_them(): void
    {
        $account = WooviAccount::factory()->create();
        $this->actingAs($this->userWithRole(Permissions::ROLE_VIEWER));

        $this->get('/admin/woovi-accounts')->assertOk();
        $this->get('/admin/woovi-accounts/create')->assertForbidden();
        $this->get("/admin/woovi-accounts/{$account->id}/edit")->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_analyst_can_send_but_cannot_touch_accounts_or_users(): void
    {
        $dispute = Dispute::factory()->submitted()->create();
        $account = WooviAccount::factory()->create();
        $this->actingAs($this->userWithRole(Permissions::ROLE_ANALYST));

        Livewire::test(ViewDispute::class, ['record' => $dispute->getRouteKey()])
            ->assertActionVisible('sendToWoovi')
            ->assertActionVisible('requestComplement');

        $this->get("/admin/woovi-accounts/{$account->id}/edit")->assertForbidden();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_admin_manages_accounts_but_not_users(): void
    {
        $account = WooviAccount::factory()->create();
        $this->actingAs($this->userWithRole(Permissions::ROLE_ADMIN));

        $this->get("/admin/woovi-accounts/{$account->id}/edit")->assertOk();
        $this->get('/admin/users')->assertForbidden();
    }

    public function test_evidence_download_requires_the_view_permission(): void
    {
        $evidence = DisputeEvidence::factory()->create();

        $this->actingAs($this->userWithRole(null))->get(route('admin.evidence.download', $evidence))->assertForbidden();
        $this->actingAs($this->userWithRole(Permissions::ROLE_VIEWER))->get(route('admin.evidence.download', $evidence))->assertOk();
    }

    public function test_super_admin_assigns_a_role_from_the_users_screen(): void
    {
        $this->actingAs(User::query()->oldest('id')->first());
        $newcomer = $this->userWithRole(null);
        $analyst = Role::findByName(Permissions::ROLE_ANALYST);

        $this->get('/admin/users')->assertOk()->assertSee($newcomer->email);

        Livewire::test(EditUser::class, ['record' => $newcomer->getRouteKey()])
            ->fillForm(['roles' => [$analyst->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($newcomer->fresh()->hasRole(Permissions::ROLE_ANALYST));
    }

    public function test_existing_users_become_super_admin_when_roles_arrive(): void
    {
        // A migração de papéis promove quem já usava o painel (antes todos podiam tudo).
        $migration = require database_path('migrations/2026_10_13_000002_seed_roles_and_permissions.php');
        $user = $this->userWithRole(null);

        $migration->up();

        $this->assertTrue($user->fresh()->hasRole(Permissions::ROLE_SUPER_ADMIN));
    }
}
