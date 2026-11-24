<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Resources\Profiles;

use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Filament\Resources\Profiles\ProfileResource;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use App\Services\ProfileExcelImportService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ProfileImportExportTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * La página de ListProfiles muestra los botones de importar y exportar.
     */
    public function test_list_profiles_page_renders_import_and_export_buttons(): void
    {
        $this->signInWithPermissions('ViewAny_Profile');

        $this->get(ProfileResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText('Importar Excel')
            ->assertSeeText('Exportar Excel');
    }

    /**
     * El servicio ProfileExcelImportService lee un archivo Excel real y procesa los registros.
     */
    public function test_profile_excel_import_service_imports_xlsx_file_successfully(): void
    {
        Storage::fake('local');

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        // Encabezados tal como vienen en el Excel
        $sheet->fromArray([
            ['N.º', 'Código de estudiante', 'Tipo de alumno', 'Ap. Paterno', 'Ap. Materno', 'Nombres', 'Especialidad', 'Modalidad', 'Turno', 'Ciclo'],
            [1, '25107002', 'REGULAR', 'ABRIOJO', 'FIESTAS', 'ANTONIO', 'ADMINISTRACIÓN', 'PRESENCIAL', 'NOCHE', 4],
            [2, '26207006', 'INGRESANTE', 'AGUIRRE', 'ESTEBAN', 'SEBASTIAN LEONARDO', 'ADMINISTRACIÓN', 'SEMIPRESENCIAL', 'NOCHE', 1],
        ]);

        $filePath = 'temp-excel-imports/test_estudiantes.xlsx';
        $fullPath = Storage::disk('local')->path($filePath);
        @mkdir(dirname($fullPath), 0777, true);

        $writer = new Xlsx($spreadsheet);
        $writer->save($fullPath);

        $service = new ProfileExcelImportService;
        $result = $service->import($filePath);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['updated']);
        $this->assertEmpty($result['errors']);

        $this->assertDatabaseHas('profiles', [
            'student_code' => '25107002',
            'first_name' => 'ANTONIO',
            'last_name' => 'ABRIOJO',
            'maternal_last_name' => 'FIESTAS',
            'student_type' => 'REGULAR',
            'program' => 'Administración',
            'modality' => 'PRESENCIAL',
            'shift' => 'NOCHE',
            'cycle' => 4,
            'user_type' => Profile::USER_TYPE_STUDENT,
        ]);

        $this->assertDatabaseHas('profiles', [
            'student_code' => '26207006',
            'first_name' => 'SEBASTIAN LEONARDO',
            'last_name' => 'AGUIRRE',
            'student_type' => 'INGRESANTE',
            'program' => 'Administración',
            'modality' => 'SEMIPRESENCIAL',
            'shift' => 'NOCHE',
            'cycle' => 1,
            'user_type' => Profile::USER_TYPE_STUDENT,
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
