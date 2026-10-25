<?php

namespace App\Providers;

use App\Models\User;
use App\Services\Defense\ClaudeDefenseWriter;
use App\Services\Defense\DefenseWriter;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DefenseWriter::class, ClaudeDefenseWriter::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Super Admin passa em qualquer checagem, inclusive em permissão nova que
        // ainda não foi dada ao papel.
        Gate::before(fn (User $user): ?bool => $user->hasRole(Permissions::ROLE_SUPER_ADMIN) ? true : null);
    }
}
