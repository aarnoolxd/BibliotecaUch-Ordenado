<?php

namespace Tests\Feature\Filament\Resources\Permissions;

use App\Filament\Resources\Permissions\Pages\CreatePermission;
use App\Filament\Resources\Permissions\Pages\EditPermission;
use App\Filament\Resources\Permissions\Pages\ListPermissions;
use App\Filament\Resources\Permissions\PermissionResource;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
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
     * Un usuario con ViewAny_Permission puede abrir el listado. La página debe responder
     * con HTTP 200 y mostrar el nombre de un permiso guardado en la base de datos.
     */
    public function test_a_user_with_list_permission_can_read_the_permissions_table(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission');

        $this->get(PermissionResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($permission->name);
    }

    /**
     * Un usuario con acceso al panel intenta abrir directamente la URL del listado.
     * Sin ViewAny_Permission, la petición debe recibir HTTP 403.
     */
    public function test_a_panel_user_without_list_permission_is_forbidden_from_listing_permissions(): void
    {
        $this->signInWithPermissions();

        $this->get(PermissionResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Un usuario con Create_Permission envía el formulario de creación. Se comprueba
     * que el nombre, el guard y la descripción se guardan y que Filament vuelve al listado.
     */
    public function test_a_user_with_create_permission_can_create_a_permission(): void
    {
        $this->signInWithPermissions('ViewAny_Permission', 'Create_Permission');
        $attributes = [
            'name' => 'Publish_Book',
            'guard_name' => 'web',
            'description' => 'Allows publishing a book.',
        ];

        Livewire::test(CreatePermission::class)
            ->fillForm($attributes)
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(PermissionResource::getUrl('index'));

        $this->assertDatabaseHas('permissions', $attributes);
    }

    /**
     * Un usuario que solo puede listar permisos intenta abrir la URL del formulario
     * de creación. La petición debe recibir HTTP 403 aunque use la URL directa.
     */
    public function test_a_user_without_create_permission_is_forbidden_from_opening_the_create_form(): void
    {
        $this->signInWithPermissions('ViewAny_Permission');

        $this->get(PermissionResource::getUrl('create'))->assertForbidden();
    }

    /**
     * Un usuario con Update_Permission cambia el nombre y la descripción de un permiso.
     * Filament debe notificar el guardado y los cambios deben quedar en el registro original.
     */
    public function test_a_user_with_update_permission_can_edit_an_existing_permission(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission', 'Update_Permission');
        $attributes = [
            'name' => 'Publish_Catalog',
            'guard_name' => 'web',
            'description' => 'Allows publishing the catalog.',
        ];

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->fillForm($attributes)
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertDatabaseHas('permissions', ['id' => $permission->getKey(), ...$attributes]);
    }

    /**
     * Un usuario que solo puede listar permisos intenta editar un registro existente
     * mediante su URL directa. La petición debe recibir HTTP 403.
     */
    public function test_a_user_without_update_permission_is_forbidden_from_opening_the_edit_form(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission');

        $this->get(PermissionResource::getUrl('edit', ['record' => $permission]))->assertForbidden();
    }

    /**
     * Un usuario con Delete_Permission elimina un permiso desde su fila en la tabla.
     * Filament debe emitir una notificación y retirar el registro de la base de datos.
     */
    public function test_a_user_with_delete_permission_can_delete_a_permission_from_the_table(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission', 'Delete_Permission');

        Livewire::test(ListPermissions::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($permission))
            ->assertNotified();

        $this->assertModelMissing($permission);
    }

    /**
     * Un usuario que solo puede listar permisos intenta ejecutar el borrado de una fila
     * mediante Livewire. La acción debe estar oculta y el registro debe conservarse.
     */
    public function test_a_user_without_delete_permission_cannot_delete_a_permission_from_the_table(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission');
        $action = TestAction::make(DeleteAction::class)->table($permission);

        Livewire::test(ListPermissions::class)
            ->mountAction($action)
            ->call('callMountedAction')
            ->assertActionHidden($action);

        $this->assertModelExists($permission);
    }

    /**
     * Un usuario con permisos de edición y borrado elimina un permiso desde el encabezado
     * del formulario de edición. Filament debe volver al listado tras eliminar el registro.
     */
    public function test_a_user_with_delete_permission_can_delete_a_permission_from_the_edit_page(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission', 'Update_Permission', 'Delete_Permission');

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertRedirect(PermissionResource::getUrl('index'));

        $this->assertModelMissing($permission);
    }

    /**
     * Un usuario que puede editar intenta ejecutar el borrado desde el formulario.
     * Sin Delete_Permission, la acción debe estar oculta y el registro debe conservarse.
     */
    public function test_a_user_with_update_permission_but_without_delete_permission_cannot_delete_from_the_edit_page(): void
    {
        $permission = Permission::findOrCreate('Publish_Book', 'web');
        $this->signInWithPermissions('ViewAny_Permission', 'Update_Permission');

        Livewire::test(EditPermission::class, ['record' => $permission->getRouteKey()])
            ->mountAction(DeleteAction::class)
            ->call('callMountedAction')
            ->assertActionHidden(DeleteAction::class);

        $this->assertModelExists($permission);
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
