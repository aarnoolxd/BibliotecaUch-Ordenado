<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\AfluenceDaysHoursChart;
use App\Filament\Widgets\BorrowingOverviewStats;
use App\Filament\Widgets\MostRequestedResourcesChart;
use App\Filament\Widgets\StudentCycleAfluenceChart;
use App\Models\Borrowing;
use App\Models\Equipment;
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
     * BorrowingOverviewStats muestra las 4 tarjetas seleccionables (Registrados, En Curso,
     * Devueltos y Vencidos), no navega a los filtros de tabla y despacha el evento de filtro
     * para los gráficos del dashboard al seleccionar o deseleccionar un estado.
     */
    public function test_borrowing_overview_stats_widget_acts_as_selectable_dashboard_filter(): void
    {
        Borrowing::factory()->count(2)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        Borrowing::factory()->count(1)->overdue()->create();
        Borrowing::factory()->count(1)->returned()->create();

        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(BorrowingOverviewStats::class)
            ->assertSeeText('Préstamos Registrados')
            ->assertSeeText('4')
            ->assertSeeText('En Curso')
            ->assertSeeText('Devueltos')
            ->assertSeeText('Vencidos')
            ->assertSeeText('Filtro activo · Todos los préstamos')
            ->assertDontSee('/admin/borrowings?filters')
            ->call('selectStatus', Borrowing::STATUS_OVERDUE)
            ->assertSet('selectedStatus', Borrowing::STATUS_OVERDUE)
            ->assertDispatched(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: Borrowing::STATUS_OVERDUE)
            ->assertSeeText('Filtro activo · 25% del total')
            ->call('selectStatus', Borrowing::STATUS_OVERDUE)
            ->assertSet('selectedStatus', null)
            ->assertDispatched(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: null)
            ->call('selectStatus', Borrowing::STATUS_IN_PROGRESS)
            ->assertSet('selectedStatus', Borrowing::STATUS_IN_PROGRESS)
            ->assertDispatched(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: Borrowing::STATUS_IN_PROGRESS)
            ->call('selectStatus', null)
            ->assertSet('selectedStatus', null)
            ->assertDispatched(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: null);
    }

    /**
     * MostRequestedResourcesChart maneja estado vacío, incluye periféricos (N:N), filtra por
     * periodo y reacciona al evento de estado emitido por las tarjetas KPI del dashboard.
     */
    public function test_most_requested_resources_chart_reacts_to_period_and_kpi_status_filter(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(MostRequestedResourcesChart::class)
            ->assertSeeText('Recursos más solicitados')
            ->assertSee('No hay datos disponibles para el filtro seleccionado');

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
            ->overdue()
            ->create(['borrowing_date' => '2026-11-22 09:00:00']);

        $widget
            ->set('filter', 'week')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Cantidad de préstamos',
                    'data' => [2, 1],
                    'backgroundColor' => ['#0A2D5F', '#1e40af'],
                    'borderRadius' => 4,
                ]],
                'labels' => [
                    '[UCH-PER-501] Logitech M185',
                    '[UCH-LAP-501] Lenovo ThinkPad T14',
                ],
            ])
            ->dispatch(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: Borrowing::STATUS_OVERDUE)
            ->assertSet('borrowingStatus', Borrowing::STATUS_OVERDUE)
            ->assertSeeText('Estado seleccionado: Vencido.')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Cantidad de préstamos',
                    'data' => [1],
                    'backgroundColor' => ['#0A2D5F'],
                    'borderRadius' => 4,
                ]],
                'labels' => ['[UCH-LAP-501] Lenovo ThinkPad T14'],
            ]);
    }

    /**
     * StudentCycleAfluenceChart muestra estado vacío, filtra por periodo y responde
     * al filtro de estado emitido por BorrowingOverviewStats.
     */
    public function test_student_cycle_afluence_chart_reacts_to_period_and_kpi_status_filter(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(StudentCycleAfluenceChart::class)
            ->assertSeeText('Afluencia de préstamos por ciclo académico')
            ->assertSee('Sin registros de afluencia');

        $cycleFiveStudent = Profile::factory()->create(['cycle' => 5]);
        $cycleEightStudent = Profile::factory()->create(['cycle' => 8]);

        Borrowing::factory()->count(2)->for($cycleFiveStudent)->returned()->create([
            'borrowing_date' => '2026-11-21 15:00:00',
        ]);
        Borrowing::factory()->for($cycleEightStudent)->overdue()->create([
            'borrowing_date' => '2026-11-22 10:00:00',
        ]);

        $expectedOverdueColors = array_fill(0, 10, '#0A2D5F');
        $expectedOverdueColors[7] = '#ea580c';
        $expectedOverdueData = array_fill(0, 10, 0);
        $expectedOverdueData[7] = 1;

        $widget
            ->set('filter', 'month')
            ->dispatch(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: Borrowing::STATUS_OVERDUE)
            ->assertSet('borrowingStatus', Borrowing::STATUS_OVERDUE)
            ->assertSeeText('Estado seleccionado: Vencido.')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos por ciclo',
                    'data' => $expectedOverdueData,
                    'backgroundColor' => $expectedOverdueColors,
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
     * AfluenceDaysHoursChart alterna entre días y horarios y responde al filtro de estado del KPI.
     */
    public function test_afluence_days_hours_chart_switches_dimensions_and_reacts_to_kpi_status_filter(): void
    {
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(AfluenceDaysHoursChart::class)
            ->assertSeeText('Días y horarios con mayor afluencia')
            ->assertSee('Sin actividad registrada para el filtro seleccionado');

        // 2026-11-24 es Martes (Devuelto); 2026-11-26 es Jueves (En curso)
        Borrowing::factory()->count(2)->returned()->create([
            'borrowing_date' => '2026-11-24 10:30:00',
        ]);
        Borrowing::factory()->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-26 16:15:00',
        ]);

        $widget
            ->call('updateChartData')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos por día',
                    'data' => [0, 2, 0, 1, 0, 0],
                    'backgroundColor' => ['#2563eb', '#ea580c', '#2563eb', '#2563eb', '#2563eb', '#2563eb'],
                    'borderRadius' => 4,
                ]],
                'labels' => ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'],
            ])
            ->set('filter', 'hours')
            ->dispatch(BorrowingOverviewStats::STATUS_FILTER_EVENT, status: Borrowing::STATUS_IN_PROGRESS)
            ->assertSet('borrowingStatus', Borrowing::STATUS_IN_PROGRESS)
            ->assertSeeText('Estado seleccionado: En curso.')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos por franja horaria',
                    'data' => [0, 0, 0, 0, 1, 0],
                    'backgroundColor' => ['#0A2D5F', '#0A2D5F', '#0A2D5F', '#0A2D5F', '#ea580c', '#0A2D5F'],
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
