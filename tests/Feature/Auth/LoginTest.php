<?php

namespace Tests\Feature\Auth;

use App\Filament\Pages\Auth\CustomLogin;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PASSWORD = 'login-test-password';

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Una persona sin sesión abre la página de ingreso. La página debe responder con HTTP 200
     * y mostrar el formulario personalizado del panel con las etiquetas de correo y contraseña.
     */
    public function test_guest_can_open_the_panel_login_form(): void
    {
        $this->get(route('filament.admin.auth.login'))
            ->assertOk()
            ->assertSeeLivewire(CustomLogin::class)
            ->assertSee(__('filament-panels::auth/pages/login.form.email.label'))
            ->assertSee(__('filament-panels::auth/pages/login.form.password.label'));
    }

    /**
     * Se repite el ingreso con una cuenta por cada rol permitido. Con la contraseña correcta,
     * cada cuenta debe quedar autenticada y poder abrir el panel después de la redirección.
     */
    #[TestWith(['admin'])]
    #[TestWith(['super-admin'])]
    #[TestWith(['coordinador'])]
    #[TestWith(['ayudante'])]
    public function test_each_allowed_role_can_sign_in_and_reach_the_dashboard(string $role): void
    {
        $user = $this->createUser($role);

        $this->submitLogin($user->email, self::PASSWORD)
            ->assertHasNoErrors()
            ->assertRedirectToRoute('filament.admin.pages.dashboard');

        $this->assertAuthenticatedAs($user, 'web');
        $this->get(route('filament.admin.pages.dashboard'))->assertOk();
    }

    /**
     * Un administrador usa su correo real con una contraseña incorrecta. Debe recibir
     * el error genérico de acceso y permanecer en el formulario como invitado.
     */
    public function test_wrong_password_is_rejected(): void
    {
        $user = $this->createUser('admin');

        $this->assertLoginRejected($user->email, 'incorrect');
    }

    /**
     * Si el correo no pertenece a ninguna cuenta, el formulario debe mostrar el mismo error
     * que para una contraseña incorrecta y mantener al visitante sin autenticar.
     */
    public function test_unknown_email_is_rejected(): void
    {
        $this->assertLoginRejected('unknown@example.test', self::PASSWORD);
    }

    /**
     * Dos cuentas intentan entrar con credenciales válidas. Una carece de rol y la otra
     * tiene el rol «lector». Ambas deben recibir el error genérico y seguir sin sesión iniciada.
     */
    #[TestWith([null], 'without a role')]
    #[TestWith(['lector'], 'with an unlisted role')]
    public function test_valid_credentials_are_rejected_without_an_allowed_role(?string $role): void
    {
        $user = $this->createUser($role);

        $this->assertLoginRejected($user->email, self::PASSWORD);
    }

    /**
     * Al enviar el formulario vacío, tanto el correo como la contraseña deben mostrar
     * un error de campo obligatorio. El visitante debe seguir sin autenticar.
     */
    public function test_email_and_password_are_required(): void
    {
        Livewire::test(CustomLogin::class)
            ->call('authenticate')
            ->assertHasFormErrors(['email' => 'required', 'password' => 'required']);

        $this->assertGuest('web');
    }

    /**
     * El visitante escribe «invalid-email» y una contraseña. El formulario debe señalar
     * que el formato del correo es inválido y mantener al visitante sin autenticar.
     */
    public function test_malformed_email_is_rejected(): void
    {
        $this->submitLogin('invalid-email', self::PASSWORD)
            ->assertHasFormErrors(['email' => 'email']);

        $this->assertGuest('web');
    }

    /**
     * Tras cinco contraseñas incorrectas, un administrador usa la correcta en el sexto
     * intento y debe recibir el aviso de bloqueo. Con el reloj simulado se comprueba que
     * a los 59 segundos sigue bloqueado y que a los 60 ya puede entrar con esa misma contraseña.
     */
    public function test_sixth_attempt_is_blocked_until_the_minute_expires(): void
    {
        $user = $this->createUser('admin');
        $this->freezeTime();
        $form = Livewire::test(CustomLogin::class)
            ->fillForm(['email' => $user->email, 'password' => 'incorrect']);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $form->call('authenticate')
                ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')]);
        }

        $form->fillForm(['password' => self::PASSWORD])
            ->call('authenticate')
            ->assertNotified(__('filament-panels::auth/pages/login.notifications.throttled.title', ['seconds' => 60, 'minutes' => 1]))
            ->assertNoRedirect();
        $this->assertGuest('web');

        $this->travel(59)->seconds();
        $form->call('authenticate')->assertNoRedirect();
        $this->assertGuest('web');

        $this->travel(1)->second();

        $form->call('authenticate')->assertRedirectToRoute('filament.admin.pages.dashboard');
        $this->assertAuthenticatedAs($user, 'web');
    }

    /**
     * Se guarda el identificador de sesión antes de que un ayudante ingrese. Una vez
     * autenticado, su sesión debe tener un identificador distinto al que tenía como invitado.
     */
    public function test_login_rotates_the_guest_session_id(): void
    {
        $user = $this->createUser('ayudante');
        $this->startSession();
        $guestSessionId = session()->getId();

        $this->submitLogin($user->email, self::PASSWORD);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertNotSame($guestSessionId, session()->getId());
    }

    /**
     * Un coordinador marca «Recordarme» al ingresar. Después de descartar la sesión
     * y el usuario en memoria, la cookie guardada debe permitirle volver al panel
     * con su propia cuenta sin escribir otra vez la contraseña.
     */
    public function test_remember_me_restores_access_in_a_new_session(): void
    {
        $user = $this->createUser('coordinador');

        $this->submitLogin($user->email, self::PASSWORD, remember: true);
        $cookieName = Auth::guard('web')->getRecallerName();
        $cookie = Cookie::queued($cookieName);
        $this->assertNotNull($cookie);
        session()->invalidate();
        Auth::forgetGuards();

        $this->withCookie($cookieName, $cookie->getValue())
            ->get(route('filament.admin.pages.dashboard'))->assertOk();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertTrue(Auth::guard('web')->viaRemember());
    }

    /**
     * Un coordinador deja «Recordarme» desmarcado. Debe quedar autenticado en la sesión
     * actual sin que el sistema prepare una cookie para recordarlo en otra sesión.
     */
    public function test_login_without_remember_me_does_not_issue_a_remember_cookie(): void
    {
        $user = $this->createUser('coordinador');

        $this->submitLogin($user->email, self::PASSWORD);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertFalse(Cookie::hasQueued(Auth::guard('web')->getRecallerName()));
    }

    /**
     * Un ayudante intenta abrir el panel con «periodo=actual» antes de iniciar sesión.
     * Tras pasar por el formulario de ingreso, debe volver a la dirección que pidió,
     * incluido el parámetro del periodo.
     */
    public function test_login_returns_to_the_originally_requested_dashboard_url(): void
    {
        $user = $this->createUser('ayudante');
        $destination = route('filament.admin.pages.dashboard', ['periodo' => 'actual']);
        $this->get($destination)->assertRedirectToRoute('filament.admin.auth.login');

        $this->submitLogin($user->email, self::PASSWORD)
            ->assertRedirect($destination);

        $this->assertAuthenticatedAs($user, 'web');
    }

    private function createUser(?string $role): User
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        if ($role !== null) {
            $user->assignRole(Role::findOrCreate($role, 'web'));
        }

        return $user;
    }

    private function submitLogin(string $email, string $password, bool $remember = false): Testable
    {
        return Livewire::test(CustomLogin::class)
            ->fillForm(['email' => $email, 'password' => $password, 'remember' => $remember])
            ->call('authenticate');
    }

    /**
     * El mismo mensaje cubre el correo desconocido, la contraseña incorrecta y la falta de rol.
     * Así se evita revelar si la cuenta existe.
     */
    private function assertLoginRejected(string $email, string $password): void
    {
        $this->submitLogin($email, $password)
            ->assertHasFormErrors(['email' => __('filament-panels::auth/pages/login.messages.failed')])
            ->assertNoRedirect();

        $this->assertGuest('web');
    }
}
