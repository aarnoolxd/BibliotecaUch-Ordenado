<?php

namespace Tests\Feature\Filament\Resources\Borrowings;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Filament\Resources\Borrowings\Pages\CreateBorrowing;
use App\Filament\Resources\Borrowings\Pages\EditBorrowing;
use App\Filament\Resources\Borrowings\Pages\ListBorrowings;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use App\Services\BorrowingReturnService;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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
     * préstamos con equipo principal, préstamos de solo periféricos y texto de respaldo sin perfil.
     */
    public function test_a_user_with_list_permission_can_read_the_borrowings_table(): void
    {
        $profile = Profile::factory()->create([
            'dni' => '71234567',
            'first_name' => 'Valeria',
            'last_name' => 'Mendoza',
            'maternal_last_name' => 'Cruz',
        ]);
        $equipment = Equipment::factory()->ofType('Laptop')->create([
            'patrimonial_code' => 'UCH-EQ-01001',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
            'status' => 'Prestado',
        ]);
        $mouse = Equipment::factory()->ofType('Periférico')->create([
            'patrimonial_code' => 'UCH-PER-001',
            'brand' => 'Logitech',
            'model' => 'M185',
            'status' => 'Prestado',
        ]);
        $linkedBorrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $peripheralsOnlyBorrowing = Borrowing::factory()
            ->withoutPrimaryEquipment()
            ->withAdditionalEquipment($mouse)
            ->create([
                'profile_id' => null,
                'student_dni' => '79998888',
                'status' => Borrowing::STATUS_RETURNED,
                'return_date' => now(),
                'observations' => 'Solo accesorios.',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing');

        $this->get(BorrowingResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($linkedBorrowing->student_dni)
            ->assertSeeText($equipment->patrimonial_code)
            ->assertSeeText($equipment->model)
            ->assertSeeText('M185')
            ->assertSeeText('Sin límite')
            ->assertSeeText('En curso')
            ->assertSeeText($peripheralsOnlyBorrowing->student_dni)
            ->assertSeeText('Sin equipo principal');

        Livewire::test(ListBorrowings::class)
            ->assertCanNotRenderTableColumn('profile.full_name')
            ->assertCanNotRenderTableColumn('return_date')
            ->assertCanNotRenderTableColumn('observations')
            ->toggleAllTableColumns()
            ->assertCanRenderTableColumn('profile.full_name')
            ->assertCanRenderTableColumn('return_date')
            ->assertCanRenderTableColumn('observations')
            ->assertSeeText('Valeria Mendoza Cruz')
            ->assertSeeText('Estudiante no vinculado')
            ->assertSeeText('Solo accesorios.');
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
     * directamente las páginas de creación o edición de préstamos.
     */
    public function test_a_user_without_create_or_update_permission_is_forbidden_from_those_pages(): void
    {
        $borrowing = Borrowing::factory()->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $this->signInWithPermissions('ViewAny_Borrowing');

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
     * únicamente cuando el usuario cuenta con dicho permiso (para update requiere estado En curso).
     */
    #[DataProvider('policyPermissionsProvider')]
    public function test_borrowing_policy_authorizes_each_ability_based_on_its_matching_permission(
        string $ability,
        string $permission,
        bool $requiresRecord,
    ): void {
        $borrowing = Borrowing::factory()->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $subject = $requiresRecord ? $borrowing : Borrowing::class;
        $unauthorizedUser = $this->signInWithPermissions();

        $this->assertTrue(Gate::forUser($unauthorizedUser)->denies($ability, $subject));

        $authorizedUser = $this->signInWithPermissions($permission);

        $this->assertTrue(Gate::forUser($authorizedUser)->allows($ability, $subject));
    }

    /**
     * La tabla de préstamos permite filtrar por estado y por incidencias, y buscar por dispositivos adicionales.
     */
    public function test_borrowings_table_can_filter_by_status_and_incidents_and_search_additional_equipment(): void
    {
        $mouse = Equipment::factory()->ofType('Periférico')->create([
            'patrimonial_code' => 'UCH-PER-777',
            'model' => 'Mouse Ergonómico',
        ]);
        $inProgress = Borrowing::factory()
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $returnedWithIncident = Borrowing::factory()->returned()->create();
        EquipmentIncident::factory()->for($returnedWithIncident)->create();
        $overdue = Borrowing::factory()->overdue()->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->filterTable('status', Borrowing::STATUS_OVERDUE)
            ->assertCanSeeTableRecords([$overdue])
            ->assertCanNotSeeTableRecords([$inProgress, $returnedWithIncident])
            ->filterTable('status', Borrowing::STATUS_RETURNED)
            ->assertCanSeeTableRecords([$returnedWithIncident])
            ->assertCanNotSeeTableRecords([$inProgress, $overdue])
            ->removeTableFilters()
            ->filterTable('has_incidents', true)
            ->assertCanSeeTableRecords([$returnedWithIncident])
            ->assertCanNotSeeTableRecords([$inProgress, $overdue])
            ->filterTable('has_incidents', false)
            ->assertCanSeeTableRecords([$inProgress, $overdue])
            ->assertCanNotSeeTableRecords([$returnedWithIncident])
            ->removeTableFilters()
            ->searchTable('UCH-PER-777')
            ->assertCanSeeTableRecords([$inProgress])
            ->assertCanNotSeeTableRecords([$returnedWithIncident, $overdue]);
    }

    /**
     * La tabla de préstamos permite ordenar por DNI del estudiante y fecha límite de devolución.
     */
    public function test_borrowings_table_can_sort_by_student_dni_and_due_date(): void
    {
        $firstProfile = Profile::factory()->create(['dni' => '70000010']);
        $secondProfile = Profile::factory()->create(['dni' => '70000020']);
        $thirdProfile = Profile::factory()->create(['dni' => '70000030']);

        $first = Borrowing::factory()->for($firstProfile)->create([
            'due_date' => now()->addHours(6),
        ]);
        $second = Borrowing::factory()->for($secondProfile)->create([
            'due_date' => now()->addHours(2),
        ]);
        $third = Borrowing::factory()->for($thirdProfile)->create([
            'due_date' => now()->addHours(4),
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
     * usando las columnas reales first_name, last_name y maternal_last_name de profiles.
     */
    public function test_borrowings_table_can_search_and_sort_by_student_full_name(): void
    {
        $anaProfile = Profile::factory()->create([
            'dni' => '70100001',
            'first_name' => 'Ana',
            'last_name' => 'Zapata',
            'maternal_last_name' => 'Paredes',
        ]);
        $brunoProfile = Profile::factory()->create([
            'dni' => '70100002',
            'first_name' => 'Bruno',
            'last_name' => 'Alvarez',
            'maternal_last_name' => 'Mendoza',
        ]);
        $anaBorrowing = Borrowing::factory()->for($anaProfile)->create();
        $brunoBorrowing = Borrowing::factory()->for($brunoProfile)->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        $table = Livewire::test(ListBorrowings::class);

        foreach (['Ana', 'Zapata', 'Paredes', 'ana zapata', 'zapata paredes'] as $term) {
            $table
                ->searchTable($term)
                ->assertCanSeeTableRecords([$anaBorrowing])
                ->assertCanNotSeeTableRecords([$brunoBorrowing]);
        }

        Livewire::test(ListBorrowings::class)
            ->toggleAllTableColumns()
            ->sortTable('profile.full_name')
            ->assertCanSeeTableRecords([$anaBorrowing, $brunoBorrowing], inOrder: true)
            ->sortTable('profile.full_name', 'desc')
            ->assertCanSeeTableRecords([$brunoBorrowing, $anaBorrowing], inOrder: true);
    }

    /**
     * Al ingresar un DNI registrado en el formulario, deben autocompletarse profile_id
     * y student_name; al cambiarlo por un DNI inexistente o vaciarlo, ambos campos se limpian.
     */
    public function test_entering_a_registered_student_dni_populates_profile_and_name_and_clearing_resets_them(): void
    {
        $profile = Profile::factory()->create([
            'dni' => '72223333',
            'first_name' => 'Lucia',
            'last_name' => 'Rojas',
            'maternal_last_name' => 'Paredes',
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
     * Solo una Computadora calcula due_date (+2 horas); al elegir Laptop o quitar el equipo
     * principal, due_date queda en null (sin límite de tiempo).
     */
    public function test_due_date_applies_two_hours_only_for_computadora_and_clears_for_laptop(): void
    {
        $this->travelTo('2026-11-23 10:00:00');
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->set('data.borrowing_date', '2026-11-23 10:00:00')
            ->set('data.equipment_id', $computer->getKey())
            ->assertSet('data.due_date', '2026-11-23 12:00:00')
            ->set('data.borrowing_date', '2026-11-24 08:30:00')
            ->assertSet('data.due_date', '2026-11-24 10:30:00')
            ->set('data.equipment_id', $laptop->getKey())
            ->assertSet('data.due_date', null)
            ->set('data.equipment_id', null)
            ->assertSet('data.due_date', null);
    }

    /**
     * Un usuario con Create_Borrowing puede registrar un préstamo de Computadora junto con
     * múltiples dispositivos adicionales (Periférico y Otro): due_date se fija en +2 horas
     * y todos los recursos pasan a estado Prestado.
     */
    public function test_creating_a_computer_borrowing_with_multiple_additional_devices_marks_all_as_prestado(): void
    {
        $this->travelTo('2026-11-23 09:00:00');
        $profile = Profile::factory()->create(['dni' => '73334444']);
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create();
        $adapter = Equipment::factory()->ofType('Otro')->withStatus('Disponible')->create();
        $actor = $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => '73334444',
                'equipment_id' => $computer->getKey(),
                'additional_equipment_ids' => [$mouse->getKey(), $adapter->getKey()],
                'borrowing_date' => '2026-11-23 09:00:00',
                'due_date' => '2026-11-25 09:00:00',
                'observations' => 'PC con mouse y adaptador HDMI.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Préstamo registrado exitosamente en estado "En curso"')
            ->assertRedirect(BorrowingResource::getUrl('index'));

        $borrowing = Borrowing::query()->with('additionalEquipment')->where('student_dni', '73334444')->firstOrFail();

        $this->assertSame($computer->getKey(), $borrowing->equipment_id);
        $this->assertSame($profile->getKey(), $borrowing->profile_id);
        $this->assertSame($actor->getKey(), $borrowing->registered_by);
        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $borrowing->status);
        $this->assertSame('2026-11-23 09:00:00', $borrowing->borrowing_date->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-23 11:00:00', $borrowing->due_date->format('Y-m-d H:i:s'));
        $this->assertNull($borrowing->return_date);
        $this->assertEqualsCanonicalizing(
            [$mouse->getKey(), $adapter->getKey()],
            $borrowing->additionalEquipment->pluck('id')->all(),
        );
        $this->assertSame('Prestado', $computer->fresh()->status);
        $this->assertSame('Prestado', $mouse->fresh()->status);
        $this->assertSame('Prestado', $adapter->fresh()->status);
    }

    /**
     * Un préstamo de Laptop no asigna fecha límite de devolución (due_date = null).
     */
    public function test_creating_a_laptop_borrowing_leaves_due_date_null(): void
    {
        $this->travelTo('2026-11-23 09:00:00');
        $profile = Profile::factory()->create(['dni' => '73334445']);
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $laptop->getKey(),
                'borrowing_date' => '2026-11-23 09:00:00',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertRedirect(BorrowingResource::getUrl('index'));

        $borrowing = Borrowing::query()->where('student_dni', $profile->dni)->firstOrFail();

        $this->assertSame($laptop->getKey(), $borrowing->equipment_id);
        $this->assertNull($borrowing->due_date);
        $this->assertSame('Prestado', $laptop->fresh()->status);
    }

    /**
     * Se puede registrar un préstamo únicamente con periféricos u otros dispositivos sin
     * seleccionar equipo principal: equipment_id y due_date quedan en null y todos pasan a Prestado.
     */
    public function test_creating_a_borrowing_with_only_additional_devices_and_no_primary_equipment(): void
    {
        $profile = Profile::factory()->create(['dni' => '73334446']);
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create();
        $charger = Equipment::factory()->ofType('Otro')->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => null,
                'additional_equipment_ids' => [$mouse->getKey(), $charger->getKey()],
                'borrowing_date' => '2026-11-23 11:00:00',
                'observations' => 'Estudiante usa su propia laptop.',
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Préstamo registrado exitosamente en estado "En curso"');

        $borrowing = Borrowing::query()->with('additionalEquipment')->where('student_dni', $profile->dni)->firstOrFail();

        $this->assertNull($borrowing->equipment_id);
        $this->assertNull($borrowing->due_date);
        $this->assertEqualsCanonicalizing(
            [$mouse->getKey(), $charger->getKey()],
            $borrowing->additionalEquipment->pluck('id')->all(),
        );
        $this->assertSame('Prestado', $mouse->fresh()->status);
        $this->assertSame('Prestado', $charger->fresh()->status);
    }

    /**
     * El formulario rechaza asignar un Periférico/Otro como equipo principal o una
     * Computadora/Laptop como dispositivo adicional, así como dispositivos no disponibles.
     */
    public function test_creating_a_borrowing_enforces_equipment_category_separation_and_additional_availability(): void
    {
        $profile = Profile::factory()->create(['dni' => '73334447']);
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $peripheral = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create();
        $unavailablePeripheral = Equipment::factory()->ofType('Periférico')->withStatus('Mantenimiento')->create([
            'brand' => 'Logitech',
            'model' => 'K120',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $peripheral->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['equipment_id'])
            ->assertNotNotified();

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => null,
                'additional_equipment_ids' => [$laptop->getKey()],
                'borrowing_date' => '2026-11-23 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['additional_equipment_ids'])
            ->assertNotNotified();

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $laptop->getKey(),
                'additional_equipment_ids' => [$unavailablePeripheral->getKey()],
                'borrowing_date' => '2026-11-23 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['additional_equipment_ids'])
            ->assertNotNotified();

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame('Disponible', $laptop->fresh()->status);
        $this->assertSame('Disponible', $peripheral->fresh()->status);
        $this->assertSame('Mantenimiento', $unavailablePeripheral->fresh()->status);
    }

    /**
     * Si el equipo cambia a Mantenimiento antes de completar el alta, CreateBorrowing
     * debe rechazar el guardado asociando el error al campo data.equipment_id.
     */
    public function test_create_borrowing_hook_attaches_validation_error_to_form_field_when_equipment_becomes_unavailable(): void
    {
        $profile = Profile::factory()->create(['dni' => '73339999']);
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create([
            'brand' => 'HP',
            'model' => 'ProDesk 400',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        $component = Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 09:00:00',
                'due_date' => '2026-11-23 11:00:00',
            ]);

        $equipment->update(['status' => 'Mantenimiento']);

        $component
            ->call('create')
            ->assertHasFormErrors(['equipment_id']);

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame('Mantenimiento', $equipment->fresh()->status);
    }

    /**
     * Un usuario con Update_Borrowing puede editar un préstamo En curso conservando su
     * propio equipo Prestado y actualizar sus observaciones y fecha límite.
     */
    public function test_a_user_with_update_permission_can_edit_an_in_progress_borrowing_keeping_its_equipment(): void
    {
        $profile = Profile::factory()->create(['dni' => '74445555']);
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->create([
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-20 11:00:00',
                'observations' => 'Observación inicial.',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => '74445555',
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-20 12:00:00',
                'observations' => 'Observación actualizada.',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $borrowing->refresh();

        $this->assertSame('2026-11-20 12:00:00', $borrowing->due_date->format('Y-m-d H:i:s'));
        $this->assertSame('Observación actualizada.', $borrowing->observations);
        $this->assertSame('Prestado', $equipment->fresh()->status);
    }

    /**
     * Al editar un préstamo En curso se pueden agregar, reemplazar y quitar dispositivos adicionales
     * o pasar de Computadora a solo periféricos, sincronizando estados y due_date.
     */
    public function test_editing_an_in_progress_borrowing_syncs_additional_equipment_and_clears_due_date_when_removing_computer(): void
    {
        $profile = Profile::factory()->create(['dni' => '74445556']);
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $mouseA = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $mouseB = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create();
        $cable = Equipment::factory()->ofType('Otro')->withStatus('Disponible')->create();
        $borrowing = Borrowing::factory()
            ->for($computer)
            ->for($profile)
            ->withAdditionalEquipment($mouseA)
            ->create([
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-20 11:00:00',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => null,
                'additional_equipment_ids' => [$mouseB->getKey(), $cable->getKey()],
                'borrowing_date' => '2026-11-20 09:00:00',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $borrowing->refresh()->load('additionalEquipment');

        $this->assertNull($borrowing->equipment_id);
        $this->assertNull($borrowing->due_date);
        $this->assertEqualsCanonicalizing(
            [$mouseB->getKey(), $cable->getKey()],
            $borrowing->additionalEquipment->pluck('id')->all(),
        );
        $this->assertSame('Disponible', $computer->fresh()->status);
        $this->assertSame('Disponible', $mouseA->fresh()->status);
        $this->assertSame('Prestado', $mouseB->fresh()->status);
        $this->assertSame('Prestado', $cable->fresh()->status);
    }

    /**
     * Solo los préstamos En curso pueden editarse, mientras que la devolución está permitida
     * tanto en En curso como en Vencido (y bloqueada en Devuelto).
     */
    public function test_edit_is_limited_to_in_progress_while_return_allows_in_progress_and_overdue(): void
    {
        $inProgress = Borrowing::factory()->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $returned = Borrowing::factory()->returned()->create();
        $overdue = Borrowing::factory()->overdue()->create();

        $user = $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        $this->assertTrue(Gate::forUser($user)->allows('update', $inProgress));
        $this->assertTrue(Gate::forUser($user)->denies('update', $returned));
        $this->assertTrue(Gate::forUser($user)->denies('update', $overdue));

        $this->assertTrue(Gate::forUser($user)->allows('registerReturn', $inProgress));
        $this->assertTrue(Gate::forUser($user)->allows('registerReturn', $overdue));
        $this->assertTrue(Gate::forUser($user)->denies('registerReturn', $returned));

        $this->get(BorrowingResource::getUrl('edit', ['record' => $inProgress]))->assertOk();
        $this->get(BorrowingResource::getUrl('edit', ['record' => $returned]))->assertForbidden();
        $this->get(BorrowingResource::getUrl('edit', ['record' => $overdue]))->assertForbidden();

        Livewire::test(ListBorrowings::class)
            ->assertActionVisible(TestAction::make('registerReturn')->table($inProgress))
            ->assertActionVisible(TestAction::make(EditAction::class)->table($inProgress))
            ->assertActionVisible(TestAction::make('registerReturn')->table($overdue))
            ->assertActionHidden(TestAction::make(EditAction::class)->table($overdue))
            ->assertActionHidden(TestAction::make('registerReturn')->table($returned))
            ->assertActionHidden(TestAction::make(EditAction::class)->table($returned));

        $service = app(BorrowingReturnService::class);

        try {
            $service->registerReturn($returned, []);
            $this->fail('Se esperaba ValidationException al devolver un préstamo ya Devuelto.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('borrowing', $exception->errors());
        }

        Livewire::test(ListBorrowings::class)
            ->callAction(TestAction::make('registerReturn')->table($overdue))
            ->assertHasNoFormErrors()
            ->assertNotified('Devolución registrada');

        $this->assertSame(Borrowing::STATUS_RETURNED, $overdue->fresh()->status);
    }

    /**
     * Tanto el comando borrowings:mark-overdue como el ingreso a ListBorrowings marcan
     * automáticamente como Vencido los préstamos En curso cuya due_date ya expiró,
     * sin afectar préstamos vigentes, préstamos sin límite (Laptop/periféricos) ni Devueltos.
     */
    public function test_expired_in_progress_borrowings_are_automatically_marked_as_overdue(): void
    {
        $this->travelTo('2026-11-23 14:00:00');

        $expiredComputerBorrowing = Borrowing::factory()->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-23 10:00:00',
            'due_date' => '2026-11-23 12:00:00',
        ]);
        $activeComputerBorrowing = Borrowing::factory()->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-23 13:00:00',
            'due_date' => '2026-11-23 15:00:00',
        ]);
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $unlimitedLaptopBorrowing = Borrowing::factory()->for($laptop)->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'due_date' => null,
        ]);
        $alreadyReturned = Borrowing::factory()->returned()->create([
            'due_date' => '2026-11-23 11:00:00',
        ]);

        $this->artisan('borrowings:mark-overdue')
            ->assertSuccessful();

        $this->assertSame(Borrowing::STATUS_OVERDUE, $expiredComputerBorrowing->fresh()->status);
        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $activeComputerBorrowing->fresh()->status);
        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $unlimitedLaptopBorrowing->fresh()->status);
        $this->assertSame(Borrowing::STATUS_RETURNED, $alreadyReturned->fresh()->status);

        $secondExpired = Borrowing::factory()->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-23 09:00:00',
            'due_date' => '2026-11-23 11:00:00',
        ]);

        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->assertCanSeeTableRecords([$secondExpired]);

        $this->assertSame(Borrowing::STATUS_OVERDUE, $secondExpired->fresh()->status);
    }

    /**
     * Al cambiar student_dni al editar un préstamo En curso, EditBorrowing debe actualizar
     * también profile_id al perfil correspondiente aunque no se dispare el evento blur.
     */
    public function test_editing_a_borrowing_updates_profile_id_when_student_dni_changes(): void
    {
        $originalProfile = Profile::factory()->create(['dni' => '74447701']);
        $newProfile = Profile::factory()->create(['dni' => '74447702']);
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($originalProfile)
            ->create([
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-20 11:00:00',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->fillForm([
                'student_dni' => $newProfile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-20 11:00:00',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $borrowing->refresh();

        $this->assertSame($newProfile->dni, $borrowing->student_dni);
        $this->assertSame($newProfile->getKey(), $borrowing->profile_id);
    }

    /**
     * Al editar un préstamo de Computadora, due_date no puede ser anterior a borrowing_date.
     */
    public function test_editing_a_borrowing_rejects_due_date_earlier_than_borrowing_date(): void
    {
        $profile = Profile::factory()->create(['dni' => '74448801']);
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->create([
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'borrowing_date' => '2026-11-20 09:00:00',
                'due_date' => '2026-11-20 11:00:00',
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

        $this->assertSame('2026-11-20 11:00:00', $borrowing->fresh()->due_date->format('Y-m-d H:i:s'));
    }

    /**
     * Al reasignar el equipo de un préstamo En curso de A a B, el equipo B pasa a Prestado
     * y el equipo A regresa a Disponible si no posee otro préstamo activo.
     */
    public function test_changing_equipment_on_an_in_progress_borrowing_syncs_both_equipment_statuses(): void
    {
        $profile = Profile::factory()->create(['dni' => '74446666']);
        $previousEquipment = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $newEquipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $borrowing = Borrowing::factory()
            ->for($previousEquipment)
            ->for($profile)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
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
        $equipmentA = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $equipmentB = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $equipmentC = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $borrowing = Borrowing::factory()->for($equipmentA)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

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
     * Un usuario con Update_Borrowing puede registrar la devolución sin daños de préstamos
     * En curso (con equipo principal + periférico o solo periféricos): no se crean incidencias,
     * se registra return_date y todos los recursos vuelven a Disponible.
     */
    public function test_registering_a_return_without_damage_releases_all_devices_and_creates_no_incidents(): void
    {
        $this->travelTo('2026-11-23 15:00:00');
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $combinedBorrowing = Borrowing::factory()
            ->for($computer)
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        $cable = Equipment::factory()->ofType('Otro')->withStatus('Prestado')->create();
        $peripheralsOnlyBorrowing = Borrowing::factory()
            ->withoutPrimaryEquipment()
            ->withAdditionalEquipment($cable)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->callAction(TestAction::make('registerReturn')->table($combinedBorrowing))
            ->assertHasNoFormErrors()
            ->assertNotified('Devolución registrada');

        $combinedBorrowing->refresh();

        $this->assertSame(Borrowing::STATUS_RETURNED, $combinedBorrowing->status);
        $this->assertSame('2026-11-23 15:00:00', $combinedBorrowing->return_date->format('Y-m-d H:i:s'));
        $this->assertSame('Disponible', $computer->fresh()->status);
        $this->assertSame('Disponible', $mouse->fresh()->status);
        $this->assertDatabaseCount('equipment_incidents', 0);

        Livewire::test(ListBorrowings::class)
            ->callAction(TestAction::make('registerReturn')->table($peripheralsOnlyBorrowing))
            ->assertHasNoFormErrors()
            ->assertNotified('Devolución registrada');

        $this->assertSame(Borrowing::STATUS_RETURNED, $peripheralsOnlyBorrowing->fresh()->status);
        $this->assertSame('Disponible', $cable->fresh()->status);
        $this->assertDatabaseCount('equipment_incidents', 0);
    }

    /**
     * Al registrar una devolución con daño parcial (solo el periférico dañado), se crea
     * una única incidencia con su fotografía almacenada en el disco privado, el periférico
     * pasa a Mantenimiento, el equipo sin daños vuelve a Disponible y observations no se altera.
     */
    public function test_registering_a_return_with_partial_damage_creates_incident_with_private_photo_and_sets_only_damaged_to_maintenance(): void
    {
        $disk = EquipmentIncident::photoDisk();
        Storage::fake($disk);
        Storage::fake('public');

        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create([
            'patrimonial_code' => 'UCH-LAP-100',
        ]);
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create([
            'patrimonial_code' => 'UCH-PER-200',
        ]);
        $borrowing = Borrowing::factory()
            ->for($laptop)
            ->withAdditionalEquipment($mouse)
            ->create([
                'status' => Borrowing::STATUS_IN_PROGRESS,
                'observations' => 'Entregado completo.',
            ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        $damagePhoto = UploadedFile::fake()->image('mouse-roto.jpg', 400, 300);

        $mountedReturn = Livewire::test(ListBorrowings::class)
            ->mountAction(TestAction::make('registerReturn')->table($borrowing));

        $livewireInstance = $mountedReturn->instance();
        $mountedSchema = $livewireInstance->getSchema($livewireInstance->getMountedActionSchemaName());
        $photoComponent = collect($mountedSchema->getFlatComponents(withHidden: true))
            ->first(fn (mixed $component): bool => $component instanceof FileUpload);

        $this->assertInstanceOf(FileUpload::class, $photoComponent);
        $this->assertSame($disk, $photoComponent->getDiskName());
        $this->assertSame('private', $photoComponent->getVisibility());

        $mountedReturn
            ->fillForm([
                'items' => [
                    [
                        'equipment_id' => $laptop->getKey(),
                        'has_damage' => false,
                    ],
                    [
                        'equipment_id' => $mouse->getKey(),
                        'has_damage' => true,
                        'description' => 'Botón izquierdo hundido y cable pelado.',
                        'photo' => [$damagePhoto],
                    ],
                ],
            ])
            ->callMountedAction()
            ->assertHasNoFormErrors()
            ->assertNotified('Devolución registrada');

        $borrowing->refresh();

        $this->assertSame(Borrowing::STATUS_RETURNED, $borrowing->status);
        $this->assertNotNull($borrowing->return_date);
        $this->assertSame('Entregado completo.', $borrowing->observations);
        $this->assertSame('Disponible', $laptop->fresh()->status);
        $this->assertSame('Mantenimiento', $mouse->fresh()->status);

        $this->assertDatabaseCount('equipment_incidents', 1);
        $incident = EquipmentIncident::query()->firstOrFail();

        $this->assertSame($mouse->getKey(), $incident->equipment_id);
        $this->assertSame($borrowing->getKey(), $incident->borrowing_id);
        $this->assertSame('Botón izquierdo hundido y cable pelado.', $incident->description);
        $this->assertNotNull($incident->photo);
        $this->assertStringStartsWith('borrowings/incidents/', $incident->photo);
        Storage::disk($disk)->assertExists($incident->photo);
        Storage::disk('public')->assertMissing($incident->photo);
    }

    /**
     * Cuando se marca has_damage en un dispositivo durante la devolución, el detalle del daño
     * y la fotografía JPG/PNG (máx. 4 MB) son obligatorios; si faltan o son inválidos no se
     * procesa la devolución ni se modifican estados.
     */
    public function test_registering_a_return_with_damage_requires_description_and_valid_photo(): void
    {
        $disk = config('filament.default_filesystem_disk');
        Storage::fake($disk);

        $equipment = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()->for($equipment)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->callAction(TestAction::make('registerReturn')->table($borrowing), [
                'items' => [
                    [
                        'equipment_id' => $equipment->getKey(),
                        'has_damage' => true,
                        'description' => '',
                        'photo' => null,
                    ],
                ],
            ])
            ->assertHasFormErrors([
                'items.0.description' => 'Debe describir el daño detectado en el recurso.',
                'items.0.photo' => 'Debe adjuntar una fotografía de evidencia del daño.',
            ])
            ->assertNotNotified();

        Livewire::test(ListBorrowings::class)
            ->callAction(TestAction::make('registerReturn')->table($borrowing), [
                'items' => [
                    [
                        'equipment_id' => $equipment->getKey(),
                        'has_damage' => true,
                        'description' => 'Pantalla fisurada.',
                        'photo' => [UploadedFile::fake()->image('grande.jpg')->size(4097)],
                    ],
                ],
            ])
            ->assertHasFormErrors(['items.0.photo'])
            ->assertNotNotified();

        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $borrowing->fresh()->status);
        $this->assertSame('Prestado', $equipment->fresh()->status);
        $this->assertDatabaseCount('equipment_incidents', 0);
    }

    /**
     * La acción Registrar Devolución permanece oculta para usuarios sin Update_Borrowing.
     */
    public function test_register_return_action_is_hidden_without_update_permission(): void
    {
        $activeBorrowing = Borrowing::factory()->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->assertActionHidden(TestAction::make('registerReturn')->table($activeBorrowing));
    }

    /**
     * La página de edición de préstamos no expone la acción de eliminación en la cabecera,
     * incluso cuando el usuario cuenta con Delete_Borrowing.
     */
    public function test_edit_borrowing_page_does_not_expose_delete_action(): void
    {
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()->for($equipment)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing', 'Delete_Borrowing');

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->assertActionDoesNotExist(DeleteAction::class);

        $this->assertModelExists($borrowing);
        $this->assertSame('Prestado', $equipment->fresh()->status);
    }

    /**
     * Eliminar un préstamo activo a nivel de modelo libera tanto el equipo principal como
     * todos sus dispositivos adicionales sin otros préstamos activos.
     */
    public function test_deleting_an_active_borrowing_model_releases_primary_and_additional_equipment(): void
    {
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $activeBorrowing = Borrowing::factory()
            ->for($laptop)
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        $activeBorrowing->delete();

        $this->assertModelMissing($activeBorrowing);
        $this->assertSame('Disponible', $laptop->fresh()->status);
        $this->assertSame('Disponible', $mouse->fresh()->status);
    }

    /**
     * Al crear un préstamo sin DNI ni ningún recurso (ni principal ni adicional),
     * deben mostrarse los mensajes de validación correspondientes.
     */
    public function test_creating_a_borrowing_rejects_empty_required_fields(): void
    {
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => '',
                'equipment_id' => null,
                'additional_equipment_ids' => [],
            ])
            ->call('create')
            ->assertHasFormErrors([
                'student_dni' => 'El DNI del estudiante es obligatorio.',
                'equipment_id' => 'Debe seleccionar al menos un equipo principal o un dispositivo adicional para registrar el préstamo.',
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
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => '1234567A',
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-23 12:00:00',
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
                'due_date' => '2026-11-23 12:00:00',
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
        $equipment = Equipment::factory()->ofType('Laptop')->withStatus($status)->create([
            'brand' => 'Dell',
            'model' => 'Latitude 5430',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
            ])
            ->call('create')
            ->assertHasFormErrors(['equipment_id'])
            ->assertNotNotified();

        $this->assertDatabaseCount('borrowings', 0);
        $this->assertSame($status, $equipment->fresh()->status);
    }

    /**
     * Las observaciones del préstamo no pueden superar los 500 caracteres.
     */
    public function test_creating_a_borrowing_rejects_observations_exceeding_500_characters(): void
    {
        $profile = Profile::factory()->create(['dni' => '76667777']);
        $equipment = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->fillForm([
                'student_dni' => $profile->dni,
                'equipment_id' => $equipment->getKey(),
                'borrowing_date' => '2026-11-23 10:00:00',
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
     * conservando el préstamo y el DNI, mientras que eliminar un equipo con préstamos históricos
     * (Devuelto) borra esos registros en cascada.
     */
    public function test_deleting_profile_or_registering_user_unlinks_borrowing_while_deleting_equipment_cascades(): void
    {
        $profile = Profile::factory()->create(['dni' => '78889999']);
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();
        $registrar = User::factory()->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->for($registrar, 'registeredBy')
            ->returned()
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
     * Un usuario sin ViewAny_Borrowing no debe disparar la actualización automática de préstamos
     * vencidos al intentar acceder a ListBorrowings.
     */
    public function test_unauthorized_user_visiting_list_borrowings_does_not_trigger_overdue_updates(): void
    {
        $this->travelTo('2026-11-23 14:00:00');
        $expiredBorrowing = Borrowing::factory()->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-23 10:00:00',
            'due_date' => '2026-11-23 12:00:00',
        ]);
        $this->signInWithPermissions();

        $this->get(BorrowingResource::getUrl('index'))->assertForbidden();

        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $expiredBorrowing->fresh()->status);
    }

    /**
     * El selector de dispositivos adicionales muestra el código patrimonial junto con marca y modelo
     * para distinguir unidades idénticas.
     */
    public function test_additional_equipment_selector_includes_patrimonial_code_to_distinguish_identical_models(): void
    {
        $first = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-PER-101',
            'brand' => 'Logitech',
            'model' => 'M185',
        ]);
        $second = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-PER-102',
            'brand' => 'Logitech',
            'model' => 'M185',
        ]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing');

        $component = Livewire::test(CreateBorrowing::class);
        $select = $component->instance()->form->getComponentByStatePath('additional_equipment_ids', withHidden: true);

        $this->assertInstanceOf(Select::class, $select);
        $options = $select->getOptions();

        $this->assertSame('[UCH-PER-101] Logitech M185', $options[$first->getKey()] ?? null);
        $this->assertSame('[UCH-PER-102] Logitech M185', $options[$second->getKey()] ?? null);
    }

    /**
     * EditBorrowing revalida el tipo del equipo principal bajo bloqueo: rechaza si cambió a
     * Periférico/Otro y recalcula due_date a null si cambió a Laptop.
     */
    public function test_edit_borrowing_revalidates_locked_equipment_type_and_recalculates_due_date(): void
    {
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $reclassifiedToPeripheral = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create();
        $reclassifiedToLaptop = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $borrowing = Borrowing::factory()->for($computer)->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-23 09:00:00',
            'due_date' => '2026-11-23 11:00:00',
        ]);

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

        try {
            $page->executeRecordUpdate($borrowing, [
                'equipment_id' => $reclassifiedToPeripheral->getKey(),
                'borrowing_date' => '2026-11-23 09:00:00',
                'due_date' => '2026-11-23 11:00:00',
            ]);
            $this->fail('Se esperaba ValidationException cuando el equipo principal bloqueado ya no es Computadora ni Laptop.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('data.equipment_id', $exception->errors());
        }

        $updated = $page->executeRecordUpdate($borrowing, [
            'equipment_id' => $reclassifiedToLaptop->getKey(),
            'borrowing_date' => '2026-11-23 09:00:00',
            'due_date' => '2026-11-23 11:00:00',
        ]);

        $this->assertSame($reclassifiedToLaptop->getKey(), $updated->equipment_id);
        $this->assertNull($updated->due_date);
        $this->assertSame('Disponible', $computer->fresh()->status);
        $this->assertSame('Prestado', $reclassifiedToLaptop->fresh()->status);
    }

    /**
     * EquipmentIncident::photoDisk nunca usa el disco público y la carga de fotos en devoluciones
     * impide manipular rutas de archivos existentes en el disco (anti-IDOR).
     */
    public function test_return_photo_upload_uses_private_non_public_disk_and_prevents_path_tampering(): void
    {
        config(['filament.default_filesystem_disk' => 'public']);
        $this->assertSame('local', EquipmentIncident::photoDisk());

        $disk = EquipmentIncident::photoDisk();
        Storage::fake($disk);
        Storage::disk($disk)->put(
            'profiles/photos/archivo-ajeno.jpg',
            UploadedFile::fake()->image('archivo-ajeno.jpg', 100, 100)->getContent(),
        );

        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()->for($laptop)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->callAction(TestAction::make('registerReturn')->table($borrowing), [
                'items' => [
                    [
                        'equipment_id' => $laptop->getKey(),
                        'has_damage' => true,
                        'description' => 'Intento de inyectar ruta ajena.',
                        'photo' => ['profiles/photos/archivo-ajeno.jpg'],
                    ],
                ],
            ])
            ->assertHasFormErrors(['items.0.photo']);

        $this->assertDatabaseCount('equipment_incidents', 0);
        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $borrowing->fresh()->status);
    }

    /**
     * BorrowingReturnService exige que todos los dispositivos del préstamo sean evaluados
     * y cuenta únicamente los equipos cuyo estado cambió efectivamente a Disponible.
     */
    public function test_return_service_requires_all_devices_and_counts_only_actually_released_equipment(): void
    {
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Mantenimiento')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($laptop)
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        $service = app(BorrowingReturnService::class);

        try {
            $service->registerReturn($borrowing, [
                ['equipment_id' => $laptop->getKey(), 'has_damage' => false],
            ]);
            $this->fail('Se esperaba ValidationException al omitir un dispositivo del préstamo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('borrowing', $exception->errors());
        }

        $result = $service->registerReturn($borrowing, [
            ['equipment_id' => $laptop->getKey(), 'has_damage' => false],
            ['equipment_id' => $mouse->getKey(), 'has_damage' => false],
        ]);

        $this->assertSame(1, $result['released_count']);
        $this->assertSame(0, $result['maintenance_count']);
        $this->assertSame('Mantenimiento', $laptop->fresh()->status);
        $this->assertSame('Disponible', $mouse->fresh()->status);
    }

    /**
     * Si otro operador modifica los dispositivos del préstamo mientras el modal de devolución
     * está abierto, la acción muestra un aviso visible y elimina cualquier foto huérfana cargada.
     */
    public function test_return_action_notifies_operator_and_cleans_up_orphaned_photo_when_devices_change_concurrently(): void
    {
        $disk = EquipmentIncident::photoDisk();
        Storage::fake($disk);

        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($laptop)
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $this->signInWithPermissions('ViewAny_Borrowing', 'Update_Borrowing');

        $action = TestAction::make('registerReturn')->table($borrowing);
        $component = Livewire::test(ListBorrowings::class)
            ->mountAction($action)
            ->fillForm([
                'items' => [
                    [
                        'equipment_id' => $laptop->getKey(),
                        'has_damage' => false,
                    ],
                    [
                        'equipment_id' => $mouse->getKey(),
                        'has_damage' => true,
                        'description' => 'Cable dañado.',
                        'photo' => [UploadedFile::fake()->image('evidencia-huerfana.jpg', 300, 200)],
                    ],
                ],
            ]);

        // Otra sesión quita el mouse del préstamo antes de confirmar el modal
        $borrowing->additionalEquipment()->detach($mouse->getKey());

        $component
            ->callMountedAction()
            ->assertNotified('No se pudo registrar la devolución');

        $this->assertSame(Borrowing::STATUS_IN_PROGRESS, $borrowing->fresh()->status);
        $this->assertDatabaseCount('equipment_incidents', 0);
        $this->assertSame([], Storage::disk($disk)->allFiles('borrowings/incidents'));
    }

    /**
     * EquipmentIncidentFactory asocia el incidente con un equipo que pertenece al préstamo generado.
     */
    public function test_equipment_incident_factory_associates_an_equipment_from_the_generated_borrowing(): void
    {
        $incident = EquipmentIncident::factory()->create();

        $this->assertContains($incident->equipment_id, $incident->borrowing->allEquipmentIds());
    }

    /**
     * No se permite eliminar directamente un equipo (ni principal ni adicional) mientras posea
     * un préstamo activo, ejecutando la verificación y el borrado dentro de una transacción con
     * bloqueo de fila incluso si la instancia en memoria tenía relaciones obsoletas.
     */
    public function test_deleting_equipment_with_active_borrowing_is_blocked_at_model_level(): void
    {
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create();
        $freeEquipment = Equipment::factory()->ofType('Otro')->withStatus('Disponible')->create();

        // Precarga relaciones vacías antes de una asignación concurrente
        $laptop->load(['borrowings', 'additionalBorrowings']);
        $mouse->load(['borrowings', 'additionalBorrowings']);

        $borrowing = Borrowing::factory()
            ->for($laptop)
            ->withAdditionalEquipment($mouse)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        $laptop->update(['status' => 'Prestado']);
        $mouse->update(['status' => 'Prestado']);

        $levelBeforeDelete = DB::transactionLevel();
        $levelDuringDeleting = null;

        Event::listen('eloquent.deleting: '.Equipment::class, function () use (&$levelDuringDeleting): void {
            $levelDuringDeleting = DB::transactionLevel();
        });

        $this->assertFalse($laptop->delete());
        $this->assertFalse($mouse->delete());
        $this->assertTrue($freeEquipment->delete());

        $this->assertSame($levelBeforeDelete + 1, $levelDuringDeleting);
        $this->assertModelExists($laptop);
        $this->assertModelExists($mouse);
        $this->assertModelExists($borrowing);
        $this->assertModelMissing($freeEquipment);
        $this->assertSame('Prestado', $mouse->fresh()->status);
    }

    /**
     * Equipment::hasActiveBorrowing considera préstamos activos tanto como equipo principal
     * como dispositivo adicional, e ignora préstamos ya devueltos.
     */
    public function test_equipment_has_active_borrowing_checks_primary_and_additional_roles(): void
    {
        $primary = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $additional = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $returnedDevice = Equipment::factory()->ofType('Otro')->withStatus('Disponible')->create();

        Borrowing::factory()
            ->for($primary)
            ->withAdditionalEquipment($additional)
            ->create(['status' => Borrowing::STATUS_IN_PROGRESS]);

        Borrowing::factory()
            ->withoutPrimaryEquipment()
            ->withAdditionalEquipment($returnedDevice)
            ->returned()
            ->create();

        $this->assertTrue($primary->hasActiveBorrowing());
        $this->assertTrue($additional->hasActiveBorrowing());
        $this->assertFalse($returnedDevice->hasActiveBorrowing());
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
