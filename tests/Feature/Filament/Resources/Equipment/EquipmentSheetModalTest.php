<?php

namespace Tests\Feature\Filament\Resources\Equipment;

use App\Filament\Resources\Equipment\Pages\EditEquipment;
use App\Filament\Resources\Equipment\Pages\ListEquipments;
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
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class EquipmentSheetModalTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un usuario con View_Equipment puede abrir la ficha modal del equipo y consultar
     * sus datos técnicos, préstamo activo, observaciones e incidencias con evidencia privada.
     */
    public function test_a_user_with_view_permission_can_open_the_equipment_sheet_modal(): void
    {
        $equipmentDisk = (string) config('filament.default_filesystem_disk', 'local');
        $incidentDisk = EquipmentIncident::photoDisk();
        Storage::fake($equipmentDisk);
        if ($incidentDisk !== $equipmentDisk) {
            Storage::fake($incidentDisk);
        }

        Storage::disk($equipmentDisk)->put(
            'equipment/photos/thinkpad-t14.jpg',
            UploadedFile::fake()->image('thinkpad-t14.jpg', 240, 180)->getContent(),
        );
        Storage::disk($incidentDisk)->put(
            'borrowings/incidents/laptop-pantalla.jpg',
            UploadedFile::fake()->image('laptop-pantalla.jpg', 200, 150)->getContent(),
        );

        $profile = Profile::factory()->create([
            'dni' => '75112233',
            'first_name' => 'Carlos',
            'last_name' => 'Mendoza',
            'maternal_last_name' => 'Paredes',
        ]);

        $activeEquipment = Equipment::factory()
            ->ofType('Laptop')
            ->withStatus('Prestado')
            ->withPhoto('equipment/photos/thinkpad-t14.jpg')
            ->create([
                'patrimonial_code' => 'UCH-LP-045',
                'brand' => 'Lenovo',
                'model' => 'ThinkPad T14',
                'serial_number' => 'SN-T14-9988',
                'observations' => 'Incluye cargador original de 65W.',
            ]);

        $borrowing = Borrowing::factory()
            ->for($activeEquipment)
            ->for($profile)
            ->create([
                'student_dni' => $profile->dni,
                'status' => Borrowing::STATUS_IN_PROGRESS,
            ]);

        EquipmentIncident::factory()
            ->for($activeEquipment)
            ->for($borrowing)
            ->withPhoto('borrowings/incidents/laptop-pantalla.jpg')
            ->create([
                'description' => 'Rayadura superficial en tapa superior.',
            ]);

        $cleanEquipment = Equipment::factory()->ofType('Periférico')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-PER-099',
            'brand' => 'Logitech',
            'model' => 'MX Keys',
            'serial_number' => 'SN-MX-0099',
            'observations' => null,
            'photo' => null,
        ]);

        $this->signInWithPermissions('ViewAny_Equipment', 'View_Equipment');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->mountAction(TestAction::make(ViewAction::class)->table($activeEquipment))
            ->assertActionMounted(TestAction::make(ViewAction::class)->table($activeEquipment))
            ->assertMountedActionModalSee([
                'Ficha del equipo',
                'INVENTARIO UCH',
                'Lenovo ThinkPad T14',
                'Prestado',
                'UCH-LP-045',
                'SN-T14-9988',
                'Laptop',
                'Carlos Mendoza Paredes (En curso)',
                'Incluye cargador original de 65W.',
                'INCIDENCIAS REPORTADAS',
                'Carlos Mendoza Paredes — DNI 75112233',
                'Rayadura superficial en tapa superior.',
                'Cerrar ficha',
            ])
            ->assertMountedActionModalSeeHtml([
                'equipment-sheet-photo',
                'equipment/photos/thinkpad-t14.jpg?expiration=',
                'lightbox-thumb-btn',
                'x-teleport="body"',
                'x-trap="open"',
                'borrowings/incidents/laptop-pantalla.jpg?expiration=',
            ])
            ->assertMountedActionModalDontSeeHtml('equipment-sheet-icon');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->mountAction(TestAction::make(ViewAction::class)->table($cleanEquipment))
            ->assertActionMounted(TestAction::make(ViewAction::class)->table($cleanEquipment))
            ->assertMountedActionModalSee([
                'Logitech MX Keys',
                'Disponible',
                'UCH-PER-099',
                'SN-MX-0099',
                'Sin préstamo activo',
                'Sin observaciones registradas.',
                'Cerrar ficha',
            ])
            ->assertMountedActionModalSeeHtml('equipment-sheet-icon')
            ->assertMountedActionModalDontSeeHtml('equipment-sheet-photo')
            ->assertMountedActionModalDontSee('INCIDENCIAS REPORTADAS');
    }

    /**
     * Un usuario con View_Equipment y Update_Equipment también puede abrir la ficha desde
     * la cabecera de la página de edición.
     */
    public function test_edit_equipment_header_opens_the_equipment_sheet_modal(): void
    {
        $equipment = Equipment::factory()->ofType('Computadora')->withStatus('Disponible')->create([
            'patrimonial_code' => 'UCH-PC-088',
            'brand' => 'Dell',
            'model' => 'OptiPlex 7090',
            'serial_number' => 'SN-OP-7090',
        ]);
        $this->signInWithPermissions('ViewAny_Equipment', 'View_Equipment', 'Update_Equipment');

        Livewire::test(EditEquipment::class, ['record' => $equipment->getRouteKey()])
            ->mountAction(ViewAction::class)
            ->assertActionMounted(ViewAction::class)
            ->assertMountedActionModalSee([
                'Ficha del equipo',
                'Dell OptiPlex 7090',
                'UCH-PC-088',
                'SN-OP-7090',
                'Cerrar ficha',
            ]);
    }

    /**
     * Un usuario sin View_Equipment no debe ver la acción para abrir la ficha del equipo.
     */
    public function test_a_user_without_view_permission_cannot_see_the_equipment_sheet_action(): void
    {
        $equipment = Equipment::factory()->create();
        $this->signInWithPermissions('ViewAny_Equipment');

        Livewire::test(ListEquipments::class)
            ->loadTable()
            ->assertActionHidden(TestAction::make(ViewAction::class)->table($equipment));
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
