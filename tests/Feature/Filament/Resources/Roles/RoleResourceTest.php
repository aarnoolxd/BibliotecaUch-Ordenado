<?php

namespace Tests\Feature\Filament\Resources\Roles;

use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use App\Filament\Resources\Roles\RoleResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
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
     * Un super-admin puede abrir el listado de roles y ver la acción de edición.
     */
    public function test_super_admin_can_read_the_roles_table_and_see_edit_action(): void
    {
        $role = Role::findOrCreate('coordinador', 'web');
        $this->signInAsSuperAdmin();

        $this->get(RoleResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($role->name);

        Livewire::test(ListRoles::class)
            ->assertActionVisible(TestAction::make(EditAction::class)->table($role));
    }

    /**
     * Un rol del panel distinto de super-admin recibe HTTP 403 al intentar abrir el listado
     * o la edición de roles, incluso si tuviera permisos asignados manualmente.
     */
    public function test_non_super_admin_panel_user_is_forbidden_from_accessing_role_pages(): void
    {
        $role = Role::findOrCreate('coordinador', 'web');
        $this->signInWithRole('admin', 'ViewAny_Role', 'Update_Role');

        $this->get(RoleResource::getUrl('index'))->assertForbidden();
        $this->get(RoleResource::getUrl('edit', ['record' => $role]))->assertForbidden();
    }

    /**
     * RolePolicy solo habilita listar y editar permisos a super-admin; crear, ver individual
     * y eliminar roles quedan denegados incluso para super-admin porque los roles son fijos.
     */
    public function test_role_policy_denies_creating_and_deleting_roles_even_for_super_admin(): void
    {
        $role = Role::findOrCreate('coordinador', 'web');
        $superAdmin = $this->signInAsSuperAdmin();

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Role::class));
        $this->assertTrue(Gate::forUser($superAdmin)->allows('update', $role));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('view', $role));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('create', Role::class));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('delete', $role));
    }

    /**
     * Al editar un rol, la matriz debe precargar sus permisos actuales; al marcar uno nuevo
     * y desmarcar otro, deben crearse los permisos faltantes y sincronizarse exactamente.
     */
    public function test_editing_a_role_hydrates_existing_permissions_and_syncs_added_and_revoked_permissions(): void
    {
        $this->enableCustomPermissionsInShieldMatrix([
            'View_Book' => 'View Book',
            'Create_Book' => 'Create Book',
            'Delete_Book' => 'Delete Book',
        ]);
        $role = Role::findOrCreate('coordinador', 'web');
        $role->givePermissionTo([
            Permission::findOrCreate('View_Book', 'web'),
            Permission::findOrCreate('Create_Book', 'web'),
        ]);
        $this->signInAsSuperAdmin();

        $component = Livewire::test(EditRole::class, ['record' => $role->getRouteKey()]);

        $hydratedPermissions = $component->get('data.custom_permissions_tab');
        sort($hydratedPermissions);
        $this->assertSame(['Create_Book', 'View_Book'], $hydratedPermissions);

        $component
            ->fillForm([
                'custom_permissions_tab' => ['View_Book', 'Delete_Book'],
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('permissions', [
            'name' => 'Delete_Book',
            'guard_name' => 'web',
        ]);
        $this->assertSame(
            ['Delete_Book', 'View_Book'],
            $role->fresh()->permissions()->orderBy('name')->pluck('name')->all(),
        );
    }

    /**
     * Tanto name como guard_name deben mostrarse deshabilitados al editar un rol y nunca
     * modificarse en base de datos, incluso si se manipula el estado Livewire.
     */
    public function test_name_and_guard_name_are_disabled_and_immutable_when_editing_a_role(): void
    {
        $this->enableCustomPermissionsInShieldMatrix([
            'View_Book' => 'View Book',
        ]);
        $role = Role::findOrCreate('admin', 'web');
        $role->givePermissionTo(Permission::findOrCreate('View_Book', 'web'));
        $this->signInAsSuperAdmin();

        Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])
            ->assertFormFieldDisabled('name')
            ->assertFormFieldDisabled('guard_name')
            ->set('data.name', 'renombrado')
            ->set('data.guard_name', 'api')
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('roles', [
            'id' => $role->getKey(),
            'name' => 'admin',
            'guard_name' => 'web',
        ]);
        $this->assertSame(
            ['View_Book'],
            $role->fresh()->permissions()->pluck('name')->all(),
        );
    }

    /**
     * El panel admin debe tener habilitadas las transacciones de base de datos para que
     * la sincronización de permisos en EditRole se ejecute de forma atómica.
     */
    public function test_admin_panel_enables_database_transactions_for_edit_role(): void
    {
        $role = Role::findOrCreate('coordinador', 'web');
        $this->signInAsSuperAdmin();

        $this->assertTrue(Filament::getPanel('admin')->hasDatabaseTransactions());
        $this->assertTrue(Livewire::test(EditRole::class, ['record' => $role->getRouteKey()])->instance()->hasDatabaseTransactions());
    }

    /**
     * Habilita permisos personalizados en la matriz de Shield solo durante la prueba
     * para ejercitar la sincronización sin depender de recursos excluidos.
     *
     * @param  array<string, string>  $permissions
     */
    private function enableCustomPermissionsInShieldMatrix(array $permissions): void
    {
        config()->set('filament-shield.shield_resource.tabs.custom_permissions', true);
        config()->set('filament-shield.custom_permissions', $permissions);
    }

    private function signInAsSuperAdmin(): User
    {
        return $this->signInWithRole(Role::SUPER_ADMIN);
    }

    /**
     * Autentica a un usuario con el rol indicado y, opcionalmente, permisos adicionales.
     */
    private function signInWithRole(string $roleName, string ...$permissionNames): User
    {
        $role = Role::findOrCreate($roleName, 'web');

        foreach ($permissionNames as $permissionName) {
            $role->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        $this->actingAs($user, 'web');

        return $user;
    }
}
