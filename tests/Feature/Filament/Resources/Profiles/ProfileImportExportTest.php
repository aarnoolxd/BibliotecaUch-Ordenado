<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Resources\Profiles;

use App\Filament\Resources\Equipment\Pages\ListEquipments;
use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Filament\Resources\Profiles\ProfileResource;
use App\Filament\Resources\Profiles\Schemas\ProfileForm;
use App\Models\Equipment;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use App\Services\ProfileExcelImportService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
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
     * La acción Importar Excel solo se muestra y autoriza cuando el usuario tiene Create_Profile
     * y Update_Profile; un usuario con solo ViewAny_Profile no puede verla ni ejecutarla.
     */
    public function test_list_profiles_page_renders_import_button_only_with_write_permissions(): void
    {
        $this->signInWithPermissions('ViewAny_Profile');

        $this->get(ProfileResource::getUrl('index'))
            ->assertOk()
            ->assertDontSeeText('Importar Excel')
            ->assertSeeText('Exportar Excel');

        Livewire::test(ListProfiles::class)
            ->assertActionHidden('import_excel');

        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        Livewire::test(ListProfiles::class)
            ->assertActionHidden('import_excel');

        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile', 'Update_Profile');

        $this->get(ProfileResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText('Importar Excel')
            ->assertSeeText('Exportar Excel');

        Livewire::test(ListProfiles::class)
            ->assertActionVisible('import_excel');
    }

    /**
     * La acción Historial Excel en perfiles requiere View_Profile y ViewAny_Borrowing.
     */
    public function test_profile_export_history_action_requires_view_profile_and_borrowing_permissions(): void
    {
        $profile = Profile::factory()->create();
        $exportAction = TestAction::make('export_history')->table($profile);

        $this->signInWithPermissions('ViewAny_Profile');

        Livewire::test(ListProfiles::class)
            ->assertActionHidden($exportAction);

        $this->signInWithPermissions('ViewAny_Profile', 'View_Profile');

        Livewire::test(ListProfiles::class)
            ->assertActionHidden($exportAction);

        $this->signInWithPermissions('ViewAny_Profile', 'View_Profile', 'ViewAny_Borrowing');

        Livewire::test(ListProfiles::class)
            ->assertActionVisible($exportAction);
    }

    /**
     * La acción Historial de Uso Excel en equipos requiere View_Equipment, ViewAny_Borrowing y View_Profile.
     */
    public function test_equipment_export_usage_history_action_requires_equipment_borrowing_and_profile_permissions(): void
    {
        $equipment = Equipment::factory()->create();
        $exportAction = TestAction::make('export_usage_history')->table($equipment);

        $this->signInWithPermissions('ViewAny_Equipment', 'View_Equipment');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertActionHidden($exportAction);

        $this->signInWithPermissions('ViewAny_Equipment', 'View_Equipment', 'ViewAny_Borrowing');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertActionHidden($exportAction);

        $this->signInWithPermissions('ViewAny_Equipment', 'View_Equipment', 'ViewAny_Borrowing', 'View_Profile');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertActionVisible($exportAction);
    }

    /**
     * El servicio ProfileExcelImportService lee un archivo Excel real y procesa los registros.
     */
    public function test_profile_excel_import_service_imports_xlsx_file_successfully(): void
    {
        Storage::fake('local');

        $filePath = $this->createXlsxFile('temp-excel-imports/test_estudiantes.xlsx', [
            ['N.º', 'Código de estudiante', 'Tipo de alumno', 'Ap. Paterno', 'Ap. Materno', 'Nombres', 'Especialidad', 'Modalidad', 'Turno', 'Ciclo'],
            [1, '25107002', 'REGULAR', 'ABRIOJO', 'FIESTAS', 'ANTONIO', 'ADMINISTRACIÓN', 'PRESENCIAL', 'NOCHE', 4],
            [2, '26207006', 'INGRESANTE', 'AGUIRRE', 'ESTEBAN', 'SEBASTIAN LEONARDO', 'ADMINISTRACIÓN', 'SEMIPRESENCIAL', 'NOCHE', 1],
        ]);

        $result = (new ProfileExcelImportService)->import($filePath);

        $this->assertSame(2, $result['imported']);
        $this->assertSame(0, $result['updated']);
        $this->assertEmpty($result['errors']);
        $this->assertFileDoesNotExist(Storage::disk('local')->path($filePath));

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

    /**
     * El importador rechaza códigos mayores a 50 caracteres sin truncarlos, conserva el user_type
     * de perfiles existentes, limpia archivos temporales fallidos y rechaza rutas fuera del directorio temporal.
     */
    public function test_profile_excel_import_service_enforces_code_length_user_type_cleanup_and_path_bounds(): void
    {
        Storage::fake('local');

        $exact50 = str_repeat('A', 50);
        $existing50 = Profile::factory()->create([
            'student_code' => $exact50,
            'first_name' => 'ORIGINAL',
            'last_name' => 'PERFIL',
            'user_type' => Profile::USER_TYPE_STUDENT,
        ]);

        $teacher = Profile::factory()->create([
            'student_code' => 'DOC-001',
            'first_name' => 'MARIA',
            'last_name' => 'TORRES',
            'user_type' => Profile::USER_TYPE_TEACHER,
            'program' => null,
            'cycle' => null,
            'modality' => null,
            'shift' => null,
        ]);

        $filePath = $this->createXlsxFile('temp-excel-imports/edge_cases.xlsx', [
            ['Código de estudiante', 'Nombres', 'Ap. Paterno', 'Ap. Materno', 'Tipo de usuario', 'Especialidad', 'Modalidad', 'Turno', 'Ciclo'],
            [$exact50.'B', 'INTRUSO', 'APELLIDO', 'MATERNO', 'Estudiante', 'ADMINISTRACIÓN', 'PRESENCIAL', 'NOCHE', 2],
            ['NUEVO-SIN-NOMBRE', '', 'RAMOS', 'LOPEZ', 'Estudiante', 'ADMINISTRACIÓN', 'PRESENCIAL', 'NOCHE', 2],
            ['NUEVO-SIN-APELLIDO', 'CARLA', '', 'LOPEZ', 'Estudiante', 'ADMINISTRACIÓN', 'PRESENCIAL', 'NOCHE', 2],
            ['DOC-001', '', 'TORRES ACTUALIZADO', 'LOPEZ', '', '', '', '', ''],
            ['ADM-001', 'LUIS', 'GOMEZ', 'RUIZ', 'Administrativo', 'ADMINISTRACIÓN', 'PRESENCIAL', 'MAÑANA', 3],
        ]);

        $result = (new ProfileExcelImportService)->import($filePath);

        $this->assertSame(1, $result['imported']);
        $this->assertSame(1, $result['updated']);
        $this->assertCount(3, $result['errors']);
        $this->assertStringContainsString('50 caracteres', $result['errors'][0]);
        $this->assertStringContainsString('nombres y apellido paterno son obligatorios', $result['errors'][1]);
        $this->assertStringContainsString('nombres y apellido paterno son obligatorios', $result['errors'][2]);
        $this->assertFileDoesNotExist(Storage::disk('local')->path($filePath));

        $this->assertDatabaseMissing('profiles', ['student_code' => 'NUEVO-SIN-NOMBRE']);
        $this->assertDatabaseMissing('profiles', ['student_code' => 'NUEVO-SIN-APELLIDO']);
        $this->assertSame('ORIGINAL', $existing50->fresh()->first_name);
        $this->assertSame(Profile::USER_TYPE_TEACHER, $teacher->fresh()->user_type);
        $this->assertSame('MARIA', $teacher->fresh()->first_name);
        $this->assertSame('TORRES ACTUALIZADO', $teacher->fresh()->last_name);

        $this->assertDatabaseHas('profiles', [
            'student_code' => 'ADM-001',
            'user_type' => Profile::USER_TYPE_ADMINISTRATIVE,
            'program' => null,
            'cycle' => null,
            'modality' => null,
            'shift' => null,
        ]);

        // Un archivo inválido dentro de temp-excel-imports debe limpiarse en finally.
        $corruptPath = 'temp-excel-imports/corrupto.xlsx';
        Storage::disk('local')->put($corruptPath, 'no-es-un-excel-valido');
        $corruptResult = (new ProfileExcelImportService)->import($corruptPath);

        $this->assertNotEmpty($corruptResult['errors']);
        $this->assertFileDoesNotExist(Storage::disk('local')->path($corruptPath));

        // Un archivo fuera de temp-excel-imports debe rechazarse sin leerse ni eliminarse.
        $outsidePath = $this->createXlsxFile('other-dir/protegido.xlsx', [
            ['Código de estudiante', 'Nombres', 'Ap. Paterno'],
            ['EXT-999', 'NO', 'IMPORTAR'],
        ]);

        $outsideResult = (new ProfileExcelImportService)->import($outsidePath);

        $this->assertSame(0, $outsideResult['imported']);
        $this->assertNotEmpty($outsideResult['errors']);
        $this->assertFileExists(Storage::disk('local')->path($outsidePath));
        $this->assertDatabaseMissing('profiles', ['student_code' => 'EXT-999']);
    }

    /**
     * Cuando una importación desde ListProfiles tiene filas válidas y filas con error,
     * debe mostrar una advertencia de importación parcial en lugar de éxito total.
     */
    public function test_list_profiles_import_action_warns_on_partial_import_errors(): void
    {
        Storage::fake('local');
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile', 'Update_Profile');

        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray([
            ['Código de estudiante', 'Nombres', 'Ap. Paterno'],
            ['PARCIAL-001', 'SOFIA', 'RAMOS'],
            [str_repeat('Z', 51), 'INVALIDA', 'CODIGO'],
        ]);

        $tempPath = tempnam(sys_get_temp_dir(), 'partial_import_');
        $this->assertIsString($tempPath);
        (new Xlsx($spreadsheet))->save($tempPath);

        try {
            $uploaded = UploadedFile::fake()->createWithContent(
                'parcial.xlsx',
                (string) file_get_contents($tempPath),
            )->mimeType('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

            Livewire::test(ListProfiles::class)
                ->callAction('import_excel', [
                    'excel_file' => $uploaded,
                ])
                ->assertNotified('Importación parcial');

            $this->assertDatabaseHas('profiles', [
                'student_code' => 'PARCIAL-001',
                'first_name' => 'SOFIA',
            ]);
        } finally {
            if (is_file($tempPath)) {
                @unlink($tempPath);
            }
        }
    }

    /**
     * ProfileForm::normalizeAcademicData limpia program, cycle, modality y shift cuando el perfil
     * no es Estudiante y conserva los cuatro campos cuando sí es Estudiante.
     */
    public function test_profile_form_normalize_academic_data_clears_modality_and_shift_for_non_students(): void
    {
        $teacherData = ProfileForm::normalizeAcademicData([
            'user_type' => Profile::USER_TYPE_TEACHER,
            'program' => 'Administración',
            'cycle' => 5,
            'modality' => 'PRESENCIAL',
            'shift' => 'NOCHE',
        ]);

        $this->assertNull($teacherData['program']);
        $this->assertNull($teacherData['cycle']);
        $this->assertNull($teacherData['modality']);
        $this->assertNull($teacherData['shift']);

        $studentData = ProfileForm::normalizeAcademicData([
            'user_type' => Profile::USER_TYPE_STUDENT,
            'program' => 'Administración',
            'cycle' => 5,
            'modality' => 'PRESENCIAL',
            'shift' => 'NOCHE',
        ]);

        $this->assertSame('Administración', $studentData['program']);
        $this->assertSame(5, $studentData['cycle']);
        $this->assertSame('PRESENCIAL', $studentData['modality']);
        $this->assertSame('NOCHE', $studentData['shift']);
    }

    /**
     * @param  list<list<mixed>>  $rows
     */
    private function createXlsxFile(string $relativePath, array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);

        $fullPath = Storage::disk('local')->path($relativePath);
        @mkdir(dirname($fullPath), 0777, true);

        (new Xlsx($spreadsheet))->save($fullPath);

        return $relativePath;
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
