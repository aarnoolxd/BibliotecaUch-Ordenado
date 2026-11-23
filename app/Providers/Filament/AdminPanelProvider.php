<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Auth\CustomLogin;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Assets\Css;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->databaseTransactions()
            // T6.1: Login personalizado
            ->login(CustomLogin::class)
            // T6.2: Branding y Logo condicional
            ->favicon(asset('/favicon.svg'))
            ->assets([
                Css::make('filament-login', asset('/css/filament-login.css')),
            ])
            ->brandName('UCH')
            ->brandLogo(fn () => Auth::check() ? asset('images/banner.svg') : asset('images/login-logo.svg'))
            ->brandLogoHeight(fn () => Auth::check() ? '1.5rem' : '5rem')
            ->colors([
                'primary' => '#0A2D5F',
                'success' => '#0A2D5F',
                'danger' => '#dc2626', // Rojo para alertas/errores
                'warning' => '#ea580c', // Naranja para advertencias
                'info' => '#2563eb', // Azul claro para información
            ])
            ->navigationGroups([
                'Gestión de Seguridad',
                'Gestión de Perfiles',
                'Gestión de Inventario',
                'Gestión de Préstamos',
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->renderHook(
                PanelsRenderHook::AUTH_LOGIN_FORM_AFTER,
                fn (): View => view('filament.auth.google-button')
            )
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins(
                [
                    FilamentShieldPlugin::make()
                        ->gridColumns([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 3,
                        ])
                        ->sectionColumnSpan(1)
                        ->checkboxListColumns([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 2,
                        ])
                        ->resourceCheckboxListColumns([
                            'default' => 1,
                            'sm' => 2,
                            'lg' => 2,
                        ]),
                ]
            );
    }
}
