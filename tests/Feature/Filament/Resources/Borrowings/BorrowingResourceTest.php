<?php

namespace Tests\Feature\Filament\Resources\Borrowings;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Filament\Resources\Borrowings\Pages\CreateBorrowing;
use App\Filament\Resources\Borrowings\Pages\EditBorrowing;
use App\Filament\Resources\Borrowings\Pages\ListBorrowings;
use App\Filament\Resources\Borrowings\Pages\ViewBorrowing;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BorrowingResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un visitante sin sesión que intenta abrir el listado de préstamos debe ser
     * redirigido al formulario de inicio de sesión del panel.
     */
    public function test_guest_is_redirected_to_login_when_visiting_borrowings_index(): void
    {
        $this->get(BorrowingResource::getUrl('index'))
            ->assertRedirectToRoute('filament.admin.auth.login');
    }

    /**
     * Un usuario con ViewAny_Borrowing puede abrir el listado de préstamos y visualizar
     * los registros vinculados a perfiles así como el texto de respaldo sin perfil.
     */
    public function test_a_user_with_list_permission_can_read_the_borrowings_table(): void
    {
        $profile = Profile::factory()->create([
            'dni' => '71234567',
            'first_name' => 'Valeria',
            'last_name' => 'Mendoza',
        ]);
        $equipment = Equipment::factory()->create([
            'patrimonial_code' => 'UCH-EQ-01001',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'status' => 'Prestado',
        ]);
        $linkedBorrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->create(['status' => 'En curso']);
        $unlinkedBorrowing = Borrowing::factory()->create([
            'profile_id' => null,
            'student_dni' => '79998888',
            'status' => 'Devuelto',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing');

        $this->get(BorrowingResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($linkedBorrowing->student_dni)
            ->assertSeeText('Valeria Mendoza')
            ->assertSeeText($equipment->patrimonial_code)
            ->assertSeeText($equipment->model)
            ->assertSeeText('En curso')
            ->assertSeeText($unlinkedBorrowing->student_dni)
            ->assertSeeText('Estudiante no vinculado');
    }

    /**
     * Un usuario con acceso al panel sin ViewAny_Borrowing recibe HTTP 403 al abrir el listado.
     */
    public function test_a_panel_user_without_list_permission_is_forbidden_from_listing_borrowings(): void
    {
        $this->signInWithPermissions();

        $this->get(BorrowingResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Un usuario que solo tiene ViewAny_Borrowing recibe HTTP 403 al intentar abrir
     * directamente las páginas de vista individual, creación o edición de préstamos.
     */
    public function test_a_user_without_view_create_or_update_permission_is_forbidden_from_those_pages(): void
    {
        $borrowing = Borrowing::factory()->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        $this->get(BorrowingResource::getUrl('view', ['record' => $borrowing]))->assertForbidden();
        $this->get(BorrowingResource::getUrl('create'))->assertForbidden();
        $this->get(BorrowingResource::getUrl('edit', ['record' => $borrowing]))->assertForbidden();
    }

    /**
     * @return array<string, array{ability: string, permission: string, requiresRecord: bool}>
     */
    public static function policyPermissionsProvider(): array
    {
        return [
            'viewAny' => ['ability' => 'viewAny', 'permission' => 'ViewAny_Borrowing', 'requiresRecord' => false],
            'view' => ['ability' => 'view', 'permission' => 'View_Borrowing', 'requiresRecord' => true],
            'create' => ['ability' => 'create', 'permission' => 'Create_Borrowing', 'requiresRecord' => false],
            'update' => ['ability' => 'update', 'permission' => 'Update_Borrowing', 'requiresRecord' => true],
            'delete' => ['ability' => 'delete', 'permission' => 'Delete_Borrowing', 'requiresRecord' => true],
            'deleteAny' => ['ability' => 'deleteAny', 'permission' => 'DeleteAny_Borrowing', 'requiresRecord' => false],
        ];
    }

    /**
     * BorrowingPolicy debe denegar cada operación cuando falta su permiso exacto y autorizarla
     * únicamente cuando el usuario cuenta con dicho permiso.
     */
    #[DataProvider('policyPermissionsProvider')]
    public function test_borrowing_policy_authorizes_each_ability_based_on_its_matching_permission(
        string $ability,
        string $permission,
        bool $requiresRecord,
    ): void {
        $borrowing = Borrowing::factory()->create();
        $subject = $requiresRecord ? $borrowing : Borrowing::class;
        $unauthorizedUser = $this->signInWithPermissions();

        $this->assertTrue(Gate::forUser($unauthorizedUser)->denies($ability, $subject));

        $authorizedUser = $this->signInWithPermissions($permission);

        $this->assertTrue(Gate::forUser($authorizedUser)->allows($ability, $subject));
    }

    /**
     * Un usuario con View_Borrowing puede consultar el detalle de un préstamo registrado.
     */
    public function test_a_user_with_view_permission_can_view_a_borrowing_record(): void
    {
        $borrowing = Borrowing::factory()->create([
            'observations' => 'Entrega con maletín y cargador original.',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'View_Borrowing');

        $this->get(BorrowingResource::getUrl('view', ['record' => $borrowing]))
            ->assertOk();

        Livewire::test(ViewBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->assertSchemaStateSet([
                'student_dni' => $borrowing->student_dni,
                'equipment_id' => $borrowing->equipment_id,
                'status' => $borrowing->status,
                'observations' => 'Entrega con maletín y cargador original.',
            ]);
    }

    /**
     * La tabla de préstamos permite filtrar por estado (En curso, Devuelto, Vencido).
     */
    public function test_borrowings_table_can_filter_by_status(): void
    {
        $inProgress = Borrowing::factory()->create(['status' => 'En curso']);
        $returned = Borrowing::factory()->returned()->create();
        $overdue = Borrowing::factory()->overdue()->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->filterTable('status', 'En curso')
            ->assertCanSeeTableRecords([$inProgress])
            ->assertCanNotSeeTableRecords([$returned, $overdue])
            ->filterTable('status', 'Devuelto')
            ->assertCanSeeTableRecords([$returned])
            ->assertCanNotSeeTableRecords([$inProgress, $overdue])
            ->filterTable('status', 'Vencido')
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$inProgress, $returned]);
    }

    /**
     * La tabla de préstamos permite ordenar por DNI del estudiante y fecha límite de devolución,
     * comprobando un orden de vencimiento distinto al orden ascendente y descendente por DNI.
     */
    public function test_borrowings_table_can_sort_by_student_dni_and_due_date(): void
    {
        $firstProfile = Profile::factory()->create(['dni' => '70000010']);
        $secondProfile = Profile::factory()->create(['dni' => '70000020']);
        $thirdProfile = Profile::factory()->create(['dni' => '70000030']);

        $first = Borrowing::factory()->for($firstProfile)->create([
            'due_date' => now()->addDays(3),
        ]);
        $second = Borrowing::factory()->for($secondProfile)->create([
            'due_date' => now()->addDay(),
        ]);
        $third = Borrowing::factory()->for($thirdProfile)->create([
            'due_date' => now()->addDays(2),
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->sortTable('student_dni')
            ->assertCanSeeTableRecords([$first, $second, $third], inOrder: true)
            ->sortTable('student_dni', 'desc')
            ->assertCanSeeTableRecords([$third, $second, $first], inOrder: true)
            ->sortTable('due_date')
            ->assertCanSeeTableRecords([$second, $third, $first], inOrder: true);
    }

    /**
     * La tabla de préstamos permite buscar y ordenar por el nombre completo del estudiante
     * usando las columnas reales first_name y last_name de profiles.
     */
    public function test_borrowings_table_can_search_and_sort_by_student_full_name(): void
    {
        $anaProfile = Profile::factory()->create([
            'dni' => '70100001',
            'first_name' => 'Ana',
            'last_name' => 'Zapata',
        ]);
        $brunoProfile = Profile::factory()->create([
            'dni' => '70100002',
            'first_name' => 'Bruno',
            'last_name' => 'Alvarez',
        ]);
        $anaBorrowing = Borrowing::factory()->for($anaProfile)->create();
        $brunoBorrowing = Borrowing::factory()->for($brunoProfile)->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        $table = Livewire::test(ListBorrowings::class);

        foreach (['Ana', 'Zapata', 'ana zapata'] as $term) {
            $table
                ->searchTable($term)
                ->assertCanSeeTableRecords([$anaBorrowing])
                ->assertCanNotSeeTableRecords([$brunoBorrowing]);
        }

        Livewire::test(ListBorrowings::class)
            ->sortTable('profile.full_name')
            ->assertCanSeeTableRecords([$anaBorrowing, $brunoBorrowing], inOrder: true)
            ->sortTable('profile.full_name', 'desc')
            ->assertCanSeeTableRecords([$brunoBorrowing, $anaBorrowing], inOrder: true);
    }

    /**
     * Al ingresar un DNI registrado en el formulario, deben autocompletarse profile_id
     * y student_name; al cambiarlo por un DNI inexistente o vaciarlo desde un estado cargado,
     * ambos campos deben limpiarse.
     */
    public function test_entering_a_registered_student_dni_populates_profile_and_name_and_clearing_resets_them(): void
    {
        $profile = Profile::factory()->create([
            'dni' => '72223333',
            'first_name' => 'Lucia',
            'last_name' => 'Rojas Paredes',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->set('data.student_dni', '72223333')
            ->assertSet('data.profile_id', $profile->getKey())
            ->assertSet('data.student_name', 'Lucia Rojas Paredes')
            ->set('data.student_dni', '79999999')
            ->assertSet('data.profile_id', null)
            ->assertSet('data.student_name', null)
            ->set('data.student_dni', '72223333')
            ->assertSet('data.profile_id', $profile->getKey())
            ->assertSet('data.student_name', 'Lucia Rojas Paredes')
            ->set('data.student_dni', '')
            ->assertSet('data.profile_id', null)
            ->assertSet('data.student_name', null);
    }

    /**
     * @return array<string, array{type: string, expectedDueDate: string, updatedBorrowingDate: string, expectedRecalculatedDueDate: string}>
     */
    public static function equipmentDueDateRulesProvider(): array
    {
        return [
            'Laptop suma 2 dias' => [
                'type' => 'Laptop',
                'expectedDueDate' => '2026-11-25 10:00:00',
                'updatedBorrowingDate' => '2026-11-24 08:30:00',
                'expectedRecalculatedDueDate' => '2026-11-26 08:30:00',
            ],
            'Computadora suma 6 horas' => [
                'type' => 'Computadora',
                'expectedDueDate' => '2026-11-23 16:00:00',
                'updatedBorrowingDate' => '2026-11-24 08:30:00',
                'expectedRecalculatedDueDate' => '2026-11-24 14:30:00',
            ],
            'Periferico suma 1 dia' => [
                'type' => 'Periférico',
                'expectedDueDate' => '2026-11-24 10:00:00',
                'updatedBorrowingDate' => '2026-11-24 08:30:00',
                'expectedRecalculatedDueDate' => '2026-11-25 08:30:00',
            ],
            'Otro suma 1 dia' => [
                'type' => 'Otro',
                'expectedDueDate' => '2026-11-24 10:00:00',
                'updatedBorrowingDate' => '2026-11-24 08:30:00',
                'expectedRecalculatedDueDate' => '2026-11-25 08:30:00',
            ],
        ];
    }

    /**
     * Al seleccionar un equipo o modificar la fecha de préstamo, el formulario debe calcular
     * automáticamente due_date según la regla de plazo de la categoría del equipo.
     */
    #[DataProvider('equipmentDueDateRulesProvider')]
    public function test_selecting_equipment_or_changing_borrowing_date_calculates_due_date_by_equipment_type(
        string $type,
        string $expectedDueDate,
        string $updatedBorrowingDate,
        string $expectedRecalculatedDueDate,
    ): void {
        $this->travelTo('2026-11-23 10:00:00');
        $equipment = Equipment::factory()->ofType($type)->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->set('data.borrowing_date', '2026-11-23 10:00:00')
            ->set('data.equipment_id', $equipment->getKey())
            ->assertSet('data.due_date', $expectedDueDate)
            ->set('data.borrowing_date', $updatedBorrowingDate)
            ->assertSet('data.due_date', $expectedRecalculatedDueDate);
    }

    /**
     * Un usuario con Create_Borrowing puede registrar un préstamo para un estudiante y equipo
     * disponibles: se aplica la regla de vencimiento del tipo de equipo incluso ante un valor
     * manual distinto, se asigna estado En curso, registered_by, se cambia el equipo a Prestado,
     * se notifica y se redirige al listado.
     */
    public function test_a_user_with_create_permission_can_create_a_borrowing_and_marks_equipment_as_prestado(): void
    {
        $this->travelTo('2026-11-23 09:00:00');
        $profile = Profile::factory()->create([
            'dni' => '73334444',
            'first_name' => 'Mateo',
            'last_name' => 'Salazar',
        ]);
        $equipment = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $actor = $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->assertFormFieldDisabled('status')
            ->fillForm([
                'student_dni' => '73334444',
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 09:00:00',
                'due_date' => '2026-11-24 09:00:00',
                'observations' => 'Entregada con cargador original.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Préstamo registrado exitosamente en estado "En curso"')
            ->assertRedirect(BorrowingResource::getUrl('index'));

        $borrowing = Borrowing::query()->where('student_dni', '73334444')->firstOrFail();

        $this->assertSame($equipment->getKey(), $borrowing->equipment_id);
        $this->assertSame($profile->getKey(), $borrowing->profile_id);
        $this->assertSame($actor->getKey(), $borrowing->registered_by);
        $this->assertSame('En curso', $borrowing->status);
        $this->assertSame('2026-11-23 09:00:00', $borrowing->borrowing_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-25 09:00:00', $borrowing->due_date->format('Y-m-d H:i:s'));
        $this->assertNull($borrowing->return_date);
        $this->assertSame('Entregada con cargador original.', $borrowing->observations);
        $this->assertSame('Prestado', $equipment->fresh()->status);
    }

    /**
     * Si el equipo cambia a Mantenimiento antes de completar el alta, CreateBorrowing
     * debe rechazar el guardado asociando el error al campo data.equipment_id.
     */
    public function test_create_borrowing_hook_attaches_validation_error_to_form_field_when_equipment_becomes_unavailable(): void
    {
        $profile = Profile::factory()->create(['dni' => '73339999']);
        $equipment = Equipment::factory()->withStatus('Disponible')->create([
            'brand' => 'HP',
            'model' => 'ProBook 450',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        $component = Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 09:00:00',
                'due_date' => '2026-11-24 09:00:00',
            ]);

        $equipment->update(['status' => 'Mantenimiento']);

        $component
            ->call('create')
            ->assertHasFormErrors(['equipment_id']);

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame('Mantenimiento', $equipment->fresh()->status);
    }

    /**
     * Un usuario con Update_Borrowing puede editar un préstamo activo conservando su
     * propio equipo Prestado y actualizar sus observaciones y fecha límite.
     */
    public function test_a_user_with_update_permission_can_edit_an_active_borrowing_keeping_its_equipment(): void
    {
        $profile = Profile::factory()->create(['dni' => '74445555']);
        $equipment = Equipment::factory()->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->create([
                'status' => 'En curso',
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-21 09:00:00',
                'observations' => 'Observación inicial.',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => '74445555',
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-22 09:00:00',
                'observations' => 'Observación actualizada.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $borrowing->refresh();

        $this->assertSame('2026-11-22 09:00:00', $borrowing->due_date->format('Y-m-d H:i:s'));
        $this->assertSame('Observación actualizada.', $borrowing->observations);
        $this->assertSame('Prestado', $equipment->fresh()->status);
    }

    /**
     * Al cambiar student_dni al editar un préstamo, EditBorrowing debe actualizar
     * también profile_id al perfil correspondiente aunque no se dispare el evento blur.
     */
    public function test_editing_a_borrowing_updates_profile_id_when_student_dni_changes(): void
    {
        $originalProfile = Profile::factory()->create(['dni' => '74447701']);
        $newProfile = Profile::factory()->create(['dni' => '74447702']);
        $equipment = Equipment::factory()->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($originalProfile)
            ->create([
                'status' => 'En curso',
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-22 09:00:00',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => $newProfile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-22 09:00:00',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $borrowing->refresh();

        $this->assertSame($newProfile->dni, $borrowing->student_dni);
        $this->assertSame($newProfile->getKey(), $borrowing->profile_id);
    }

    /**
     * Al editar un préstamo, due_date no puede ser anterior a borrowing_date.
     */
    public function test_editing_a_borrowing_rejects_due_date_earlier_than_borrowing_date(): void
    {
        $profile = Profile::factory()->create(['dni' => '74448801']);
        $equipment = Equipment::factory()->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->create([
                'status' => 'En curso',
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-22 09:00:00',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-19 08:00:00',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'due_date' => 'La fecha límite de devolución no puede ser anterior a la fecha del préstamo.',
            ])
            ->assertNotNotified();

        $this->assertSame('2026-11-22 09:00:00', $borrowing->fresh()->due_date->format('Y-m-d H:i:s'));
    }

    /**
     * Al reasignar el equipo de un préstamo activo de A a B, el equipo B pasa a Prestado
     * y el equipo A regresa a Disponible si no posee otro préstamo activo.
     */
    public function test_changing_equipment_on_an_active_borrowing_syncs_both_equipment_statuses(): void
    {
        $profile = Profile::factory()->create(['dni' => '74446666']);
        $previousEquipment = Equipment::factory()->withStatus('Prestado')->create();
        $newEquipment = Equipment::factory()->withStatus('Disponible')->create();
        $borrowing = Borrowing::factory()
            ->for($previousEquipment)
            ->for($profile)
            ->create(['status' => 'En curso']);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $newEquipment->getKey(),
                'borrowing_date' => $borrowing->borrowing_date->format('Y-m-d H:i:s'),
                'due_date' => $borrowing->due_date->format('Y-m-d H:i:s'),
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertSame($newEquipment->getKey(), $borrowing->fresh()->equipment_id);
        $this->assertSame('Prestado', $newEquipment->fresh()->status);
        $this->assertSame('Disponible', $previousEquipment->fresh()->status);
    }

    /**
     * Si dos solicitudes leen el mismo préstamo con el equipo A y la primera lo reasigna a B
     * antes de que la segunda lo reasigne a C, handleRecordUpdate debe bloquear y recargar el
     * préstamo desde la base de datos para liberar B y no dejarlo retenido como Prestado.
     */
    public function test_stale_borrowing_instance_reloads_locked_record_and_releases_current_equipment(): void
    {
        $equipmentA = Equipment::factory()->withStatus('Prestado')->create();
        $equipmentB = Equipment::factory()->withStatus('Disponible')->create();
        $equipmentC = Equipment::factory()->withStatus('Disponible')->create();
        $borrowing = Borrowing::factory()->for($equipmentA)->create(['status' => 'En curso']);

        $staleSecondInstance = Borrowing::query()->findOrFail($borrowing->getKey());

        $page = new class extends EditBorrowing
        {
            /**
             * @param  array<string, mixed>  $data
             */
            public function executeRecordUpdate(Borrowing $record, array $data): Borrowing
            {
                /** @var Borrowing $updated */
                $updated = $this->handleRecordUpdate($record, $data);

                return $updated;
            }
        };

        $page->executeRecordUpdate($borrowing, ['equipment_id' => $equipmentB->getKey()]);
        $page->executeRecordUpdate($staleSecondInstance, ['equipment_id' => $equipmentC->getKey()]);

        $this->assertSame($equipmentC->getKey(), $borrowing->fresh()->equipment_id);
        $this->assertSame('Disponible', $equipmentA->fresh()->status);
        $this->assertSame('Disponible', $equipmentB->fresh()->status);
        $this->assertSame('Prestado', $equipmentC->fresh()->status);
    }

    /**
     * Un usuario con Delete_Borrowing puede eliminar un préstamo activo desde la cabecera
     * de edición, devolviendo el equipo a Disponible si no tiene otro préstamo activo.
     */
    public function test_a_user_with_delete_permission_can_delete_a_borrowing_from_the_edit_page(): void
    {
        $equipment = Equipment::factory()->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()->for($equipment)->create(['status' => 'En curso']);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing', 'Delete_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->callAction(DeleteAction::class)
            ->assertNotified()
            ->assertRedirect(BorrowingResource::getUrl('index'));

        $this->assertModelMissing($borrowing);
        $this->assertSame('Disponible', $equipment->fresh()->status);
    }

    /**
     * Un usuario sin Delete_Borrowing no debe ver la acción de eliminación en la página de edición.
     */
    public function test_a_user_without_delete_permission_cannot_see_delete_action_on_the_edit_page(): void
    {
        $borrowing = Borrowing::factory()->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->assertActionHidden(DeleteAction::class);

        $this->assertModelExists($borrowing);
    }

    /**
     * Un usuario con DeleteAny_Borrowing y Delete_Borrowing puede eliminar masivamente
     * solo los préstamos seleccionados y liberar a Disponible los equipos sin otros préstamos activos.
     */
    public function test_a_user_with_delete_any_and_delete_permissions_can_bulk_delete_borrowings(): void
    {
        $firstEquipment = Equipment::factory()->withStatus('Prestado')->create();
        $secondEquipment = Equipment::factory()->withStatus('Prestado')->create();
        $keptEquipment = Equipment::factory()->withStatus('Prestado')->create();

        $firstToDelete = Borrowing::factory()->for($firstEquipment)->create(['status' => 'En curso']);
        $secondToDelete = Borrowing::factory()->for($secondEquipment)->create(['status' => 'En curso']);
        $toKeep = Borrowing::factory()->for($keptEquipment)->create(['status' => 'En curso']);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Delete_Borrowing', 'DeleteAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->selectTableRecords([$firstToDelete, $secondToDelete])
            ->callAction(TestAction::make(DeleteBulkAction::class)->table()->bulk())
            ->assertNotified();

        $this->assertModelMissing($firstToDelete);
        $this->assertModelMissing($secondToDelete);
        $this->assertSame('Disponible', $firstEquipment->fresh()->status);
        $this->assertSame('Disponible', $secondEquipment->fresh()->status);

        $this->assertModelExists($toKeep);
        $this->assertSame('Prestado', $keptEquipment->fresh()->status);
    }

    /**
     * Un usuario sin DeleteAny_Borrowing no puede ver el borrado masivo aunque tenga Delete_Borrowing.
     */
    public function test_a_user_without_delete_any_permission_cannot_bulk_delete_borrowings(): void
    {
        $records = Borrowing::factory()->count(2)->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Delete_Borrowing');
        $bulkDeleteAction = TestAction::make(DeleteBulkAction::class)->table()->bulk();

        Livewire::test(ListBorrowings::class)
            ->selectTableRecords($records)
            ->assertActionHidden($bulkDeleteAction);

        foreach ($records as $borrowing) {
            $this->assertModelExists($borrowing);
        }
    }

    /**
     * Al crear un préstamo con campos obligatorios vacíos, deben rechazarse con sus mensajes
     * personalizados sin crear registros ni enviar notificaciones.
     */
    public function test_creating_a_borrowing_rejects_empty_required_fields(): void
    {
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => '',
                'equipment_id' => null,
                'due_date' => null,
            ])
            ->call('create')
            ->assertHasFormErrors([
                'student_dni' => 'El DNI del estudiante es obligatorio.',
                'equipment_id' => 'Debe seleccionar un equipo para registrar el préstamo.',
                'due_date' => 'required',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('borrowings', 0);
    }

    /**
     * El formulario debe rechazar DNIs con formato inválido o que no correspondan a ningún
     * perfil registrado en el sistema.
     */
    public function test_creating_a_borrowing_rejects_malformed_or_unregistered_student_dni(): void
    {
        $equipment = Equipment::factory()->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => '1234567A',
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-24 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'student_dni' => 'El DNI solo debe contener números.',
            ])
            ->assertNotNotified();

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => '79999999',
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-24 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'student_dni' => 'El DNI ingresado no se encuentra registrado en el sistema. Debe registrar previamente el perfil del estudiante.',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame('Disponible', $equipment->fresh()->status);
    }

    /**
     * @return array<string, array{status: string}>
     */
    public static function unavailableEquipmentStatusesProvider(): array
    {
        return [
            'equipo prestado' => ['status' => 'Prestado'],
            'equipo en mantenimiento' => ['status' => 'Mantenimiento'],
            'equipo de baja' => ['status' => 'Baja'],
        ];
    }

    /**
     * No se debe permitir registrar un préstamo sobre equipos cuyo estado operativo
     * no sea Disponible, conservando el estado del equipo sin cambios.
     */
    #[DataProvider('unavailableEquipmentStatusesProvider')]
    public function test_creating_a_borrowing_rejects_equipment_that_is_not_disponible(string $status): void
    {
        $profile = Profile::factory()->create(['dni' => '75556666']);
        $equipment = Equipment::factory()->withStatus($status)->create([
            'brand' => 'Dell',
            'model' => 'Latitude 5430',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        $component = Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-24 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['equipment_id'])
            ->assertNotNotified();

        $this->assertSame(
            ["El recurso 'Dell Latitude 5430' no se encuentra disponible (Estado actual: {$status}). No se puede registrar el préstamo."],
            $component->errors()->get('data.equipment_id'),
        );

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame($status, $equipment->fresh()->status);
    }

    /**
     * Las observaciones del préstamo no pueden superar los 500 caracteres.
     */
    public function test_creating_a_borrowing_rejects_observations_exceeding_500_characters(): void
    {
        $profile = Profile::factory()->create(['dni' => '76667777']);
        $equipment = Equipment::factory()->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-24 10:00:00',
                'observations' => str_repeat('X', 501),
            ])
            ->call('create')
            ->assertHasFormErrors(['observations'])
            ->assertNotNotified();

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame('Disponible', $equipment->fresh()->status);
    }

    /**
     * Eliminar el perfil o el usuario registrador desvincula las llaves foráneas anulables
     * conservando el préstamo y el DNI, mientras que eliminar el equipo borra en cascada.
     */
    public function test_deleting_profile_or_registering_user_unlinks_borrowing_while_deleting_equipment_cascades(): void
    {
        $profile = Profile::factory()->create(['dni' => '78889999']);
        $equipment = Equipment::factory()->create();
        $registrar = User::factory()->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->for($registrar, 'registeredBy')
            ->create();

        $this->assertTrue($borrowing->equipment->is($equipment));
        $this->assertTrue($borrowing->profile->is($profile));
        $this->assertTrue($borrowing->registeredBy->is($registrar));

        $profile->delete();
        $registrar->delete();
        $borrowing->refresh();

        $this->assertModelExists($borrowing);
        $this->assertNull($borrowing->profile_id);
        $this->assertNull($borrowing->registered_by);
        $this->assertSame('78889999', $borrowing->student_dni);

        $equipment->delete();

        $this->assertModelMissing($borrowing);
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
