<?php

namespace Tests\Feature\Filament\Resources\Borrowings;

use App\Filament\Resources\Borrowings\BorrowingResource;
use App\Filament\Resources\Borrowings\Pages\CreateBorrowing;
use App\Filament\Resources\Borrowings\Pages\EditBorrowing;
use App\Filament\Resources\Borrowings\Pages\ListBorrowings;
use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\ImageEntry;
use Filament\Schemas\Schema;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class BorrowingSheetModalTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un usuario con View_Borrowing puede abrir la ficha modal del préstamo y ver
     * los recursos asignados, cronología, observaciones y, cuando existan, las incidencias con foto privada.
     */
    public function test_a_user_with_view_permission_can_open_the_borrowing_sheet_modal(): void
    {
        $disk = config('filament.default_filesystem_disk');
        Storage::fake($disk);
        Storage::disk($disk)->put(
            'profiles/photos/estudiante.jpg',
            UploadedFile::fake()->image('estudiante.jpg', 120, 120)->getContent(),
        );
        Storage::disk($disk)->put(
            'borrowings/incidents/mouse-danado.jpg',
            UploadedFile::fake()->image('mouse-danado.jpg', 200, 150)->getContent(),
        );

        $registrar = User::factory()->create(['name' => 'Bibliotecario Turno Mañana']);
        $profile = Profile::factory()->withPhoto('profiles/photos/estudiante.jpg')->create([
            'student_code' => '74561234',
            'first_name' => 'Andrea',
            'last_name' => 'Gomez',
            'maternal_last_name' => 'Rios',
            'phone' => '987654321',
        ]);
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-PC-010',
            'brand' => 'Dell',
            'model' => 'OptiPlex 7090',
        ]);
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Mantenimiento')->create([
            'patrimonial_code' => 'UCH-PER-025',
            'brand' => 'Logitech',
            'model' => 'M185',
        ]);

        $borrowingWithIncident = Borrowing::factory()
            ->for($computer)
            ->for($profile)
            ->for($registrar, 'registeredBy')
            ->withAdditionalEquipment($mouse)
            ->returned()
            ->create([
                'observations' => 'Entregado con cargador.',
            ]);

        EquipmentIncident::factory()
            ->for($mouse)
            ->for($borrowingWithIncident)
            ->withPhoto('borrowings/incidents/mouse-danado.jpg')
            ->create([
                'description' => 'Rueda central atascada.',
            ]);

        $cleanPeripheralsBorrowing = Borrowing::factory()
            ->withoutPrimaryEquipment()
            ->withAdditionalEquipment($mouse)
            ->create([
                'profile_id' => null,
                'student_code' => '79001122',
                'observations' => null,
            ]);

        $this->signInWithPermissions('ViewAny_Borrowing', 'View_Borrowing');

        $modal = Livewire::test(ListBorrowings::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($borrowingWithIncident))
            ->assertActionMounted(TestAction::make(ViewAction::class)->table($borrowingWithIncident))
            ->assertMountedActionModalSee([
                'Ficha del préstamo',
                'BIBLIOTECA UCH',
                'Andrea Gomez Rios',
                'Devuelto',
                '[UCH-PC-010] Dell OptiPlex 7090',
                '[UCH-PER-025] Logitech M185',
                '74561234',
                '987654321',
                'Bibliotecario Turno Mañana',
                'Entregado con cargador.',
                'INCIDENCIAS POR DAÑO REPORTADAS',
                '[UCH-PER-025] Logitech M185',
                'Rueda central atascada.',
                'Cerrar ficha',
            ])
            ->assertMountedActionModalSeeHtml([
                'lightbox-thumb-btn',
                'x-teleport="body"',
                'x-trap="open"',
                'borrowings/incidents/mouse-danado.jpg?expiration=',
            ]);

        $infolist = BorrowingResource::infolist(Schema::make($modal->instance())->record($borrowingWithIncident));
        $profilePhotoEntry = $infolist->getComponentByStatePath('profile.photo', withHidden: true);

        $this->assertInstanceOf(ImageEntry::class, $profilePhotoEntry);
        $this->assertSame($disk, $profilePhotoEntry->getDiskName());
        $this->assertSame('private', $profilePhotoEntry->getVisibility());
        $resolvedProfilePhoto = $profilePhotoEntry->getImageUrl($profile->photo);
        $this->assertIsString($resolvedProfilePhoto);
        $this->assertStringContainsString('profiles/photos/estudiante.jpg?expiration=', $resolvedProfilePhoto);

        Livewire::test(ListBorrowings::class)
            ->mountAction(TestAction::make(ViewAction::class)->table($cleanPeripheralsBorrowing))
            ->assertActionMounted(TestAction::make(ViewAction::class)->table($cleanPeripheralsBorrowing))
            ->assertMountedActionModalSee([
                'Estudiante no vinculado',
                'Sin equipo principal (solo periféricos)',
                'Sin límite',
                'Pendiente de devolución',
                'Sin observaciones registradas.',
                'Cerrar ficha',
            ])
            ->assertMountedActionModalDontSee('INCIDENCIAS POR DAÑO REPORTADAS');
    }

    /**
     * Un usuario sin View_Borrowing no debe ver la acción para abrir la ficha del préstamo.
     */
    public function test_a_user_without_view_permission_cannot_see_the_borrowing_sheet_action(): void
    {
        $borrowing = Borrowing::factory()->create();
        $this->signInWithPermissions('ViewAny_Borrowing');

        Livewire::test(ListBorrowings::class)
            ->assertActionHidden(TestAction::make(ViewAction::class)->table($borrowing));
    }

    /**
     * En los formularios de creación y edición de préstamo, el botón del ojito junto a
     * Estudiante Identificado permanece deshabilitado sin código válido y abre la ficha del perfil
     * cuando existe un estudiante identificado.
     */
    public function test_borrowing_create_and_edit_forms_can_open_the_identified_student_profile_sheet(): void
    {
        $profile = Profile::factory()->create([
            'student_code' => '76543210',
            'first_name' => 'Lucia',
            'last_name' => 'Vargas',
            'maternal_last_name' => 'Salas',
            'user_type' => Profile::USER_TYPE_STUDENT,
            'program' => 'Ingeniería de Sistemas e Informática',
            'cycle' => 6,
            'phone' => '912345678',
            'address' => 'Av. Universitaria 5175',
        ]);
        $equipment = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $borrowing = Borrowing::factory()
            ->for($equipment)
            ->for($profile)
            ->create([
                'student_code' => $profile->student_code,
                'status' => Borrowing::STATUS_IN_PROGRESS,
            ]);

        $viewProfileAction = TestAction::make('viewStudentProfile')->schemaComponent('student_name');

        $this->signInWithPermissions('ViewAny_Borrowing', 'Create_Borrowing', 'Update_Borrowing');

        Livewire::test(CreateBorrowing::class)
            ->assertActionDisabled($viewProfileAction)
            ->set('data.student_code', $profile->student_code)
            ->assertActionEnabled($viewProfileAction)
            ->mountAction($viewProfileAction)
            ->assertActionMounted($viewProfileAction)
            ->assertMountedActionModalSee([
                'Ficha del perfil',
                'COMUNIDAD UCH',
                'Lucia Vargas Salas',
                'Ingeniería de Sistemas e Informática',
                'Ciclo 6',
                '76543210',
                '912345678',
                'Av. Universitaria 5175',
                'Cerrar ficha',
            ]);

        Livewire::test(EditBorrowing::class, ['record' => $borrowing->getRouteKey()])
            ->assertActionEnabled($viewProfileAction)
            ->mountAction($viewProfileAction)
            ->assertActionMounted($viewProfileAction)
            ->assertMountedActionModalSee([
                'Ficha del perfil',
                'Lucia Vargas Salas',
                '76543210',
                'Cerrar ficha',
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
