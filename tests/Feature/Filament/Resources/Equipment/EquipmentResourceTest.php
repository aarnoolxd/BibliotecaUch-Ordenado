<?php

namespace Tests\Feature\Filament\Resources\Equipment;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipments;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EquipmentResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un visitante sin sesión que intenta abrir el inventario de equipos debe ser
     * redirigido al formulario de inicio de sesión del panel.
     */
    public function test_guest_is_redirected_to_login_when_visiting_equipment_index(): void
    {
        $this->get(EquipmentResource::getUrl('index'))
            ->assertRedirectToRoute('filament.admin.auth.login');
    }

    /**
     * Un usuario con ViewAny_Equipment puede abrir el listado de inventario. La página debe
     * responder con HTTP 200 y mostrar los datos principales de los equipos registrados.
     */
    public function test_a_user_with_list_permission_can_read_the_equipment_table(): void
    {
        $equipment = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-00101',
            'type' => 'Laptop',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'serial_number' => 'SN-1122334455',
            'status' => 'Disponible',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment');

        $this->get(EquipmentResource::getUrl('index'))
            ->assertOk();

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertCanSeeTableRecords([$equipment])
            ->assertSeeText($equipment->patrimonial_code)
            ->assertSeeText($equipment->type)
            ->assertSeeText($equipment->brand)
            ->assertSeeText($equipment->model)
            ->assertSeeText($equipment->status)
            ->assertCanNotRenderTableColumn('serial_number')
            ->toggleAllTableColumns()
            ->assertCanRenderTableColumn('serial_number')
            ->assertSeeText($equipment->serial_number);
    }

    /**
     * Un usuario con acceso al panel sin ViewAny_Equipment recibe HTTP 403 al abrir el listado.
     */
    public function test_a_panel_user_without_list_permission_is_forbidden_from_listing_equipment(): void
    {
        $this->signInWithPermissions();

        $this->get(EquipmentResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Un usuario que solo tiene ViewAny_Equipment recibe HTTP 403 al intentar abrir directamente
     * los formularios de creación o edición de equipos.
     */
    public function test_a_user_without_create_or_update_permission_is_forbidden_from_create_and_edit_forms(): void
    {
        $equipment = Equipment::factory()->create();
        $this->signInWithPermissions('ViewAny_Equipment');

        $this->get(EquipmentResource::getUrl('create'))->assertForbidden();
        $this->get(EquipmentResource::getUrl('edit', ['record' => $equipment]))->assertForbidden();
    }

    /**
     * @return array<string, array{ability: string, permission: string, requiresRecord: bool}>
     */
    public static function policyPermissionsProvider(): array
    {
        return [
            'viewAny' => ['ability' => 'viewAny', 'permission' => 'ViewAny_Equipment', 'requiresRecord' => false],
            'view' => ['ability' => 'view', 'permission' => 'View_Equipment', 'requiresRecord' => true],
            'create' => ['ability' => 'create', 'permission' => 'Create_Equipment', 'requiresRecord' => false],
            'update' => ['ability' => 'update', 'permission' => 'Update_Equipment', 'requiresRecord' => true],
            'delete' => ['ability' => 'delete', 'permission' => 'Delete_Equipment', 'requiresRecord' => true],
            'deleteAny' => ['ability' => 'deleteAny', 'permission' => 'DeleteAny_Equipment', 'requiresRecord' => false],
        ];
    }

    /**
     * EquipmentPolicy debe denegar cada operación cuando falta su permiso exacto y autorizarla
     * únicamente cuando el usuario cuenta con dicho permiso.
     */
    #[DataProvider('policyPermissionsProvider')]
    public function test_equipment_policy_authorizes_each_ability_based_on_its_matching_permission(
        string $ability,
        string $permission,
        bool $requiresRecord,
    ): void {
        $equipment = Equipment::factory()->create();
        $subject = $requiresRecord ? $equipment : Equipment::class;
        $unauthorizedUser = $this->signInWithPermissions();

        $this->assertTrue(Gate::forUser($unauthorizedUser)->denies($ability, $subject));

        $authorizedUser = $this->signInWithPermissions($permission);

        $this->assertTrue(Gate::forUser($authorizedUser)->allows($ability, $subject));
    }

    /**
     * La tabla de equipos permite buscar por código patrimonial, categoría, marca, modelo
     * y número de serie, filtrando los demás registros.
     */
    public function test_equipment_table_can_search_by_patrimonial_code_type_brand_model_and_serial_number(): void
    {
        $matching = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-90001',
            'type' => 'Laptop',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad X1',
            'serial_number' => 'SN-UNIQUE-001',
        ]);
        $other = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-90002',
            'type' => 'Periférico',
            'brand' => 'Logitech',
            'model' => 'MX Master 3',
            'serial_number' => 'SN-OTHER-002',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment');

        $table = Livewire::test(ListEquipments::class)->loadTable();

        foreach (['UCH-EQ-90001', 'Laptop', 'Lenovo', 'ThinkPad X1', 'SN-UNIQUE-001'] as $term) {
            $table
                ->searchTable($term)
                ->assertCanSeeTableRecords([$matching])
                ->assertCanNotSeeTableRecords([$other]);
        }
    }

    /**
     * La tabla muestra el mensaje de inventario vacío únicamente cuando no hay búsqueda activa;
     * cuando una búsqueda no tiene coincidencias muestra un aviso específico.
     */
    public function test_equipment_table_distinguishes_empty_inventory_from_unmatched_search(): void
    {
        $this->signInWithPermissions('ViewAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertSeeText('Sin equipos registrados')
            ->assertSeeText('El inventario aún no tiene equipos registrados.');

        Equipment::factory()->withStatus('Disponible')->create();

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->searchTable('CODIGO-INEXISTENTE')
            ->assertSeeText('No se encontraron equipos')
            ->assertSeeText('No hay equipos que coincidan con la búsqueda o los filtros aplicados.')
            ->assertDontSeeText('Sin equipos registrados');
    }

    /**
     * La tabla de equipos permite ordenar ascendente y descendentemente por código patrimonial
     * y por marca.
     */
    public function test_equipment_table_can_sort_by_patrimonial_code_and_brand(): void
    {
        $first = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-00001',
            'brand' => 'HP',
        ]);
        $second = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-00002',
            'brand' => 'Dell',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->sortTable('patrimonial_code')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->sortTable('patrimonial_code', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->sortTable('brand')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);
    }

    /**
     * La tabla de equipos permite filtrar por categoría y estado operativo desde el panel
     * de filtros desplegable (slide-over).
     */
    public function test_equipment_table_can_filter_by_type_and_status(): void
    {
        $availableLaptop = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $maintenanceComputer = Equipment::factory()->ofType('Computadora')->withStatus('Mantenimiento')->create();
        $borrowedMouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $this->signInWithPermissions('ViewAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->filterTable('type', 'Laptop')
            ->assertCanSeeTableRecords([$availableLaptop])
            ->assertCanNotSeeTableRecords([$maintenanceComputer, $borrowedMouse])
            ->removeTableFilters()
            ->filterTable('status', 'Mantenimiento')
            ->assertCanSeeTableRecords([$maintenanceComputer])
            ->assertCanNotSeeTableRecords([$availableLaptop, $borrowedMouse]);
    }

    /**
     * Al crear un equipo, el campo status debe permanecer oculto y mutateFormDataBeforeCreate
     * debe forzar siempre el estado inicial Disponible incluso si se manipula el estado Livewire.
     */
    public function test_a_user_with_create_permission_can_create_equipment_with_forced_disponible_status(): void
    {
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        Livewire::test(CreateEquipment::class)
            ->assertFormFieldHidden('status')
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-00123',
                'type' => 'Computadora',
                'brand' => 'Dell',
                'model' => 'OptiPlex 7090',
                'serial_number' => 'SN-9876543210',
                'observations' => 'Equipo nuevo con cargador y cable de poder.',
            ])
            ->set('data.status', 'Baja')
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo registrado')
            ->assertRedirect(EquipmentResource::getUrl('index'));

        $created = Equipment::query()->where('patrimonial_code', 'UCH-EQ-00123')->firstOrFail();

        $this->assertSame('Computadora', $created->type);
        $this->assertSame('Dell', $created->brand);
        $this->assertSame('OptiPlex 7090', $created->model);
        $this->assertSame('SN-9876543210', $created->serial_number);
        $this->assertSame('Disponible', $created->status);
        $this->assertSame('Equipo nuevo con cargador y cable de poder.', $created->observations);
    }

    /**
     * Al editar un equipo, el campo status debe mostrarse visible y permitir actualizar el estado
     * operativo conservando sus propios códigos únicos (ignoreRecord), notificando y redirigiendo.
     */
    public function test_a_user_with_update_permission_can_edit_equipment_status_and_keep_its_own_unique_codes(): void
    {
        $equipment = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-00500',
            'type' => 'Laptop',
            'brand' => 'HP',
            'model' => 'ProBook 450',
            'serial_number' => 'SN-5555500001',
            'status' => 'Disponible',
            'observations' => null,
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(EditEquipment::class, ['record' => $equipment->getRouteKey()])
            ->assertFormFieldVisible('status')
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-00500',
                'type' => 'Laptop',
                'brand' => 'HP Enterprise',
                'model' => 'ProBook 450 G9',
                'serial_number' => 'SN-5555500001',
                'status' => 'Mantenimiento',
                'observations' => 'Revisión preventiva de batería.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo actualizado')
            ->assertRedirect(EquipmentResource::getUrl('index'));

        $equipment->refresh();

        $this->assertSame('UCH-EQ-00500', $equipment->patrimonial_code);
        $this->assertSame('HP Enterprise', $equipment->brand);
        $this->assertSame('ProBook 450 G9', $equipment->model);
        $this->assertSame('SN-5555500001', $equipment->serial_number);
        $this->assertSame('Mantenimiento', $equipment->status);
        $this->assertSame('Revisión preventiva de batería.', $equipment->observations);
    }

    /**
     * Al editar un equipo, no debe permitirse tomar el código patrimonial ni el número de serie
     * ya registrados en otro equipo existente.
     */
    public function test_editing_equipment_rejects_patrimonial_code_or_serial_number_belonging_to_another_record(): void
    {
        Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-70001',
            'serial_number' => 'SN-70001',
        ]);
        $equipment = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-70002',
            'serial_number' => 'SN-70002',
            'brand' => 'Dell',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(EditEquipment::class, ['record' => $equipment->getRouteKey()])
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-70001',
                'type' => $equipment->type,
                'brand' => 'Marca No Guardada',
                'model' => $equipment->model,
                'serial_number' => 'SN-70001',
                'status' => $equipment->status,
            ])
            ->call('save')
            ->assertHasFormErrors([
                'patrimonial_code' => 'Este código patrimonial ya se encuentra registrado en el sistema.',
                'serial_number' => 'Este número de serie ya está registrado en el inventario.',
            ])
            ->assertNotNotified();

        $equipment->refresh();

        $this->assertSame('UCH-EQ-70002', $equipment->patrimonial_code);
        $this->assertSame('SN-70002', $equipment->serial_number);
        $this->assertSame('Dell', $equipment->brand);
    }

    /**
     * Un usuario con Delete_Equipment puede eliminar un equipo desde la tabla.
     */
    public function test_a_user_with_delete_permission_can_delete_equipment_from_the_table(): void
    {
        $equipment = Equipment::factory()->create();
        $this->signInWithPermissions('ViewAny_Equipment', 'Delete_Equipment');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($equipment))
            ->assertNotified();

        $this->assertModelMissing($equipment);
    }

    /**
     * La página de edición de equipos no expone la acción de eliminación en la cabecera,
     * incluso cuando el usuario cuenta con el permiso Delete_Equipment.
     */
    public function test_edit_equipment_page_does_not_expose_delete_action(): void
    {
        $equipment = Equipment::factory()->create();
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment', 'Delete_Equipment');

        Livewire::test(EditEquipment::class, ['record' => $equipment->getRouteKey()])
            ->assertActionDoesNotExist(DeleteAction::class);

        $this->assertModelExists($equipment);
    }

    /**
     * Un usuario sin Delete_Equipment no puede ver ni ejecutar el borrado desde la tabla.
     */
    public function test_a_user_without_delete_permission_cannot_delete_equipment_from_the_table(): void
    {
        $equipment = Equipment::factory()->create();
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');
        $tableDeleteAction = TestAction::make(DeleteAction::class)->table($equipment);

        Livewire::test(ListEquipments::class)
            ->mountAction($tableDeleteAction)
            ->call('callMountedAction')
            ->assertActionHidden($tableDeleteAction);

        $this->assertModelExists($equipment);
    }

    /**
     * Un usuario con DeleteAny_Equipment y Delete_Equipment puede eliminar masivamente
     * solo los equipos seleccionados desde la barra de acciones de la tabla.
     */
    public function test_a_user_with_delete_any_and_delete_permissions_can_bulk_delete_equipment(): void
    {
        $toDelete = Equipment::factory()->count(2)->create();
        $toKeep = Equipment::factory()->create();
        $this->signInWithPermissions('ViewAny_Equipment', 'Delete_Equipment', 'DeleteAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->selectTableRecords($toDelete)
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertNotified();

        foreach ($toDelete as $equipment) {
            $this->assertModelMissing($equipment);
        }

        $this->assertModelExists($toKeep);
    }

    /**
     * Ni la acción de eliminación individual ni la masiva en EquipmentTable permiten eliminar
     * equipos asociados a un préstamo activo (como equipo principal o dispositivo adicional).
     */
    public function test_delete_and_bulk_delete_actions_block_equipment_with_active_borrowings(): void
    {
        $primary = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $additional = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $freeEquipment = Equipment::factory()->ofType('Otro')->withStatus('Disponible')->create();

        $borrowing = Borrowing::factory()
            ->for($primary)
            ->withAdditionalEquipment($additional)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        $this->signInWithPermissions('ViewAny_Equipment', 'Delete_Equipment', 'DeleteAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($primary))
            ->assertNotified('No se puede eliminar');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($additional))
            ->assertNotified('No se puede eliminar');

        Livewire::test(ListEquipments::class)
            ->selectTableRecords([$primary, $freeEquipment])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertNotified('No se pueden eliminar');

        $this->assertModelExists($primary);
        $this->assertModelExists($additional);
        $this->assertModelExists($freeEquipment);
        $this->assertModelExists($borrowing);
    }

    /**
     * Un usuario sin DeleteAny_Equipment no puede ver ni ejecutar el borrado masivo
     * aunque cuente con Delete_Equipment para borrado individual.
     */
    public function test_a_user_without_delete_any_permission_cannot_bulk_delete_equipment(): void
    {
        $records = Equipment::factory()->count(2)->create();
        $this->signInWithPermissions('ViewAny_Equipment', 'Delete_Equipment');
        $bulkDeleteAction = TestAction::make(DeleteBulkAction::class)->table()->bulk();

        Livewire::test(ListEquipments::class)
            ->selectTableRecords($records)
            ->assertActionHidden($bulkDeleteAction);

        foreach ($records as $equipment) {
            $this->assertModelExists($equipment);
        }
    }

    /**
     * Al crear un equipo con campos obligatorios vacíos, deben mostrarse los mensajes
     * personalizados sin crear registros ni enviar notificaciones.
     */
    public function test_creating_equipment_rejects_empty_required_fields(): void
    {
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'patrimonial_code' => '',
                'type' => null,
                'brand' => '',
                'model' => '',
                'serial_number' => '',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'patrimonial_code' => 'El código patrimonial es obligatorio.',
                'type' => 'Debe seleccionar el tipo o categoría de equipo.',
                'brand' => 'La marca del equipo es obligatoria.',
                'model' => 'El modelo del equipo es obligatorio.',
                'serial_number' => 'El número de serie es obligatorio.',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('equipment', 0);
    }

    /**
     * @return array<string, array{overrides: array<string, mixed>, field: string, expectedMessage: ?string}>
     */
    public static function invalidEquipmentFieldValuesProvider(): array
    {
        return [
            'codigo patrimonial demasiado corto' => [
                'overrides' => ['patrimonial_code' => 'AB'],
                'field' => 'patrimonial_code',
                'expectedMessage' => 'El código patrimonial debe tener al menos 3 caracteres.',
            ],
            'codigo patrimonial demasiado largo' => [
                'overrides' => ['patrimonial_code' => str_repeat('A', 51)],
                'field' => 'patrimonial_code',
                'expectedMessage' => 'El código patrimonial no puede superar los 50 caracteres.',
            ],
            'tipo fuera de las opciones permitidas' => [
                'overrides' => ['type' => 'Impresora'],
                'field' => 'type',
                'expectedMessage' => null,
            ],
            'marca demasiado corta' => [
                'overrides' => ['brand' => 'A'],
                'field' => 'brand',
                'expectedMessage' => 'La marca debe tener al menos 2 caracteres.',
            ],
            'marca demasiado larga' => [
                'overrides' => ['brand' => str_repeat('B', 101)],
                'field' => 'brand',
                'expectedMessage' => 'La marca no puede superar los 100 caracteres.',
            ],
            'modelo demasiado corto' => [
                'overrides' => ['model' => 'X'],
                'field' => 'model',
                'expectedMessage' => 'El modelo debe tener al menos 2 caracteres.',
            ],
            'modelo demasiado largo' => [
                'overrides' => ['model' => str_repeat('M', 101)],
                'field' => 'model',
                'expectedMessage' => 'El modelo no puede superar los 100 caracteres.',
            ],
            'numero de serie demasiado corto' => [
                'overrides' => ['serial_number' => '12'],
                'field' => 'serial_number',
                'expectedMessage' => 'El número de serie debe tener al menos 3 caracteres.',
            ],
            'numero de serie demasiado largo' => [
                'overrides' => ['serial_number' => str_repeat('S', 101)],
                'field' => 'serial_number',
                'expectedMessage' => 'El número de serie no puede superar los 100 caracteres.',
            ],
            'observaciones demasiado largas' => [
                'overrides' => ['observations' => str_repeat('O', 501)],
                'field' => 'observations',
                'expectedMessage' => null,
            ],
        ];
    }

    /**
     * Cada regla de validación del formulario de equipos debe rechazar valores fuera
     * de contrato con su mensaje esperado y sin persistir registros.
     *
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidEquipmentFieldValuesProvider')]
    public function test_creating_equipment_rejects_invalid_field_values(
        array $overrides,
        string $field,
        ?string $expectedMessage,
    ): void {
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        $expectedErrors = $expectedMessage !== null ? [$field => $expectedMessage] : [$field];

        Livewire::test(CreateEquipment::class)
            ->fillForm(array_merge([
                'patrimonial_code' => 'UCH-EQ-00200',
                'type' => 'Laptop',
                'brand' => 'Lenovo',
                'model' => 'ThinkPad T14',
                'serial_number' => 'SN-00200-VALID',
                'observations' => 'Sin novedades.',
            ], $overrides))
            ->call('create')
            ->assertHasFormErrors($expectedErrors)
            ->assertNotNotified();

        $this->assertDatabaseCount('equipment', 0);
    }

    /**
     * El formulario debe rechazar tanto un código patrimonial duplicado como un número
     * de serie duplicado mostrando los mensajes personalizados.
     */
    public function test_creating_equipment_rejects_duplicate_patrimonial_code_and_serial_number(): void
    {
        Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-88888',
            'serial_number' => 'SN-88888',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-88888',
                'type' => 'Computadora',
                'brand' => 'HP',
                'model' => 'EliteDesk 800',
                'serial_number' => 'SN-88888',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'patrimonial_code' => 'Este código patrimonial ya se encuentra registrado en el sistema.',
                'serial_number' => 'Este número de serie ya está registrado en el inventario.',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('equipment', 1);
    }

    /**
     * Al editar un equipo, un estado operativo fuera de las opciones válidas debe ser
     * rechazado sin modificar el registro en base de datos.
     */
    public function test_editing_equipment_rejects_invalid_status(): void
    {
        $equipment = Equipment::factory()->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(EditEquipment::class, ['record' => $equipment->getRouteKey()])
            ->fillForm([
                'patrimonial_code' => $equipment->patrimonial_code,
                'type' => $equipment->type,
                'brand' => $equipment->brand,
                'model' => $equipment->model,
                'serial_number' => $equipment->serial_number,
                'status' => 'Extraviado',
            ])
            ->call('save')
            ->assertHasFormErrors(['status'])
            ->assertNotNotified();

        $this->assertSame('Disponible', $equipment->fresh()->status);
    }

    /**
     * Un usuario con Update_Equipment puede descontinuar un equipo sin préstamos activos
     * registrando la baja técnica con fecha y motivo en observaciones y cambiando su estado a Baja.
     */
    public function test_a_user_with_update_permission_can_discontinue_equipment_without_active_borrowings(): void
    {
        $this->travelTo('2026-11-23 16:45:00');
        $equipment = Equipment::factory()->withStatus('Mantenimiento')->create([
            'patrimonial_code' => 'UCH-EQ-00910',
            'observations' => 'Falla detectada en placa base.',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($equipment), [
                'decommission_reason' => 'Placa madre quemada sin repuesto disponible.',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo descontinuado')
            ->assertActionHidden(TestAction::make('discontinue')->table($equipment));

        $equipment->refresh();

        $this->assertSame(Equipment::STATUS_DISCONTINUED, $equipment->status);
        $this->assertSame(
            'Falla detectada en placa base. | [BAJA TÉCNICA - 23/11/2026 16:45]: Placa madre quemada sin repuesto disponible.',
            $equipment->observations,
        );
    }

    /**
     * La acción Descontinuar está oculta para usuarios sin Update_Equipment y para equipos
     * que ya se encuentran en estado Baja.
     */
    public function test_discontinue_action_requires_update_permission_and_is_hidden_for_baja_equipment(): void
    {
        $available = Equipment::factory()->withStatus('Disponible')->create();
        $alreadyBaja = Equipment::factory()->withStatus(Equipment::STATUS_DISCONTINUED)->create();

        $this->signInWithPermissions('ViewAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->assertActionHidden(TestAction::make('discontinue')->table($available))
            ->assertActionHidden(TestAction::make('discontinue')->table($alreadyBaja));

        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(ListEquipments::class)
            ->assertActionVisible(TestAction::make('discontinue')->table($available))
            ->assertActionHidden(TestAction::make('discontinue')->table($alreadyBaja));
    }

    /**
     * No se permite descontinuar un equipo mientras posea un préstamo activo (como equipo
     * principal o como dispositivo adicional), ni desde la tabla ni a nivel de modelo.
     */
    public function test_discontinuing_equipment_with_active_borrowing_is_blocked(): void
    {
        $primary = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create([
            'observations' => 'Sin novedades.',
        ]);
        $additional = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create([
            'observations' => null,
        ]);

        Borrowing::factory()
            ->for($primary)
            ->withAdditionalEquipment($additional)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($primary), [
                'decommission_reason' => 'Intento de baja durante préstamo activo.',
            ])
            ->assertNotified('No se puede descontinuar');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($additional), [
                'decommission_reason' => 'Intento de baja de periférico en préstamo.',
            ])
            ->assertNotified('No se puede descontinuar');

        $this->assertSame(Equipment::DISCONTINUE_ACTIVE_BORROWING, $primary->discontinue('Motivo directo.'));
        $this->assertSame('Prestado', $primary->fresh()->status);
        $this->assertSame('Sin novedades.', $primary->fresh()->observations);
        $this->assertSame('Prestado', $additional->fresh()->status);
        $this->assertNull($additional->fresh()->observations);
    }

    /**
     * La acción Descontinuar recorta espacios extremos antes de validar que el motivo sea
     * obligatorio y tenga entre 5 y 500 caracteres, aceptando un motivo válido de 5 caracteres.
     */
    public function test_discontinue_action_validates_decommission_reason(): void
    {
        $this->travelTo('2026-11-23 17:00:00');
        $equipment = Equipment::factory()->withStatus('Disponible')->create([
            'observations' => null,
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($equipment), [
                'decommission_reason' => '   ',
            ])
            ->assertHasFormErrors([
                'decommission_reason' => 'Debe indicar el motivo de la baja técnica.',
            ])
            ->assertNotNotified();

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($equipment), [
                'decommission_reason' => '1234',
            ])
            ->assertHasFormErrors([
                'decommission_reason' => 'El motivo de la baja técnica debe tener al menos 5 caracteres.',
            ])
            ->assertNotNotified();

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($equipment), [
                'decommission_reason' => '1234 ',
            ])
            ->assertHasFormErrors([
                'decommission_reason' => 'El motivo de la baja técnica debe tener al menos 5 caracteres.',
            ])
            ->assertNotNotified();

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($equipment), [
                'decommission_reason' => str_repeat('R', 501),
            ])
            ->assertHasFormErrors([
                'decommission_reason' => 'El motivo de la baja técnica no puede superar los 500 caracteres.',
            ])
            ->assertNotNotified();

        $this->assertSame('Disponible', $equipment->fresh()->status);

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($equipment), [
                'decommission_reason' => '12345',
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo descontinuado');

        $equipment->refresh();

        $this->assertSame(Equipment::STATUS_DISCONTINUED, $equipment->status);
        $this->assertSame('[BAJA TÉCNICA - 23/11/2026 17:00]: 12345', $equipment->observations);
    }

    /**
     * Si el estado o las observaciones cambian en base de datos después de abrir EditEquipment
     * (por ejemplo, por una baja técnica concurrente), el guardado debe rechazarse sin sobrescribir
     * los datos actuales.
     */
    public function test_editing_equipment_rejects_save_when_status_or_observations_changed_after_loading(): void
    {
        $this->travelTo('2026-11-23 17:15:00');
        $discontinuedConcurrently = Equipment::factory()->withStatus('Disponible')->create([
            'brand' => 'Lenovo',
            'observations' => 'Operativo en sala.',
        ]);
        $observationsChangedConcurrently = Equipment::factory()->withStatus('Disponible')->create([
            'brand' => 'HP',
            'observations' => 'Observación inicial.',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        $staleEditAfterDiscontinue = Livewire::test(EditEquipment::class, [
            'record' => $discontinuedConcurrently->getRouteKey(),
        ]);

        $this->assertSame(
            Equipment::DISCONTINUE_SUCCESS,
            $discontinuedConcurrently->discontinue('Falla irreparable en tarjeta madre.'),
        );

        $staleEditAfterDiscontinue
            ->fillForm([
                'patrimonial_code' => $discontinuedConcurrently->patrimonial_code,
                'type' => $discontinuedConcurrently->type,
                'brand' => 'Lenovo Editado',
                'model' => $discontinuedConcurrently->model,
                'serial_number' => $discontinuedConcurrently->serial_number,
                'status' => 'Mantenimiento',
                'observations' => 'Edición tardía que no debe sobrescribir la baja.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Registro desactualizado')
            ->assertNoRedirect();

        $discontinuedConcurrently->refresh();

        $this->assertSame('Lenovo', $discontinuedConcurrently->brand);
        $this->assertSame(Equipment::STATUS_DISCONTINUED, $discontinuedConcurrently->status);
        $this->assertSame(
            'Operativo en sala. | [BAJA TÉCNICA - 23/11/2026 17:15]: Falla irreparable en tarjeta madre.',
            $discontinuedConcurrently->observations,
        );

        $staleEditAfterObservationsChange = Livewire::test(EditEquipment::class, [
            'record' => $observationsChangedConcurrently->getRouteKey(),
        ]);

        $observationsChangedConcurrently->update([
            'observations' => 'Observación actualizada por otro usuario.',
        ]);

        $staleEditAfterObservationsChange
            ->fillForm([
                'patrimonial_code' => $observationsChangedConcurrently->patrimonial_code,
                'type' => $observationsChangedConcurrently->type,
                'brand' => 'HP Editado',
                'model' => $observationsChangedConcurrently->model,
                'serial_number' => $observationsChangedConcurrently->serial_number,
                'status' => 'Disponible',
                'observations' => 'Observación obsoleta.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Registro desactualizado')
            ->assertNoRedirect();

        $observationsChangedConcurrently->refresh();

        $this->assertSame('HP', $observationsChangedConcurrently->brand);
        $this->assertSame('Observación actualizada por otro usuario.', $observationsChangedConcurrently->observations);
    }

    /**
     * La baja técnica debe respetar siempre el límite máximo de observaciones del formulario
     * de edición incluso con motivos de 500 caracteres o con observaciones previas extensas.
     */
    public function test_discontinue_keeps_observations_within_form_limit_so_equipment_remains_editable(): void
    {
        $this->travelTo('2026-11-23 17:30:00');
        $withoutObservations = Equipment::factory()->withStatus('Disponible')->create([
            'brand' => 'Dell',
            'observations' => null,
        ]);
        $withLongObservations = Equipment::factory()->withStatus('Mantenimiento')->create([
            'observations' => str_repeat('E', 480),
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'Update_Equipment');

        Livewire::test(ListEquipments::class)
            ->callAction(TestAction::make('discontinue')->table($withoutObservations), [
                'decommission_reason' => str_repeat('R', 500),
            ])
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo descontinuado');

        $withoutObservations->refresh();

        $this->assertSame(Equipment::STATUS_DISCONTINUED, $withoutObservations->status);
        $this->assertSame(Equipment::OBSERVATIONS_MAX_LENGTH, mb_strlen((string) $withoutObservations->observations));
        $this->assertStringStartsWith('[BAJA TÉCNICA - 23/11/2026 17:30]: ', (string) $withoutObservations->observations);
        $this->assertStringEndsWith('...', (string) $withoutObservations->observations);

        Livewire::test(EditEquipment::class, ['record' => $withoutObservations->getRouteKey()])
            ->fillForm([
                'patrimonial_code' => $withoutObservations->patrimonial_code,
                'type' => $withoutObservations->type,
                'brand' => 'Dell Actualizada',
                'model' => $withoutObservations->model,
                'serial_number' => $withoutObservations->serial_number,
                'status' => $withoutObservations->status,
                'observations' => $withoutObservations->observations,
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo actualizado');

        $this->assertSame('Dell Actualizada', $withoutObservations->fresh()->brand);

        $reason = 'Falla crítica irreparable en fuente de poder principal.';
        $this->assertSame(Equipment::DISCONTINUE_SUCCESS, $withLongObservations->discontinue($reason));

        $withLongObservations->refresh();

        $this->assertSame(Equipment::STATUS_DISCONTINUED, $withLongObservations->status);
        $this->assertSame(Equipment::OBSERVATIONS_MAX_LENGTH, mb_strlen((string) $withLongObservations->observations));
        $this->assertStringContainsString(
            '... | [BAJA TÉCNICA - 23/11/2026 17:30]: '.$reason,
            (string) $withLongObservations->observations,
        );
    }

    /**
     * Un usuario con Create_Equipment puede adjuntar una fotografía opcional al registrar un equipo
     * y esta se almacena en el directorio privado equipment/photos.
     */
    public function test_a_user_with_create_permission_can_create_equipment_with_optional_photo(): void
    {
        $disk = (string) config('filament.default_filesystem_disk', 'local');
        Storage::fake($disk);
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-00777',
                'type' => 'Laptop',
                'brand' => 'Asus',
                'model' => 'ZenBook 14',
                'serial_number' => 'SN-ASUS-00777',
                'photo' => UploadedFile::fake()->image('zenbook.jpg', 320, 240),
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Equipo registrado')
            ->assertRedirect(EquipmentResource::getUrl('index'));

        $created = Equipment::query()->where('patrimonial_code', 'UCH-EQ-00777')->firstOrFail();

        $this->assertNotNull($created->photo);
        $this->assertStringStartsWith('equipment/photos/', $created->photo);
        Storage::disk($disk)->assertExists($created->photo);
    }

    /**
     * El formulario de equipos rechaza fotografías que superen 4 MB o que no sean JPG, PNG o WebP.
     */
    public function test_creating_equipment_rejects_oversized_or_unsupported_photo_files(): void
    {
        Storage::fake((string) config('filament.default_filesystem_disk', 'local'));
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-00778',
                'type' => 'Computadora',
                'brand' => 'Dell',
                'model' => 'OptiPlex 7010',
                'serial_number' => 'SN-DELL-00778',
                'photo' => UploadedFile::fake()->image('pesada.jpg')->size(4097),
            ])
            ->call('create')
            ->assertHasFormErrors([
                'photo' => 'La imagen no puede superar los 4 MB.',
            ])
            ->assertNotNotified();

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'patrimonial_code' => 'UCH-EQ-00779',
                'type' => 'Computadora',
                'brand' => 'Dell',
                'model' => 'OptiPlex 7010',
                'serial_number' => 'SN-DELL-00779',
                'photo' => UploadedFile::fake()->image('animada.gif'),
            ])
            ->call('create')
            ->assertHasFormErrors(['photo'])
            ->assertNotNotified();

        $this->assertDatabaseCount('equipment', 0);
    }

    /**
     * Al reemplazar la fotografía o eliminar un equipo, el archivo anterior se elimina del disco
     * únicamente si ningún otro equipo sigue referenciándolo.
     */
    public function test_replacing_or_deleting_equipment_photo_cleans_up_unreferenced_files(): void
    {
        $disk = (string) config('filament.default_filesystem_disk', 'local');
        Storage::fake($disk);
        Storage::disk($disk)->put('equipment/photos/old.jpg', 'old-bytes');
        Storage::disk($disk)->put('equipment/photos/new.jpg', 'new-bytes');
        Storage::disk($disk)->put('equipment/photos/shared.jpg', 'shared-bytes');

        $equipment = Equipment::factory()->withPhoto('equipment/photos/old.jpg')->create();
        $firstShared = Equipment::factory()->withPhoto('equipment/photos/shared.jpg')->create();
        $secondShared = Equipment::factory()->withPhoto('equipment/photos/shared.jpg')->create();

        $equipment->update(['photo' => 'equipment/photos/new.jpg']);

        Storage::disk($disk)->assertMissing('equipment/photos/old.jpg');
        Storage::disk($disk)->assertExists('equipment/photos/new.jpg');

        $equipment->delete();

        Storage::disk($disk)->assertMissing('equipment/photos/new.jpg');

        $firstShared->delete();

        Storage::disk($disk)->assertExists('equipment/photos/shared.jpg');

        $secondShared->delete();

        Storage::disk($disk)->assertMissing('equipment/photos/shared.jpg');
    }

    /**
     * La columna Foto de la tabla permanece oculta por defecto y resuelve URLs temporales
     * firmadas sobre el disco privado cuando se habilita.
     */
    public function test_equipment_table_exposes_toggleable_private_photo_column(): void
    {
        $disk = (string) config('filament.default_filesystem_disk', 'local');
        Storage::fake($disk);
        Storage::disk($disk)->put(
            'equipment/photos/tabla.jpg',
            UploadedFile::fake()->image('tabla.jpg', 120, 120)->getContent(),
        );

        $equipment = Equipment::factory()->withPhoto('equipment/photos/tabla.jpg')->create();
        $this->signInWithPermissions('ViewAny_Equipment');

        $component = Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertCanNotRenderTableColumn('photo')
            ->toggleAllTableColumns()
            ->assertCanRenderTableColumn('photo');

        $photoColumn = $component->instance()->getTable()->getColumn('photo');

        $this->assertInstanceOf(ImageColumn::class, $photoColumn);
        $this->assertSame($disk, $photoColumn->getDiskName());
        $this->assertSame('private', $photoColumn->getVisibility());
        $resolvedUrl = $photoColumn->getImageUrl($equipment->photo);
        $this->assertIsString($resolvedUrl);
        $this->assertStringContainsString('equipment/photos/tabla.jpg?expiration=', $resolvedUrl);
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
