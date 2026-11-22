<?php

namespace Tests\Feature\Filament\Resources\Roles;

use App\Filament\Resources\Permissions\PermissionResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un usuario con ViewAny_Role puede abrir el listado. La página debe responder
     * con HTTP 200 y mostrar el nombre de un rol guardado en la base de datos.
     */
    public function test_a_user_with_list_permission_can_read_the_roles_table(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $this->signInWithPermissions('ViewAny_Role');

        $this->get(RoleResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($role->name);
    }

    /**
     * Un usuario con acceso al panel intenta abrir directamente la URL del listado.
     * Sin ViewAny_Role, la petición debe recibir HTTP 403.
     */
    public function test_a_panel_user_without_list_permission_is_forbidden_from_listing_roles(): void
    {
        $this->signInWithPermissions();

        $this->get(RoleResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Un usuario que solo tiene ViewAny_Role no debe ver las acciones de crear ni editar
     * en la página del listado.
     */
    public function test_a_user_with_only_list_permission_cannot_see_create_or_edit_actions_in_the_roles_table(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $this->signInWithPermissions('ViewAny_Role');

        Livewire::test(ListRoles::class)
            ->assertActionHidden(CreateAction::class)
            ->assertActionHidden(TestAction::make(EditAction::class)->table($role));
    }

    /**
     * Un usuario con Create_Role y Update_Role debe ver las acciones de crear y editar
     * en la página del listado.
     */
    public function test_a_user_with_create_and_update_permissions_sees_create_and_edit_actions_in_the_roles_table(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role', 'Update_Role');

        Livewire::test(ListRoles::class)
            ->assertActionVisible(CreateAction::class)
            ->assertActionVisible(TestAction::make(EditAction::class)->table($role));
    }

    /**
     * Un usuario que solo puede listar roles intenta abrir la URL del formulario
     * de creación. La petición debe recibir HTTP 403 aunque use la URL directa.
     */
    public function test_a_user_without_create_permission_is_forbidden_from_opening_the_create_form(): void
    {
        $this->signInWithPermissions('ViewAny_Role');

        $this->get(RoleResource::getUrl('create'))->assertForbidden();
    }

    /**
     * Un usuario que solo puede listar roles intenta editar un registro existente
     * mediante su URL directa. La petición debe recibir HTTP 403.
     */
    public function test_a_user_without_update_permission_is_forbidden_from_opening_the_edit_form(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $this->signInWithPermissions('ViewAny_Role');

        $this->get(RoleResource::getUrl('edit', ['record' => $role]))->assertForbidden();
    }

    /**
     * Un usuario con Delete_Role elimina un rol desde su fila en la tabla.
     * Filament debe emitir una notificación y retirar el registro de la base de datos.
     */
    public function test_a_user_with_delete_permission_can_delete_a_role_from_the_table(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $this->signInWithPermissions('ViewAny_Role', 'Delete_Role');

        Livewire::test(ListRoles::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($role))
            ->assertNotified();

        $this->assertModelMissing($role);
    }

    /**
     * Un usuario que solo puede listar roles intenta ejecutar el borrado de una fila
     * mediante Livewire. La acción debe estar oculta y el registro debe conservarse.
     */
    public function test_a_user_without_delete_permission_cannot_delete_a_role_from_the_table(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $this->signInWithPermissions('ViewAny_Role');
        $action = TestAction::make(DeleteAction::class)->table($role);

        Livewire::test(ListRoles::class)
            ->mountAction($action)
            ->call('callMountedAction')
            ->assertActionHidden($action);

        $this->assertModelExists($role);
    }

    /**
     * Al crear un rol seleccionando permisos en la matriz de Shield, Filament debe filtrar
     * los campos de la matriz, crear en BD los permisos faltantes y sincronizarlos al rol.
     */
    public function test_creating_a_role_syncs_selected_matrix_permissions_and_creates_missing_permission_records(): void
    {
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role');

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'web',
                PermissionResource::class => ['View_Permission', 'Delete_Permission'],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Rol creado exitosamente')
            ->assertRedirect(RoleResource::getUrl('index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'bibliotecario',
            'guard_name' => 'web',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'View_Permission',
            'guard_name' => 'web',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'Delete_Permission',
            'guard_name' => 'web',
        ]);

        $createdRole = Role::query()
            ->where('name', 'bibliotecario')
            ->where('guard_name', 'web')
            ->firstOrFail();

        $this->assertSame(
            ['Delete_Permission', 'View_Permission'],
            $createdRole->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * Al crear un rol para un guard distinto de web, los permisos seleccionados en la matriz
     * deben crearse y asociarse usando el mismo guard elegido en el formulario.
     */
    public function test_creating_a_role_for_another_guard_creates_permissions_for_that_guard(): void
    {
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role');

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'api',
                PermissionResource::class => ['ViewAny_Permission'],
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Rol creado exitosamente')
            ->assertRedirect(RoleResource::getUrl('index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'bibliotecario',
            'guard_name' => 'api',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'ViewAny_Permission',
            'guard_name' => 'api',
        ]);

        $createdRole = Role::query()
            ->where('name', 'bibliotecario')
            ->where('guard_name', 'api')
            ->firstOrFail();

        $this->assertSame(
            ['ViewAny_Permission'],
            $createdRole->permissions()->pluck('name')->all(),
        );
    }

    /**
     * Al editar un rol, la matriz debe precargar sus permisos actuales; al marcar uno nuevo
     * y desmarcar otro, deben sincronizarse exactamente los cambios en la base de datos.
     */
    public function test_editing_a_role_hydrates_existing_permissions_and_syncs_added_and_revoked_permissions(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $role->givePermissionTo([
            Permission::findOrCreate('View_Permission', 'web'),
            Permission::findOrCreate('Create_Permission', 'web'),
        ]);
        $this->signInWithPermissions('ViewAny_Role', 'Update_Role');

        $component = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()]);

        $hydratedPermissions = $component->get('data.'.PermissionResource::class);
        sort($hydratedPermissions);
        $this->assertSame(['Create_Permission', 'View_Permission'], $hydratedPermissions);

        $component
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'web',
                PermissionResource::class => ['View_Permission', 'Delete_Permission'],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame(
            ['Delete_Permission', 'View_Permission'],
            $role->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * Al desmarcar todos los permisos de un rol existente y guardar, el rol debe quedar
     * sin permisos asignados en la base de datos.
     */
    public function test_editing_a_role_and_unchecking_all_permissions_revokes_all_assigned_permissions(): void
    {
        $role = Role::factory()->create(['name' => 'bibliotecario']);
        $role->givePermissionTo(Permission::findOrCreate('View_Permission', 'web'));
        $this->signInWithPermissions('ViewAny_Role', 'Update_Role');

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'web',
                PermissionResource::class => [],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame([], $role->fresh()->permissions()->pluck('name')->all());
    }

    /**
     * Enviar el formulario de creación con los campos obligatorios vacíos debe fallar
     * mostrando los mensajes personalizados configurados en RoleForm.
     */
    public function test_creating_a_role_with_empty_required_fields_fails_with_custom_messages(): void
    {
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role');
        $initialRoleCount = Role::query()->count();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => '',
                'guard_name' => '',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'name' => 'El nombre del rol es obligatorio.',
                'guard_name' => 'El grupo de seguridad es obligatorio.',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('roles', $initialRoleCount);
    }

    /**
     * @return array<string, array{overrides: array<string, string>, field: string, expectedMessage: string}>
     */
    public static function invalidRoleFieldLengthProvider(): array
    {
        return [
            'name shorter than 3 characters' => [
                'overrides' => ['name' => 'ab'],
                'field' => 'name',
                'expectedMessage' => 'El nombre debe tener al menos 3 caracteres.',
            ],
            'name longer than 64 characters' => [
                'overrides' => ['name' => str_repeat('a', 65)],
                'field' => 'name',
                'expectedMessage' => 'El nombre no puede superar los 64 caracteres.',
            ],
            'guard_name shorter than 3 characters' => [
                'overrides' => ['guard_name' => 'ab'],
                'field' => 'guard_name',
                'expectedMessage' => 'El guard debe tener al menos 3 caracteres.',
            ],
            'guard_name longer than 32 characters' => [
                'overrides' => ['guard_name' => str_repeat('a', 33)],
                'field' => 'guard_name',
                'expectedMessage' => 'El guard no puede superar los 32 caracteres.',
            ],
        ];
    }

    /**
     * Los límites mínimos y máximos de name y guard_name deben rechazar valores fuera de rango
     * con los mensajes de validación personalizados y sin guardar registros.
     *
     * @param  array<string, string>  $overrides
     */
    #[DataProvider('invalidRoleFieldLengthProvider')]
    public function test_creating_a_role_rejects_invalid_field_lengths_with_custom_messages(
        array $overrides,
        string $field,
        string $expectedMessage,
    ): void {
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role');
        $initialRoleCount = Role::query()->count();

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'web',
                ...$overrides,
            ])
            ->call('create')
            ->assertHasFormErrors([$field => $expectedMessage])
            ->assertNotNotified();

        $this->assertDatabaseCount('roles', $initialRoleCount);
    }

    /**
     * No se puede registrar un rol con el mismo nombre dentro del mismo guard.
     * El formulario debe mostrar el mensaje de unicidad personalizado.
     */
    public function test_creating_a_role_rejects_a_duplicate_name_within_the_same_guard(): void
    {
        Role::factory()->create([
            'name' => 'bibliotecario',
            'guard_name' => 'web',
        ]);
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role');

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'web',
            ])
            ->call('create')
            ->assertHasFormErrors(['name' => 'Este nombre de rol ya está registrado.'])
            ->assertNotNotified();

        $this->assertSame(
            1,
            Role::query()->where('name', 'bibliotecario')->where('guard_name', 'web')->count(),
        );
    }

    /**
     * Un rol puede repetir un nombre existente si pertenece a un guard distinto,
     * respetando la unicidad compuesta (name, guard_name).
     */
    public function test_creating_a_role_allows_the_same_name_under_a_different_guard(): void
    {
        Role::factory()->create([
            'name' => 'bibliotecario',
            'guard_name' => 'web',
        ]);
        $this->signInWithPermissions('ViewAny_Role', 'Create_Role');

        Livewire::test(CreateRole::class)
            ->fillForm([
                'name' => 'bibliotecario',
                'guard_name' => 'api',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Rol creado exitosamente')
            ->assertRedirect(RoleResource::getUrl('index'));

        $this->assertDatabaseHas('roles', [
            'name' => 'bibliotecario',
            'guard_name' => 'api',
        ]);
    }

    /**
     * Al editar un rol existente de nombre corto como admin sin cambiar su nombre,
     * la validación debe ignorar el propio registro y aceptar su longitud actual.
     */
    public function test_editing_a_short_existing_role_keeps_its_own_name_without_validation_errors(): void
    {
        $role = Role::factory()->create([
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $this->signInWithPermissions('ViewAny_Role', 'Update_Role');

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->fillForm([
                'name' => 'admin',
                'guard_name' => 'web',
                PermissionResource::class => ['View_Permission'],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('roles', [
            'id' => $role->getKey(),
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $this->assertSame(
            ['View_Permission'],
            $role->fresh()->permissions()->pluck('name')->all(),
        );
    }

    /**
     * Autentica a un ayudante, un rol que ya puede entrar al panel. Cada prueba decide
     * qué permisos adicionales recibe ese rol para operar sobre el recurso.
     */
    private function signInWithPermissions(string ...$permissionNames): void
    {
        $role = Role::findOrCreate('ayudante', 'web');

        foreach ($permissionNames as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user, 'web');
    }
}
