<?php

namespace App\Providers\Filament;

use App\Filament\Widgets\MedOverviewWidget;
use App\Http\Middleware\SetPanelLocale;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            // Aviso do rascunho da defesa com IA (job em fila).
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->path('admin')
            ->login()
            ->brandName('ÚnicoPag MED')
            // Mesma paleta navy do painel do KYC (ver AdminPanelProvider de lá).
            ->colors([
                'primary' => [
                    50 => 'oklch(0.97 0.012 262)',
                    100 => 'oklch(0.94 0.022 262)',
                    200 => 'oklch(0.88 0.04 262)',
                    300 => 'oklch(0.79 0.06 262)',
                    400 => 'oklch(0.64 0.08 262)',
                    500 => 'oklch(0.5 0.085 262)',
                    600 => 'oklch(0.4 0.075 262)',
                    700 => 'oklch(0.34 0.066 262)',
                    800 => 'oklch(0.29 0.058 262)',
                    900 => 'oklch(0.24 0.05 262)',
                    950 => 'oklch(0.18 0.04 262)',
                ],
            ])
            ->brandLogo(asset('images/logo-unicopag-dark.png'))
            ->darkModeBrandLogo(asset('images/logo-unicopag.png'))
            ->brandLogoHeight('1.75rem')
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->widgets([
                MedOverviewWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->middleware([SetPanelLocale::class], isPersistent: true)
            ->spa()
            ->sidebarCollapsibleOnDesktop();
    }
}
