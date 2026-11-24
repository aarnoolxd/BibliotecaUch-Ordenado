<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use App\Services\ExportHistoryExcelService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ExportHistoryExcelServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_export_student_history_generates_streamed_excel_response(): void
    {
        $profile = Profile::factory()->create([
            'student_code' => '25107002',
            'first_name' => 'ANTONIO',
            'last_name' => 'ABRIOJO',
            'program' => 'Administración',
        ]);

        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create();

        Borrowing::factory()->create([
            'profile_id' => $profile->id,
            'student_code' => $profile->student_code,
            'equipment_id' => $equipment->id,
            'status' => 'Devuelto',
            'borrowing_date' => now()->subDays(2),
            'return_date' => now()->subDays(2)->addHours(2),
        ]);

        $service = new ExportHistoryExcelService;
        $response = $service->exportStudentHistory($profile);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('historial_estudiante_25107002', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_export_equipment_history_generates_streamed_excel_response(): void
    {
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-PC-001',
        ]);

        $profile = Profile::factory()->create([
            'student_code' => '26207006',
            'first_name' => 'SEBASTIAN',
            'last_name' => 'AGUIRRE',
        ]);

        Borrowing::factory()->create([
            'profile_id' => $profile->id,
            'student_code' => $profile->student_code,
            'equipment_id' => $equipment->id,
            'status' => 'Devuelto',
            'borrowing_date' => now()->subDay(),
            'return_date' => now()->subDay()->addHours(1),
        ]);

        $service = new ExportHistoryExcelService;
        $response = $service->exportEquipmentHistory($equipment);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('historial_equipo_UCH-PC-001', (string) $response->headers->get('Content-Disposition'));
    }
}
