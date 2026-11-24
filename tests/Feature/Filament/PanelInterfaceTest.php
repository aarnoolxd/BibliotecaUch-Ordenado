<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\Equipment\Pages\CreateEquipment;
use App\Filament\Resources\Users\UserResource;
use App\Filament\Widgets\AfluenceDaysHoursChart;
use App\Filament\Widgets\BorrowingOverviewStats;
use App\Filament\Widgets\EquipmentStatusChart;
use App\Filament\Widgets\EquipmentTotalOverview;
use App\Filament\Widgets\MostRequestedResourcesChart;
use App\Filament\Widgets\StudentCycleAfluenceChart;
use App\Models\Equipment;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Filament\Widgets\AccountWidget;
use Filament\Widgets\FilamentInfoWidget;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class PanelInterfaceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * El panel mantiene habilitado el modo oscuro, fija el orden de los grupos de navegación,
     * agrupa las cuentas bajo Gestión de Perfiles y no registra los widgets por defecto de Filament.
     */
    public function test_admin_panel_keeps_dark_mode_organizes_navigation_and_removes_default_widgets(): void
    {
        $panel = Filament::getPanel('admin');
        $widgets = $panel->getWidgets();

        $this->assertTrue($panel->hasDarkMode());
        $this->assertSame([
            'Gestión de Seguridad',
            'Gestión de Perfiles',
            'Gestión de Inventario',
            'Gestión de Préstamos',
        ], $panel->getNavigationGroups());
        $this->assertSame('Gestión de Perfiles', UserResource::getNavigationGroup());
        $this->assertNotContains(AccountWidget::class, $widgets);
        $this->assertNotContains(FilamentInfoWidget::class, $widgets);
        $this->assertContains(EquipmentTotalOverview::class, $widgets);
        $this->assertContains(BorrowingOverviewStats::class, $widgets);
        $this->assertContains(EquipmentStatusChart::class, $widgets);
        $this->assertContains(MostRequestedResourcesChart::class, $widgets);
        $this->assertContains(StudentCycleAfluenceChart::class, $widgets);
        $this->assertContains(AfluenceDaysHoursChart::class, $widgets);
    }

    /**
     * Los widgets de inventario en el dashboard solo son visibles para usuarios con ViewAny_Equipment.
     */
    public function test_dashboard_equipment_widgets_require_view_any_equipment_permission(): void
    {
        $this->signInWithPermissions();

        $this->assertFalse(EquipmentTotalOverview::canView());
        $this->assertFalse(EquipmentStatusChart::canView());

        $this->get('/admin')->assertOk();
        Livewire::test(Dashboard::class)
            ->assertDontSeeLivewire(EquipmentTotalOverview::class)
            ->assertDontSeeLivewire(EquipmentStatusChart::class);

        $this->signInWithPermissions('ViewAny_Equipment');

        $this->assertTrue(EquipmentTotalOverview::canView());
        $this->assertTrue(EquipmentStatusChart::canView());

        $this->get('/admin')->assertOk();
        Livewire::test(Dashboard::class)
            ->assertSeeLivewire(EquipmentTotalOverview::class)
            ->assertSeeLivewire(EquipmentStatusChart::class);
    }

    /**
     * EquipmentTotalOverview muestra el total global de equipos registrados en el inventario.
     */
    public function test_equipment_total_overview_widget_displays_total_equipment_count(): void
    {
        Equipment::factory()->count(4)->create();
        $this->signInWithPermissions('ViewAny_Equipment');

        Livewire::test(EquipmentTotalOverview::class)
            ->assertSeeText('Equipos Totales')
            ->assertSeeText('4')
            ->assertSeeText('Inventario global');
    }

    /**
     * EquipmentStatusChart calcula la distribución por estado y despacha los datos actualizados
     * cuando cambia el inventario.
     */
    public function test_equipment_status_chart_widget_reflects_equipment_counts_by_status(): void
    {
        Equipment::factory()->count(2)->withStatus('Disponible')->create();
        Equipment::factory()->count(1)->withStatus('Prestado')->create();
        Equipment::factory()->count(3)->withStatus('Mantenimiento')->create();
        Equipment::factory()->count(1)->withStatus('Baja')->create();
        $this->signInWithPermissions('ViewAny_Equipment');

        $component = Livewire::test(EquipmentStatusChart::class)
            ->assertSeeText('Equipos por estado')
            ->assertSeeText('Distribución del inventario. Se actualiza cada 15 segundos.');

        Equipment::factory()->withStatus('Disponible')->create();

        $component
            ->call('updateChartData')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Equipos',
                    'data' => [3, 1, 3, 1],
                    'backgroundColor' => ['#0A2D5F', '#ea580c', '#dc2626', '#6b7280'],
                    'borderWidth' => 0,
                ]],
                'labels' => ['Disponibles', 'Prestados', 'En mantenimiento', 'De baja'],
            ]);
    }

    /**
     * La acción Limpiar de la clase base CreateRecord vacía los campos ingresados,
     * reinicia los errores de validación y envía la notificación de confirmación.
     */
    public function test_create_record_clear_fields_action_resets_form_and_validation_errors(): void
    {
        $this->signInWithPermissions('ViewAny_Equipment', 'Create_Equipment');

        Livewire::test(CreateEquipment::class)
            ->fillForm([
                'patrimonial_code' => 'AB',
                'brand' => 'Dell',
                'model' => 'Latitude 5430',
            ])
            ->call('create')
            ->assertHasFormErrors(['patrimonial_code'])
            ->callAction(TestAction::make('clearFields')->schemaComponent('form-actions', schema: 'content'))
            ->assertHasNoFormErrors()
            ->assertSet('data.patrimonial_code', null)
            ->assertSet('data.brand', null)
            ->assertSet('data.model', null)
            ->assertNotified('Campos limpiados exitosamente');
    }

    /**
     * Un usuario con fotografía en su perfil expone una URL temporal firmada desde el disco
     * privado por defecto de Filament y el panel la renderiza al ingresar.
     */
    public function test_authenticated_user_with_profile_photo_renders_private_temporary_avatar_url_in_panel(): void
    {
        $this->travelTo('2026-11-23 14:15:00');
        $disk = config('filament.default_filesystem_disk');
        Storage::fake($disk);

        $user = $this->signInWithPermissions();
        Profile::factory()->for($user)->withPhoto('profiles/photos/usuario.jpg')->create();

        $avatarUrl = $user->getFilamentAvatarUrl();

        $this->assertIsString($avatarUrl);
        $this->assertStringContainsString('profiles/photos/usuario.jpg?expiration=', $avatarUrl);
        $this->assertSame($avatarUrl, Filament::getUserAvatarUrl($user));

        $this->get('/admin')
            ->assertOk()
            ->assertSee($avatarUrl);
    }

    /**
     * Un usuario sin perfil o con fotografía vacía devuelve null como URL de avatar personalizado
     * para que Filament utilice el avatar por defecto.
     */
    public function test_user_without_profile_or_with_blank_photo_returns_null_filament_avatar_url(): void
    {
        Storage::fake(config('filament.default_filesystem_disk'));

        $userWithoutProfile = User::factory()->create();
        $userWithBlankPhoto = User::factory()->create();
        Profile::factory()->for($userWithBlankPhoto)->create(['photo' => '   ']);

        $this->assertNull($userWithoutProfile->getFilamentAvatarUrl());
        $this->assertNull($userWithBlankPhoto->getFilamentAvatarUrl());
    }

    /**
     * Si el disco por defecto de Filament se configura como public, el avatar utiliza
     * la URL pública estándar en lugar de intentar firmar una URL temporal.
     */
    public function test_user_avatar_falls_back_to_public_url_when_filament_disk_is_public(): void
    {
        config(['filament.default_filesystem_disk' => 'public']);
        Storage::fake('public');

        $user = User::factory()->create();
        Profile::factory()->for($user)->withPhoto('profiles/photos/usuario.jpg')->create();

        $this->assertSame('/storage/profiles/photos/usuario.jpg', $user->getFilamentAvatarUrl());
    }

    /**
     * Autentica a un ayudante con los permisos adicionales indicados.
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
