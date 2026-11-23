<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Al cerrar sesión, un administrador debe volver al formulario de ingreso como invitado.
     * Se comprueba que cambió el identificador de sesión y que se borró una preferencia
     * guardada. Si vuelve a abrir el panel con la cookie anterior, se le debe pedir que ingrese.
     */
    public function test_logout_invalidates_the_session_and_prevents_further_panel_access(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));
        $this->withSession(['panel-preference' => 'books']);
        Auth::guard('web')->login($user);
        $sessionId = session()->getId();
        $sessionCookie = config('session.cookie');
        session()->save();

        $this->withCookie($sessionCookie, $sessionId)
            ->post(route('filament.admin.auth.logout'))
            ->assertRedirectToRoute('filament.admin.auth.login')
            ->assertSessionMissing('panel-preference');

        $this->assertGuest('web');
        $this->assertNotSame($sessionId, session()->getId());
        Auth::forgetGuards();
        $this->get(route('filament.admin.pages.dashboard'))
            ->assertRedirectToRoute('filament.admin.auth.login');
    }
}
