<?php

namespace Tests\Feature\Services;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Models\Profile;
use App\Services\BorrowingAnalyticsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class BorrowingAnalyticsServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * getOperationalSummary consolida préstamos en curso, préstamos vencidos,
     * equipos en mantenimiento e incidencias totales sin incluir préstamos devueltos.
     */
    public function test_operational_summary_counts_active_borrowings_maintenance_and_incidents(): void
    {
        Borrowing::factory()->count(2)->create(['status' => Borrowing::STATUS_IN_PROGRESS]);
        Borrowing::factory()->count(3)->overdue()->create();
        $returned = Borrowing::factory()->returned()->create();

        Equipment::factory()->count(2)->withStatus('Mantenimiento')->create();
        Equipment::factory()->withStatus('Disponible')->create();
        EquipmentIncident::factory()->count(4)->for($returned)->create();

        $summary = app(BorrowingAnalyticsService::class)->getOperationalSummary();

        $this->assertSame([
            'in_progress' => 2,
            'overdue' => 3,
            'in_maintenance' => 2,
            'total_incidents' => 4,
        ], $summary);
    }

    /**
     * getMostRequestedResources contabiliza equipos principales y periféricos/otros (N:N),
     * respeta el límite superior y filtra correctamente por periodo.
     */
    public function test_most_requested_resources_combines_primary_and_additional_equipment_with_period_filters(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $service = app(BorrowingAnalyticsService::class);

        $this->assertSame([
            'labels' => [],
            'data' => [],
            'empty' => true,
        ], $service->getMostRequestedResources('all', 5));

        $computer = Equipment::factory()->ofType('Computadora')->create([
            'patrimonial_code' => 'UCH-PC-001',
            'brand' => 'Dell',
            'model' => 'OptiPlex 7090',
        ]);
        $laptop = Equipment::factory()->ofType('Laptop')->create([
            'patrimonial_code' => 'UCH-LAP-001',
            'brand' => 'Lenovo',
            'model' => 'ThinkPad T14',
        ]);
        $mouse = Equipment::factory()->ofType('Periférico')->create([
            'patrimonial_code' => 'UCH-PER-001',
            'brand' => 'Logitech',
            'model' => 'M185',
        ]);

        // 2 préstamos recientes con computadora + mouse
        Borrowing::factory()->count(2)
            ->for($computer)
            ->withAdditionalEquipment($mouse)
            ->returned()
            ->create(['borrowing_date' => '2026-11-21 10:00:00']);

        // 1 préstamo reciente de solo periférico (mouse suma 3 préstamos en total)
        Borrowing::factory()
            ->withoutPrimaryEquipment()
            ->withAdditionalEquipment($mouse)
            ->returned()
            ->create(['borrowing_date' => '2026-11-22 11:00:00']);

        // 2 préstamos antiguos de laptop (fuera de última semana y último mes, dentro de último semestre)
        Borrowing::factory()->count(2)
            ->for($laptop)
            ->returned()
            ->create(['borrowing_date' => '2026-08-10 09:00:00']);

        // 1 préstamo muy antiguo de laptop (fuera del último semestre)
        Borrowing::factory()
            ->for($laptop)
            ->returned()
            ->create(['borrowing_date' => '2025-12-01 09:00:00']);

        $allTopTwo = $service->getMostRequestedResources('all', 2);

        $this->assertFalse($allTopTwo['empty']);
        $this->assertSame([
            '[UCH-LAP-001] Lenovo ThinkPad T14',
            '[UCH-PER-001] Logitech M185',
        ], $allTopTwo['labels']);
        $this->assertSame([3, 3], $allTopTwo['data']);

        $weekRanking = $service->getMostRequestedResources('week', 5);

        $this->assertFalse($weekRanking['empty']);
        $this->assertSame([
            '[UCH-PER-001] Logitech M185',
            '[UCH-PC-001] Dell OptiPlex 7090',
        ], $weekRanking['labels']);
        $this->assertSame([3, 2], $weekRanking['data']);

        $semesterRanking = $service->getMostRequestedResources('semester', 5);

        $this->assertSame([
            '[UCH-PER-001] Logitech M185',
            '[UCH-PC-001] Dell OptiPlex 7090',
            '[UCH-LAP-001] Lenovo ThinkPad T14',
        ], $semesterRanking['labels']);
        $this->assertSame([3, 2, 2], $semesterRanking['data']);
    }

    /**
     * getAfluenceByCycle agrupa préstamos de estudiantes por ciclo (I al X), ignora perfiles
     * sin ciclo (docentes/administrativos), resalta el ciclo pico y respeta el filtro de periodo.
     */
    public function test_afluence_by_cycle_groups_student_borrowings_highlights_peak_and_filters_by_period(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $service = app(BorrowingAnalyticsService::class);

        $empty = $service->getAfluenceByCycle('all');
        $this->assertTrue($empty['empty']);
        $this->assertNull($empty['max_cycle']);
        $this->assertCount(10, $empty['labels']);
        $this->assertSame(array_fill(0, 10, 0), $empty['data']);

        $cycleThreeStudent = Profile::factory()->create(['cycle' => 3]);
        $cycleSevenStudent = Profile::factory()->create(['cycle' => 7]);
        $teacher = Profile::factory()->teacher()->create();

        Borrowing::factory()->count(3)->for($cycleThreeStudent)->returned()->create([
            'borrowing_date' => '2026-11-20 10:00:00',
        ]);
        Borrowing::factory()->count(2)->for($cycleSevenStudent)->returned()->create([
            'borrowing_date' => '2026-09-15 10:00:00',
        ]);
        Borrowing::factory()->for($teacher)->returned()->create([
            'borrowing_date' => '2026-11-20 11:00:00',
        ]);
        Borrowing::factory()->returned()->create([
            'profile_id' => null,
            'borrowing_date' => '2026-11-20 11:30:00',
        ]);

        $all = $service->getAfluenceByCycle('all');

        $this->assertFalse($all['empty']);
        $this->assertSame(3, $all['max_cycle']);
        $this->assertSame('Ciclo III', $all['labels'][2]);
        $this->assertSame(3, $all['data'][2]);
        $this->assertSame(2, $all['data'][6]);
        $this->assertSame('#ea580c', $all['backgroundColors'][2]);
        $this->assertSame('#0A2D5F', $all['backgroundColors'][6]);

        $monthOnly = $service->getAfluenceByCycle('month');

        $this->assertSame(3, $monthOnly['data'][2]);
        $this->assertSame(0, $monthOnly['data'][6]);
    }

    /**
     * getAfluenceByDaysAndHours distribuye préstamos por día laboral (Lunes–Sábado) o franjas
     * de 2 horas (08:00–20:00), excluye registros fuera de rango y resalta el pico en naranja.
     */
    public function test_afluence_by_days_and_hours_calculates_weekday_and_two_hour_slot_peaks(): void
    {
        $this->travelTo('2026-11-23 12:00:00');
        $service = app(BorrowingAnalyticsService::class);

        $this->assertTrue($service->getAfluenceByDaysAndHours('days', 'all')['empty']);
        $this->assertTrue($service->getAfluenceByDaysAndHours('hours', 'all')['empty']);

        // 2026-11-23 es Lunes; 2026-11-25 es Miércoles; 2026-11-22 es Domingo (excluido de Lunes-Sábado)
        Borrowing::factory()->returned()->create(['borrowing_date' => '2026-11-23 09:15:00']);
        Borrowing::factory()->returned()->create(['borrowing_date' => '2026-11-23 14:30:00']);
        Borrowing::factory()->returned()->create(['borrowing_date' => '2026-11-25 14:45:00']);
        Borrowing::factory()->returned()->create(['borrowing_date' => '2026-11-22 20:05:00']);

        $byDays = $service->getAfluenceByDaysAndHours('days', 'all');

        $this->assertFalse($byDays['empty']);
        $this->assertSame(['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado'], $byDays['labels']);
        $this->assertSame([2, 0, 1, 0, 0, 0], $byDays['data']);
        $this->assertSame('#ea580c', $byDays['backgroundColors'][0]);
        $this->assertSame('#2563eb', $byDays['backgroundColors'][2]);

        $byHours = $service->getAfluenceByDaysAndHours('hours', 'all');

        $this->assertFalse($byHours['empty']);
        $this->assertSame([
            '08:00 - 10:00',
            '10:00 - 12:00',
            '12:00 - 14:00',
            '14:00 - 16:00',
            '16:00 - 18:00',
            '18:00 - 20:00',
        ], $byHours['labels']);
        // 20:05 queda fuera de [18, 20)
        $this->assertSame([1, 0, 0, 2, 0, 0], $byHours['data']);
        $this->assertSame('#ea580c', $byHours['backgroundColors'][3]);
        $this->assertSame('#0A2D5F', $byHours['backgroundColors'][0]);
    }
}
