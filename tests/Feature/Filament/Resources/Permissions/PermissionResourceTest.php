<?php

namespace Tests\Feature\Filament\Resources\Permissions;

use App\Filament\Resources\Permissions\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PermissionResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un super-admin puede abrir el catálogo de permisos en modo solo lectura.
     * La página debe responder con HTTP 200 y mostrar los permisos guardados.
     */
    public function test_super_admin_can_read_the_permissions_table(): void
    {
        $permission = Permission::findOrCreate('View_Book', 'web');
        $this->signInWithRole(Role::SUPER_ADMIN);

        $this->get(PermissionResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($permission->name);
    }

    /**
     * @return array<string, array{roleName: string}>
     */
    public static function nonSuperAdminPanelRolesProvider(): array
    {
        return [
            'admin' => ['roleName' => 'admin'],
            'coordinador' => ['roleName' => 'coordinador'],
            'ayudante' => ['roleName' => 'ayudante'],
        ];
    }

    /**
     * Ningún otro rol del panel puede ver el catálogo de permisos, aunque tenga
     * permisos asignados manualmente. La petición debe recibir HTTP 403.
     */
    #[DataProvider('nonSuperAdminPanelRolesProvider')]
    public function test_non_super_admin_panel_roles_are_forbidden_from_listing_permissions(string $roleName): void
    {
        $this->signInWithRole($roleName, 'ViewAny_Permission');

        $this->get(PermissionResource::getUrl('index'))->assertForbidden();
    }

    /**
     * PermissionPolicy debe bloquear cualquier operación de creación, vista individual,
     * edición o borrado incluso para super-admin porque el módulo es solo lectura.
     */
    public function test_permission_policy_denies_write_operations_even_for_super_admin(): void
    {
        $permission = Permission::findOrCreate('View_Book', 'web');
        $superAdmin = $this->signInWithRole(Role::SUPER_ADMIN);

        $this->assertTrue(Gate::forUser($superAdmin)->allows('viewAny', Permission::class));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('view', $permission));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('create', Permission::class));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('update', $permission));
        $this->assertTrue(Gate::forUser($superAdmin)->denies('delete', $permission));
    }

    /**
     * Autentica a un usuario con el rol indicado y, opcionalmente, permisos adicionales
     * para confirmar que solo el rol super-admin habilita el acceso.
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
