<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class PanelAccessTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Si un visitante sin sesión intenta abrir directamente el panel, la aplicación
     * debe redirigirlo al formulario de ingreso.
     */
    public function test_guest_visiting_the_dashboard_is_redirected_to_login(): void
    {
        $this->get(route('filament.admin.pages.dashboard'))
            ->assertRedirectToRoute('filament.admin.auth.login');
    }

    /**
     * Una cuenta ya autenticada intenta abrir el panel sin tener ningún rol asignado.
     * La petición debe recibir un HTTP 403 de acceso denegado.
     */
    public function test_authenticated_user_without_a_role_is_forbidden_from_the_dashboard(): void
    {
        $this->actingAs(User::factory()->create(), 'web')
            ->get(route('filament.admin.pages.dashboard'))
            ->assertForbidden();
    }

    /**
     * Un administrador con sesión activa abre la página de ingreso. La aplicación
     * debe enviarlo directamente al panel sin volver a pedirle las credenciales.
     */
    public function test_authenticated_panel_user_visiting_login_is_redirected_to_the_dashboard(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('admin', 'web'));

        $this->actingAs($user, 'web')
            ->get(route('filament.admin.auth.login'))
            ->assertRedirectToRoute('filament.admin.pages.dashboard');
    }

    /**
     * Un coordinador abre el panel y luego pierde su único rol autorizado. Al volver
     * con la misma sesión, debe seguir autenticado, pero recibir un HTTP 403 porque
     * sus permisos de acceso cambiaron.
     */
    public function test_removing_the_last_allowed_role_blocks_the_next_dashboard_request(): void
    {
        $user = User::factory()->create();
        $user->assignRole(Role::findOrCreate('coordinador', 'web'));
        $dashboard = route('filament.admin.pages.dashboard');

        Auth::guard('web')->login($user);
        $this->withCookie(config('session.cookie'), session()->getId());
        session()->save();
        $this->get($dashboard)->assertOk();

        $user->fresh()->removeRole('coordinador');
        Auth::forgetGuards();

        $this->get($dashboard)->assertForbidden();
        $this->assertAuthenticatedAs($user, 'web');
    }
}
