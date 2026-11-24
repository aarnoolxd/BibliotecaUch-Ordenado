<?php

namespace Tests\Feature\Filament\Widgets;

use App\Filament\Widgets\AfluenceDaysHoursChart;
use App\Filament\Widgets\BorrowingOverviewStats;
use App\Filament\Widgets\BorrowingStatusChart;
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
     * Todos los widgets de préstamos en el dashboard requieren ViewAny_Borrowing para ser visibles.
     */
    public function test_borrowing_dashboard_widgets_require_view_any_borrowing_permission(): void
    {
        $this->signInWithPermissions();

        $this->assertFalse(BorrowingOverviewStats::canView());
        $this->assertFalse(BorrowingStatusChart::canView());
        $this->assertFalse(MostRequestedResourcesChart::canView());
        $this->assertFalse(StudentCycleAfluenceChart::canView());
        $this->assertFalse(AfluenceDaysHoursChart::canView());

        Livewire::test(Dashboard::class)
            ->assertDontSeeLivewire(BorrowingOverviewStats::class)
            ->assertDontSeeLivewire(BorrowingStatusChart::class)
            ->assertDontSeeLivewire(MostRequestedResourcesChart::class)
            ->assertDontSeeLivewire(StudentCycleAfluenceChart::class)
            ->assertDontSeeLivewire(AfluenceDaysHoursChart::class);

        $this->signInWithPermissions('ViewAny_Borrowing');

        $this->assertTrue(BorrowingOverviewStats::canView());
        $this->assertTrue(BorrowingStatusChart::canView());
        $this->assertTrue(MostRequestedResourcesChart::canView());
        $this->assertTrue(StudentCycleAfluenceChart::canView());
        $this->assertTrue(AfluenceDaysHoursChart::canView());

        Livewire::test(Dashboard::class)
            ->assertSeeLivewire(BorrowingOverviewStats::class)
            ->assertSeeLivewire(BorrowingStatusChart::class)
            ->assertSeeLivewire(MostRequestedResourcesChart::class)
            ->assertSeeLivewire(StudentCycleAfluenceChart::class)
            ->assertSeeLivewire(AfluenceDaysHoursChart::class);
    }

    /**
     * BorrowingOverviewStats muestra el total global de préstamos registrados.
     */
    public function test_borrowing_overview_stats_widget_displays_total_borrowings_count(): void
    {
        Borrowing::factory()->count(5)->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(BorrowingOverviewStats::class)
            ->assertSeeText('Préstamos Totales')
            ->assertSeeText('5')
            ->assertSeeText('Historial global');
    }

    /**
     * BorrowingStatusChart muestra la distribución de préstamos por estado en barras
     * horizontales y responde al filtro de periodo.
     */
    public function test_borrowing_status_chart_displays_status_distribution_and_filters_by_period(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(BorrowingStatusChart::class)
            ->assertSeeText('Préstamos por estado')
            ->assertSeeText('Distribución de préstamos registrados.');

        Borrowing::factory()->count(2)->create([
            'status' => Borrowing::STATUS_IN_PROGRESS,
            'borrowing_date' => '2026-11-22 10:00:00',
        ]);
        Borrowing::factory()->overdue()->create([
            'borrowing_date' => '2026-11-21 10:00:00',
        ]);
        Borrowing::factory()->count(3)->returned()->create([
            'borrowing_date' => '2026-08-01 10:00:00',
        ]);

        $widget
            ->call('updateChartData')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos',
                    'data' => [2, 3, 1],
                    'backgroundColor' => ['#2563eb', '#059669', '#dc2626'],
                    'borderRadius' => 6,
                    'barThickness' => 26,
                ]],
                'labels' => ['En curso', 'Devueltos', 'Vencidos'],
            ])
            ->set('filter', 'week')
            ->assertDispatched('updateChartData', data: [
                'datasets' => [[
                    'label' => 'Préstamos',
                    'data' => [2, 0, 1],
                    'backgroundColor' => ['#2563eb', '#059669', '#dc2626'],
                    'borderRadius' => 6,
                    'barThickness' => 26,
                ]],
                'labels' => ['En curso', 'Devueltos', 'Vencidos'],
            ]);
    }

    /**
     * MostRequestedResourcesChart maneja el estado vacío, incluye periféricos (N:N)
     * y actualiza el gráfico al cambiar el filtro de periodo.
     */
    public function test_most_requested_resources_chart_renders_empty_state_and_filters_by_period(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(MostRequestedResourcesChart::class)
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

        $widget
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
     * StudentCycleAfluenceChart muestra estado vacío sin registros y resalta el ciclo pico.
     */
    public function test_student_cycle_afluence_chart_renders_and_filters_by_period(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $this->signInWithPermissions('ViewAny_Borrowing');

        $widget = Livewire::test(StudentCycleAfluenceChart::class)
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

        $widget
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
     * AfluenceDaysHoursChart alterna correctamente entre días laborales y franjas horarias.
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
