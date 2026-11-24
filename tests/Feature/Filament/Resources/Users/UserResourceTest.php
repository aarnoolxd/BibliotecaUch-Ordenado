<?php

namespace Tests\Feature\Filament\Resources\Users;

use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\UserResource;
use App\Models\Borrowing;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class UserResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un usuario con ViewAny_User puede abrir el listado de cuentas. La página debe
     * responder con HTTP 200 y mostrar los usuarios registrados.
     */
    public function test_a_user_with_list_permission_can_read_the_users_table(): void
    {
        $target = User::factory()->create([
            'name' => 'Maria Lopez',
            'email' => 'maria.lopez@uch.pe',
        ]);
        $this->signInWithPermissions('ViewAny_User');

        $this->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($target->name)
            ->assertSeeText($target->email);
    }

    /**
     * Un usuario con acceso al panel sin ViewAny_User recibe HTTP 403 al abrir el listado.
     */
    public function test_a_panel_user_without_list_permission_is_forbidden_from_listing_users(): void
    {
        $this->signInWithPermissions();

        $this->get(UserResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Un usuario que solo tiene ViewAny_User recibe HTTP 403 al intentar abrir directamente
     * los formularios de creación o edición de cuentas.
     */
    public function test_a_user_without_create_or_update_permission_is_forbidden_from_create_and_edit_forms(): void
    {
        $target = User::factory()->create();
        $this->signInWithPermissions('ViewAny_User');

        $this->get(UserResource::getUrl('create'))->assertForbidden();
        $this->get(UserResource::getUrl('edit', ['record' => $target]))->assertForbidden();
    }

    /**
     * Un usuario con Create_User puede registrar una nueva cuenta con contraseña segura y rol.
     * La contraseña debe guardarse hasheada, el rol asociado y Filament debe redirigir al listado.
     */
    public function test_a_user_with_create_permission_can_create_an_account_with_hashed_password_and_role(): void
    {
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        $this->signInWithPermissions('ViewAny_User', 'Create_User');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Carlos Ramos',
                'email' => 'carlos.ramos@uch.pe',
                'password' => 'ClaveSegura1!',
                'roles' => [$coordinadorRole->getKey()],
            ])
            ->callAction(TestAction::make('create')->schemaComponent('form-actions', schema: 'content'))
            ->assertHasNoFormErrors()
            ->assertNotified('Cuenta creada')
            ->assertRedirect(UserResource::getUrl('index'));

        $createdUser = User::query()->where('email', 'carlos.ramos@uch.pe')->firstOrFail();

        $this->assertSame('Carlos Ramos', $createdUser->name);
        $this->assertNotSame('ClaveSegura1!', $createdUser->password);
        $this->assertTrue(Hash::check('ClaveSegura1!', $createdUser->password));
        $this->assertSame(['coordinador'], $createdUser->getRoleNames()->all());
    }

    /**
     * El rol super-admin no debe estar disponible ni poder asignarse al crear una cuenta
     * desde el formulario, incluso si quien crea la cuenta es super-admin.
     */
    public function test_super_admin_role_cannot_be_assigned_when_creating_a_user(): void
    {
        $superAdminRole = Role::findOrCreate(Role::SUPER_ADMIN, 'web');
        $superAdminRole->givePermissionTo([
            Permission::findOrCreate('ViewAny_User', 'web'),
            Permission::findOrCreate('Create_User', 'web'),
        ]);
        $actor = User::factory()->create();
        $actor->assignRole($superAdminRole);
        $this->actingAs($actor, 'web');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Intento Super',
                'email' => 'intento.super@uch.pe',
                'password' => 'ClaveSegura1!',
                'roles' => [$superAdminRole->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['roles.0'])
            ->assertNotNotified();

        $this->assertDatabaseMissing('users', ['email' => 'intento.super@uch.pe']);
    }

    /**
     * Al editar una cuenta dejando la contraseña vacía, se actualizan nombre y correo
     * conservando tanto el hash de contraseña original como todos los roles asignados.
     */
    public function test_a_user_with_update_permission_can_edit_a_user_without_changing_password_or_losing_roles(): void
    {
        $adminRole = Role::findOrCreate('admin', 'web');
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        $target = User::factory()->create([
            'name' => 'Ana Torres',
            'email' => 'ana.torres@uch.pe',
            'password' => 'PasswordInicial1!',
        ]);
        $target->assignRole([$adminRole, $coordinadorRole]);
        $originalPasswordHash = $target->password;
        $this->signInWithPermissions('ViewAny_User', 'Update_User');

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => 'Ana Torres Quispe',
                'email' => 'ana.torres@uch.pe',
                'password' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Cuenta actualizada')
            ->assertRedirect(UserResource::getUrl('index'));

        $target->refresh();

        $this->assertSame('Ana Torres Quispe', $target->name);
        $this->assertSame($originalPasswordHash, $target->password);
        $this->assertSame(
            ['admin', 'coordinador'],
            $target->roles()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * Al editar una cuenta ingresando una nueva contraseña válida, debe actualizarse su hash.
     */
    public function test_a_user_with_update_permission_can_update_a_users_password(): void
    {
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        $target = User::factory()->create(['password' => 'PasswordInicial1!']);
        $target->assignRole($coordinadorRole);
        $this->signInWithPermissions('ViewAny_User', 'Update_User');

        Livewire::test(EditUser::class, ['record' => $target->getRouteKey()])
            ->fillForm([
                'name' => $target->name,
                'email' => $target->email,
                'password' => 'NuevaClave2@',
                'roles' => [$coordinadorRole->getKey()],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Cuenta actualizada')
            ->assertRedirect(UserResource::getUrl('index'));

        $this->assertTrue(Hash::check('NuevaClave2@', $target->fresh()->password));
    }

    /**
     * EditUser::getRedirectUrl solo redirige al listado cuando el usuario cuenta con ViewAny_User;
     * si únicamente cuenta con Update_User (sin permiso de listado), conserva la página actual
     * para evitar una redirección hacia HTTP 403.
     */
    public function test_edit_user_redirect_url_targets_index_only_when_user_can_view_any(): void
    {
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        $target = User::factory()->create();
        $target->assignRole($coordinadorRole);

        $page = new class extends EditUser
        {
            public function resolveRedirectUrl(User $record): ?string
            {
                $this->record = $record;

                return $this->getRedirectUrl();
            }
        };

        $this->signInWithPermissions('Update_User');
        $this->assertNull($page->resolveRedirectUrl($target));

        $this->signInWithPermissions('ViewAny_User', 'Update_User');
        $this->assertSame(UserResource::getUrl('index'), $page->resolveRedirectUrl($target));
    }

    /**
     * Un usuario con Delete_User puede eliminar otra cuenta ordinaria desde la tabla.
     */
    public function test_a_user_with_delete_permission_can_delete_another_user_from_the_table(): void
    {
        $target = User::factory()->create();
        $target->assignRole(Role::findOrCreate('coordinador', 'web'));
        $this->signInWithPermissions('ViewAny_User', 'Delete_User');

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($target))
            ->assertNotified();

        $this->assertModelMissing($target);
    }

    /**
     * Un usuario sin Delete_User no puede ejecutar el borrado de una cuenta desde la tabla.
     */
    public function test_a_user_without_delete_permission_cannot_delete_a_user_from_the_table(): void
    {
        $target = User::factory()->create();
        $this->signInWithPermissions('ViewAny_User');
        $action = TestAction::make(DeleteAction::class)->table($target);

        Livewire::test(ListUsers::class)
            ->mountAction($action)
            ->call('callMountedAction')
            ->assertActionHidden($action);

        $this->assertModelExists($target);
    }

    /**
     * Un usuario con Delete_User no puede eliminarse a sí mismo desde la tabla.
     */
    public function test_a_user_with_delete_permission_cannot_delete_themselves(): void
    {
        $actor = $this->signInWithPermissions('ViewAny_User', 'Delete_User');
        $action = TestAction::make(DeleteAction::class)->table($actor);

        Livewire::test(ListUsers::class)
            ->mountAction($action)
            ->call('callMountedAction')
            ->assertActionHidden($action);

        $this->assertModelExists($actor);
    }

    /**
     * Una cuenta con rol super-admin no puede ser editada ni eliminada desde el módulo,
     * incluso si también posee otro rol como admin.
     */
    public function test_super_admin_accounts_cannot_be_edited_or_deleted_even_with_multiple_roles(): void
    {
        $superAdminTarget = User::factory()->create();
        $superAdminTarget->assignRole([
            Role::findOrCreate(Role::SUPER_ADMIN, 'web'),
            Role::findOrCreate('admin', 'web'),
        ]);
        $this->signInWithPermissions('ViewAny_User', 'Update_User', 'Delete_User');

        $this->get(UserResource::getUrl('edit', ['record' => $superAdminTarget]))->assertForbidden();

        $deleteAction = TestAction::make(DeleteAction::class)->table($superAdminTarget);

        Livewire::test(ListUsers::class)
            ->mountAction($deleteAction)
            ->call('callMountedAction')
            ->assertActionHidden($deleteAction);

        $this->assertModelExists($superAdminTarget);
        $this->assertFalse($superAdminTarget->delete());
        $this->assertTrue($superAdminTarget->fresh()->hasRole(Role::SUPER_ADMIN));
    }

    /**
     * No se permite eliminar una cuenta de usuario (ni desde la tabla ni a nivel de modelo)
     * si posee préstamos activos registrados o vinculados a su perfil.
     */
    public function test_user_with_active_borrowings_cannot_be_deleted_from_table_or_model(): void
    {
        $operatorWithActiveBorrowing = User::factory()->create();
        Borrowing::factory()->for($operatorWithActiveBorrowing, 'registeredBy')->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
        ]);

        $studentUserWithActiveBorrowing = User::factory()->create();
        $studentProfile = Profile::factory()->for($studentUserWithActiveBorrowing)->create();
        Borrowing::factory()->for($studentProfile)->overdue()->create();

        $this->signInWithPermissions('ViewAny_User', 'Delete_User');

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($operatorWithActiveBorrowing))
            ->assertNotified('No se puede eliminar');

        Livewire::test(ListUsers::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($studentUserWithActiveBorrowing))
            ->assertNotified('No se puede eliminar');

        $this->assertFalse($operatorWithActiveBorrowing->delete());
        $this->assertFalse($studentUserWithActiveBorrowing->delete());
        $this->assertModelExists($operatorWithActiveBorrowing);
        $this->assertModelExists($studentUserWithActiveBorrowing);
    }

    /**
     * La tabla de usuarios permite filtrar por rol asignado y por vinculación de perfil.
     */
    public function test_users_table_can_filter_by_role_and_profile_link(): void
    {
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        $adminRole = Role::findOrCreate('admin', 'web');

        $coordinatorWithProfile = User::factory()->create();
        $coordinatorWithProfile->assignRole($coordinadorRole);
        Profile::factory()->for($coordinatorWithProfile)->create();

        $adminWithoutProfile = User::factory()->create();
        $adminWithoutProfile->assignRole($adminRole);

        $this->signInWithPermissions('ViewAny_User');

        Livewire::test(ListUsers::class)
            ->filterTable('roles', $coordinadorRole->getKey())
            ->assertCanSeeTableRecords([$coordinatorWithProfile])
            ->assertCanNotSeeTableRecords([$adminWithoutProfile])
            ->removeTableFilters()
            ->filterTable('has_profile', true)
            ->assertCanSeeTableRecords([$coordinatorWithProfile])
            ->assertCanNotSeeTableRecords([$adminWithoutProfile])
            ->filterTable('has_profile', false)
            ->assertCanSeeTableRecords([$adminWithoutProfile])
            ->assertCanNotSeeTableRecords([$coordinatorWithProfile]);
    }

    /**
     * Al crear una cuenta, los campos obligatorios vacíos deben rechazarse con sus mensajes
     * personalizados y una contraseña débil debe ser rechazada por la política de complejidad.
     */
    public function test_creating_a_user_rejects_empty_required_fields_and_weak_passwords(): void
    {
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        $this->signInWithPermissions('ViewAny_User', 'Create_User');
        $initialUserCount = User::query()->count();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => '',
                'email' => '',
                'password' => '',
                'roles' => [],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'El nombre es obligatorio.',
                'email' => 'El correo electrónico es obligatorio.',
                'password' => 'La contraseña es obligatoria.',
                'roles' => 'Debe seleccionar al menos un rol.',
            ])
            ->assertNotNotified();

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Usuario Prueba',
                'email' => 'usuario.prueba@uch.pe',
                'password' => 'solominusculas',
                'roles' => [$coordinadorRole->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors(['password'])
            ->assertNotNotified();

        $this->assertDatabaseCount('users', $initialUserCount);
    }

    /**
     * El correo electrónico debe ser único al crear cuentas y mostrar el mensaje personalizado.
     */
    public function test_creating_a_user_rejects_a_duplicate_email(): void
    {
        $coordinadorRole = Role::findOrCreate('coordinador', 'web');
        User::factory()->create(['email' => 'duplicado@uch.pe']);
        $this->signInWithPermissions('ViewAny_User', 'Create_User');

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Otra Persona',
                'email' => 'duplicado@uch.pe',
                'password' => 'ClaveSegura1!',
                'roles' => [$coordinadorRole->getKey()],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'email' => 'Este correo electrónico ya se encuentra registrado.',
            ])
            ->assertNotNotified();

        $this->assertSame(1, User::query()->where('email', 'duplicado@uch.pe')->count());
    }

    /**
     * Autentica a un ayudante, un rol que ya puede entrar al panel. Cada prueba decide
     * qué permisos adicionales recibe ese rol para operar sobre el recurso.
     */
    private function signInWithPermissions(string ...$permissionNames): User
    {
        $role = Role::findOrCreate('ayudante', 'web');

        foreach ($permissionNames as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user, 'web');

        return $user;
    }
}
