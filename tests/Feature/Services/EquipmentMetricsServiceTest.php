<?php

namespace Tests\Feature\Services;

use App\Models\Equipment;
use App\Services\EquipmentMetricsService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EquipmentMetricsServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * getMetrics debe calcular total y porcentajes a partir de una única consulta agrupada,
     * garantizando coherencia entre el total y la suma de los estados.
     */
    public function test_get_metrics_calculates_total_and_percentages_from_a_single_grouped_query(): void
    {
        Equipment::factory()->count(2)->ofType('Laptop')->withStatus('Disponible')->create();
        Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        Equipment::factory()->ofType('Computadora')->withStatus('Mantenimiento')->create();

        $service = new EquipmentMetricsService;

        DB::flushQueryLog();
        DB::enableQueryLog();
        $metrics = $service->getMetrics();
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $queries);
        $this->assertSame(4, $metrics['total']);
        $this->assertSame(2, $metrics['by_status']['Disponible']['count']);
        $this->assertSame(50.0, $metrics['by_status']['Disponible']['percentage']);
        $this->assertSame(1, $metrics['by_status']['Prestado']['count']);
        $this->assertSame(25.0, $metrics['by_status']['Prestado']['percentage']);
        $this->assertSame(1, $metrics['by_status']['Mantenimiento']['count']);
        $this->assertSame(25.0, $metrics['by_status']['Mantenimiento']['percentage']);
        $this->assertSame(0, $metrics['by_status']['Baja']['count']);
        $this->assertSame(0.0, $metrics['by_status']['Baja']['percentage']);

        $laptopMetrics = $service->getMetrics('Laptop');

        $this->assertSame(3, $laptopMetrics['total']);
        $this->assertSame('Laptop', $laptopMetrics['type']);
        $this->assertSame(2, $laptopMetrics['by_status']['Disponible']['count']);
        $this->assertSame(66.7, $laptopMetrics['by_status']['Disponible']['percentage']);
        $this->assertSame(1, $laptopMetrics['by_status']['Prestado']['count']);
        $this->assertSame(33.3, $laptopMetrics['by_status']['Prestado']['percentage']);
    }
}
