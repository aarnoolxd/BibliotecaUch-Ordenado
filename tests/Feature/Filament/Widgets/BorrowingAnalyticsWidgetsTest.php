<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\AfluenceDaysHoursChart;
use App\Filament\Widgets\BorrowingOverviewStats;
use App\Filament\Widgets\MostRequestedResourcesChart;
use App\Filament\Widgets\StudentCycleAfluenceChart;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BorrowingAnalyticsWidgetsTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Los 4 widgets analíticos y de KPIs de préstamos requieren el permiso ViewAny_Borrowing
     * para ser visibles en el dashboard del panel.
     */
    public function test_borrowing_analytics_widgets_require_view_any_borrowing_permission(): void
    {
        $this->signInWithPermissions();

        $this->assertFalse(BorrowingOverviewStats::canView());
        $this->assertFalse(MostRequestedResourcesChart::canView());
        $this->assertFalse(StudentCycleAfluenceChart::canView());
        $this->assertFalse(AfluenceDaysHoursChart::canView());

        Livewire::test(Dashboard::class)
            ->assertDontSeeLivewire(BorrowingOverviewStats::class)
            ->assertDontSeeLivewire(MostRequestedResourcesChart::class)
            ->assertDontSeeLivewire(StudentCycleAfluenceChart::class)
            ->assertDontSeeLivewire(AfluenceDaysHoursChart::class);

        $this->signInWithPermissions('ViewAny_Borrowing');

        $this->assertTrue(BorrowingOverviewStats::canView());
        $this->assertTrue(MostRequestedResourcesChart::canView());
        $this->assertTrue(StudentCycleAfluenceChart::canView());
        $this->assertTrue(AfluenceDaysHoursChart::canView());

        Livewire::test(Dashboard::class)
            ->assertSeeLivewire(BorrowingOverviewStats::class)
            ->assertSeeLivewire(MostRequestedResourcesChart::class)
            ->assertSeeLivewire(StudentCycleAfluenceChart::class)
            ->assertSeeLivewire(AfluenceDaysHoursChart::class);
    }

    /**
     * BorrowingOverviewStats muestra los conteos en vivo de préstamos en curso, vencidos
     * y equipos en mantenimiento junto con el historial de incidencias.
     */
    public function test_borrowing_overview_stats_widget_renders_live_operational_counts(): void
    {
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(BorrowingOverviewStats::class)
            ->assertSeeText('Préstamos En Curso')
            ->assertSeeText('Préstamos Vencidos')
            ->assertSeeText('Sin préstamos vencidos')
            ->assertSeeText('Equipos en Mantenimiento')
            ->assertSeeText('0 incidencias registradas en historial');

        Borrowing::factory()->count(2)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        Borrowing::factory()->overdue()->create();
        $returned = Borrowing::factory()->returned()->create();
        Equipment::factory()->withStatus('Mantenimiento')->create();
        EquipmentIncident::factory()->for($returned)->create();

        Livewire::test(BorrowingOverviewStats::class)
            ->assertSeeText('Requieren devolución inmediata')
            ->assertSeeText('1 incidencia registrada en historial')
            ->assertSeeText('2');
    }

    /**
     * MostRequestedResourcesChart maneja el estado vacío, incluye periféricos adicionales (N:N)
     * y actualiza el gráfico al cambiar el filtro de periodo.
     */
    public function test_most_requested_resources_chart_renders_empty_state_and_filters_by_period(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $emptyWidget = Livewire::test(MostRequestedResourcesChart::class)
            ->assertSeeText('Recursos más solicitados')
            ->assertSee('No hay datos disponibles para el periodo seleccionado');

        $mouse = Equipment::factory()->ofType('Periférico')->create([
            'patrimonial_code' => 'UCH-PER-501',
            'brand' => 'Logitech',
            'model' => 'M185',
        ]);
        $laptop = Equipment::factory()->ofType('Laptop')->create([
            'patrimonial_code' => 'UCH-LAP-501',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
        ]);

        Borrowing::factory()->count(2)
            ->withoutPrimaryEquipment()
            ->withAdditionalEquipment($mouse)
            ->returned()
            ->create(['borrowing_date' => '2026-11-22 10:00:00']);

        Borrowing::factory()
            ->for($laptop)
            ->returned()
            ->create(['borrowing_date' => '2026-08-01 10:00:00']);

        $emptyWidget
            ->set('filter', 'week')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Cantidad de préstamos',
                    'data' => [2],
                    'backgroundColor' => ['#0A2D5F'],
                    'borderRadius' => 4,
                ]],
                'labels' => ['[UCH-PER-501] Logitech M185'],
            ]);
    }

    /**
     * StudentCycleAfluenceChart muestra estado vacío sin registros y resalta en naranja el ciclo pico.
     */
    public function test_student_cycle_afluence_chart_renders_and_filters_by_period(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $emptyWidget = Livewire::test(StudentCycleAfluenceChart::class)
            ->assertSeeText('Afluencia de préstamos por ciclo académico')
            ->assertSee('Sin registros de afluencia');

        $student = Profile::factory()->create(['cycle' => 5]);
        Borrowing::factory()->count(2)->for($student)->returned()->create([
            'borrowing_date' => '2026-11-21 15:00:00',
        ]);

        $expectedColors = array_fill(0, 10, '#0A2D5F');
        $expectedColors[4] = '#ea580c';
        $expectedData = array_fill(0, 10, 0);
        $expectedData[4] = 2;

        $emptyWidget
            ->set('filter', 'month')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos por ciclo',
                    'data' => $expectedData,
                    'backgroundColor' => $expectedColors,
                    'borderRadius' => 4,
                ]],
                'labels' => [
                    'Ciclo I',
                    'Ciclo II',
                    'Ciclo III',
                    'Ciclo IV',
                    'Ciclo V',
                    'Ciclo VI',
                    'Ciclo VII',
                    'Ciclo VIII',
                    'Ciclo IX',
                    'Ciclo X',
                ],
            ]);
    }

    /**
     * AfluenceDaysHoursChart alterna correctamente entre afluencia por día de la semana
     * y afluencia por franjas horarias de 2 horas.
     */
    public function test_afluence_days_hours_chart_switches_between_days_and_hours_dimensions(): void
    {
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(AfluenceDaysHoursChart::class)
            ->assertSeeText('Días y horarios con mayor afluencia')
            ->assertSee('Sin actividad registrada en este periodo');

        // 2026-11-24 es Martes
        Borrowing::factory()->count(2)->returned()->create([
            'borrowing_date' => '2026-11-24 10:30:00',
        ]);

        $widget
            ->call('updateChartData')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos por día',
                    'data' => [0, 2, 0, 0, 0, 0],
                    'backgroundColor' => ['#2563eb', '#ea580c', '#2563eb', '#2563eb', '#2563eb', '#2563eb'],
                    'borderRadius' => 4,
                ]],
                'labels' => ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
            ])
            ->set('filter', 'hours')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos por franja horaria',
                    'data' => [0, 2, 0, 0, 0, 0],
                    'backgroundColor' => ['#0A2D5F', '#ea580c', '#0A2D5F', '#0A2D5F', '#0A2D5F', '#0A2D5F'],
                    'borderRadius' => 4,
                ]],
                'labels' => [
                    '08:00 - 10:00',
                    '10:00 - 12:00',
                    '12:00 - 14:00',
                    '14:00 - 16:00',
                    '16:00 - 18:00',
                    '18:00 - 20:00',
                ],
            ]);
    }

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
