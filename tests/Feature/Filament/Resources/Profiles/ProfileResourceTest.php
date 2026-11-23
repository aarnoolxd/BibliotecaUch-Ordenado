<?php

namespace Tests\Feature\Filament\Resources\Profiles;

use App\Filament\Resources\Profiles\Pages\CreateProfile;
use App\Filament\Resources\Profiles\Pages\EditProfile;
use App\Filament\Resources\Profiles\Pages\ListProfiles;
use App\Filament\Resources\Profiles\ProfileResource;
use App\Models\Permission;
use App\Models\Profile;
use App\Models\Role;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProfileResourceTest extends TestCase
{
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    /**
     * Un visitante sin sesión que intenta abrir el listado de perfiles debe ser
     * redirigido al formulario de inicio de sesión del panel.
     */
    public function test_guest_is_redirected_to_login_when_visiting_profiles_index(): void
    {
        $this->get(ProfileResource::getUrl('index'))
            ->assertRedirectToRoute('filament.admin.auth.login');
    }

    /**
     * Un usuario con ViewAny_Profile puede abrir el listado de perfiles. La página debe
     * responder con HTTP 200 y mostrar los perfiles con cuenta vinculada y sin cuenta.
     */
    public function test_a_user_with_list_permission_can_read_the_profiles_table(): void
    {
        $linkedUser = User::factory()->create([
            'name' => 'Maria Lopez',
            'email' => 'maria.lopez@uch.pe',
        ]);
        $linkedProfile = Profile::factory()->for($linkedUser)->create([
            'dni' => '71234567',
            'first_name' => 'Maria Elena',
            'last_name' => 'Lopez Diaz',
            'phone' => '987654321',
        ]);
        $unlinkedProfile = Profile::factory()->create([
            'dni' => '72345678',
            'first_name' => 'Carlos',
            'last_name' => 'Quispe',
        ]);
        $this->signInWithPermissions('ViewAny_Profile');

        $this->get(ProfileResource::getUrl('index'))
            ->assertOk()
            ->assertSeeText($linkedProfile->dni)
            ->assertSeeText($linkedProfile->first_name)
            ->assertSeeText($linkedProfile->last_name)
            ->assertSeeText($linkedUser->email)
            ->assertSeeText($linkedProfile->phone)
            ->assertSeeText($unlinkedProfile->dni)
            ->assertSeeText('Sin cuenta vinculada');
    }

    /**
     * Un usuario con acceso al panel sin ViewAny_Profile recibe HTTP 403 al abrir el listado.
     */
    public function test_a_panel_user_without_list_permission_is_forbidden_from_listing_profiles(): void
    {
        $this->signInWithPermissions();

        $this->get(ProfileResource::getUrl('index'))->assertForbidden();
    }

    /**
     * Un usuario que solo tiene ViewAny_Profile recibe HTTP 403 al intentar abrir directamente
     * los formularios de creación o edición de perfiles.
     */
    public function test_a_user_without_create_or_update_permission_is_forbidden_from_create_and_edit_forms(): void
    {
        $profile = Profile::factory()->create();
        $this->signInWithPermissions('ViewAny_Profile');

        $this->get(ProfileResource::getUrl('create'))->assertForbidden();
        $this->get(ProfileResource::getUrl('edit', ['record' => $profile]))->assertForbidden();
    }

    /**
     * @return array<string, array{ability: string, permission: string, requiresRecord: bool}>
     */
    public static function policyPermissionsProvider(): array
    {
        return [
            'viewAny' => ['ability' => 'viewAny', 'permission' => 'ViewAny_Profile', 'requiresRecord' => false],
            'view' => ['ability' => 'view', 'permission' => 'View_Profile', 'requiresRecord' => true],
            'create' => ['ability' => 'create', 'permission' => 'Create_Profile', 'requiresRecord' => false],
            'update' => ['ability' => 'update', 'permission' => 'Update_Profile', 'requiresRecord' => true],
            'delete' => ['ability' => 'delete', 'permission' => 'Delete_Profile', 'requiresRecord' => true],
        ];
    }

    /**
     * ProfilePolicy debe denegar cada operación cuando falta su permiso exacto y autorizarla
     * únicamente cuando el usuario cuenta con dicho permiso.
     */
    #[DataProvider('policyPermissionsProvider')]
    public function test_profile_policy_authorizes_each_ability_based_on_its_matching_permission(
        string $ability,
        string $permission,
        bool $requiresRecord,
    ): void {
        $profile = Profile::factory()->create();
        $subject = $requiresRecord ? $profile : Profile::class;
        $unauthorizedUser = $this->signInWithPermissions();

        $this->assertTrue(Gate::forUser($unauthorizedUser)->denies($ability, $subject));

        $authorizedUser = $this->signInWithPermissions($permission);

        $this->assertTrue(Gate::forUser($authorizedUser)->allows($ability, $subject));
    }

    /**
     * La tabla de perfiles permite buscar por DNI, nombres, apellidos, correo de cuenta
     * vinculada y teléfono, filtrando los demás registros.
     */
    public function test_profiles_table_can_search_by_dni_names_linked_email_and_phone(): void
    {
        $linkedUser = User::factory()->create(['email' => 'buscar.perfil@uch.pe']);
        $matchingProfile = Profile::factory()->for($linkedUser)->create([
            'dni' => '70112233',
            'first_name' => 'Valeria',
            'last_name' => 'Mendoza',
            'phone' => '991122334',
        ]);
        $otherProfile = Profile::factory()->create([
            'dni' => '79887766',
            'first_name' => 'Diego',
            'last_name' => 'Salazar',
            'phone' => '955443322',
        ]);
        $this->signInWithPermissions('ViewAny_Profile');

        $table = Livewire::test(ListProfiles::class);

        foreach (['70112233', 'Valeria', 'Mendoza', 'buscar.perfil@uch.pe', '991122334'] as $term) {
            $table
                ->searchTable($term)
                ->assertCanSeeTableRecords([$matchingProfile])
                ->assertCanNotSeeTableRecords([$otherProfile]);
        }
    }

    /**
     * La tabla de perfiles permite ordenar ascendente y descendentemente por DNI y nombres.
     */
    public function test_profiles_table_can_sort_by_dni_and_first_name(): void
    {
        $first = Profile::factory()->create([
            'dni' => '70000001',
            'first_name' => 'Bruno',
        ]);
        $second = Profile::factory()->create([
            'dni' => '70000002',
            'first_name' => 'Andrea',
        ]);
        $this->signInWithPermissions('ViewAny_Profile');

        Livewire::test(ListProfiles::class)
            ->sortTable('dni')
            ->assertCanSeeTableRecords([$first, $second], inOrder: true)
            ->sortTable('dni', 'desc')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true)
            ->sortTable('first_name')
            ->assertCanSeeTableRecords([$second, $first], inOrder: true);
    }

    /**
     * Un usuario con Create_Profile puede registrar un perfil vinculado a una cuenta de usuario
     * incluyendo fotografía. El archivo debe almacenarse en el disco configurado, persistirse
     * en base de datos, notificarse y redirigir al listado.
     */
    public function test_a_user_with_create_permission_can_create_a_profile_linked_to_a_user_with_photo(): void
    {
        $disk = config('filament.default_filesystem_disk');
        Storage::fake($disk);

        $linkedUser = User::factory()->create(['name' => 'Juan Sedano']);
        $photo = UploadedFile::fake()->image('perfil.jpg', 300, 300);
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        Livewire::test(CreateProfile::class)
            ->fillForm([
                'user_id' => $linkedUser->getKey(),
                'dni' => '71234567',
                'first_name' => 'Juan Alberto',
                'last_name' => 'Sedano Quispe',
                'phone' => '987654321',
                'address' => 'Av. Universitaria 1234, Los Olivos',
                'photo' => $photo,
            ])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Metadatos personales del perfil registrados exitosamente')
            ->assertRedirect(ProfileResource::getUrl('index'));

        $createdProfile = Profile::query()->where('dni', '71234567')->firstOrFail();

        $this->assertSame($linkedUser->getKey(), $createdProfile->user_id);
        $this->assertSame('Juan Alberto', $createdProfile->first_name);
        $this->assertSame('Sedano Quispe', $createdProfile->last_name);
        $this->assertSame('987654321', $createdProfile->phone);
        $this->assertSame('Av. Universitaria 1234, Los Olivos', $createdProfile->address);
        $this->assertNotNull($createdProfile->photo);
        $this->assertStringStartsWith('profiles/photos/', $createdProfile->photo);
        Storage::disk($disk)->assertExists($createdProfile->photo);
    }

    /**
     * Se pueden crear múltiples perfiles sin cuenta de usuario vinculada ni campos opcionales,
     * respetando la restricción única anulable sobre user_id.
     */
    public function test_multiple_profiles_can_be_created_without_a_linked_user_or_optional_fields(): void
    {
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        foreach (['71111111' => 'Lucia', '72222222' => 'Mateo'] as $dni => $firstName) {
            Livewire::test(CreateProfile::class)
                ->fillForm([
                    'user_id' => null,
                    'dni' => $dni,
                    'first_name' => $firstName,
                    'last_name' => 'Rojas Paredes',
                    'phone' => null,
                    'address' => null,
                ])
                ->call('create')
                ->assertHasNoFormErrors()
                ->assertNotified('Metadatos personales del perfil registrados exitosamente');
        }

        $this->assertDatabaseCount('profiles', 2);
        $this->assertDatabaseHas('profiles', [
            'dni' => '71111111',
            'user_id' => null,
            'phone' => null,
            'address' => null,
        ]);
        $this->assertDatabaseHas('profiles', [
            'dni' => '72222222',
            'user_id' => null,
            'phone' => null,
            'address' => null,
        ]);
    }

    /**
     * Un usuario con Update_Profile puede editar un perfil conservando su propio DNI y cuenta
     * vinculada (ignoreRecord), actualizando el resto de metadatos con notificación y redirección.
     */
    public function test_a_user_with_update_permission_can_edit_a_profile_keeping_its_own_dni_and_linked_user(): void
    {
        $linkedUser = User::factory()->create();
        $profile = Profile::factory()->for($linkedUser)->create([
            'dni' => '73456789',
            'first_name' => 'Ana',
            'last_name' => 'Torres',
            'phone' => '911223344',
            'address' => 'Jr. Lima 100',
        ]);
        $this->signInWithPermissions('ViewAny_Profile', 'Update_Profile');

        Livewire::test(EditProfile::class, ['record' => $profile->getRouteKey()])
            ->fillForm([
                'user_id' => $linkedUser->getKey(),
                'dni' => '73456789',
                'first_name' => 'Ana Maria',
                'last_name' => 'Torres Quispe',
                'phone' => '999888777',
                'address' => 'Av. Arequipa 500',
            ])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified('Metadatos del perfil actualizados exitosamente')
            ->assertRedirect(ProfileResource::getUrl('index'));

        $profile->refresh();

        $this->assertSame($linkedUser->getKey(), $profile->user_id);
        $this->assertSame('73456789', $profile->dni);
        $this->assertSame('Ana Maria', $profile->first_name);
        $this->assertSame('Torres Quispe', $profile->last_name);
        $this->assertSame('999888777', $profile->phone);
        $this->assertSame('Av. Arequipa 500', $profile->address);
    }

    /**
     * Al editar un perfil, no debe permitirse tomar el DNI ni la cuenta de usuario ya
     * asignados a otro perfil existente.
     */
    public function test_editing_a_profile_rejects_dni_or_user_belonging_to_another_profile(): void
    {
        $otherUser = User::factory()->create();
        Profile::factory()->for($otherUser)->create(['dni' => '74444444']);
        $profile = Profile::factory()->create([
            'dni' => '75555555',
            'first_name' => 'Pedro',
            'last_name' => 'Campos',
        ]);
        $this->signInWithPermissions('ViewAny_Profile', 'Update_Profile');

        Livewire::test(EditProfile::class, ['record' => $profile->getRouteKey()])
            ->fillForm([
                'user_id' => $otherUser->getKey(),
                'dni' => '74444444',
                'first_name' => 'Pedro Modificado',
                'last_name' => 'Campos',
            ])
            ->call('save')
            ->assertHasFormErrors([
                'user_id' => 'La cuenta de usuario seleccionada ya tiene un perfil vinculado.',
                'dni' => 'Este número de DNI ya se encuentra registrado.',
            ])
            ->assertNotNotified();

        $profile->refresh();

        $this->assertNull($profile->user_id);
        $this->assertSame('75555555', $profile->dni);
        $this->assertSame('Pedro', $profile->first_name);
    }

    /**
     * Un usuario con Delete_Profile puede eliminar un perfil desde la tabla sin eliminar
     * la cuenta de usuario vinculada.
     */
    public function test_a_user_with_delete_permission_can_delete_a_profile_from_the_table(): void
    {
        $linkedUser = User::factory()->create();
        $profile = Profile::factory()->for($linkedUser)->create();
        $this->signInWithPermissions('ViewAny_Profile', 'Delete_Profile');

        Livewire::test(ListProfiles::class)
            ->callAction(TestAction::make(DeleteAction::class)->table($profile))
            ->assertNotified();

        $this->assertModelMissing($profile);
        $this->assertModelExists($linkedUser);
    }

    /**
     * Un usuario sin Delete_Profile no puede ver ni ejecutar el borrado desde la tabla.
     */
    public function test_a_user_without_delete_permission_cannot_delete_a_profile_from_the_table(): void
    {
        $profile = Profile::factory()->create();
        $this->signInWithPermissions('ViewAny_Profile');
        $action = TestAction::make(DeleteAction::class)->table($profile);

        Livewire::test(ListProfiles::class)
            ->mountAction($action)
            ->call('callMountedAction')
            ->assertActionHidden($action);

        $this->assertModelExists($profile);
    }

    /**
     * Si se elimina una cuenta de usuario vinculada a un perfil, la restricción nullOnDelete
     * conserva el perfil desvinculándolo (user_id = null) y mantiene íntegra la relación.
     */
    public function test_deleting_a_linked_user_unlinks_the_profile_instead_of_deleting_it(): void
    {
        $user = User::factory()->create();
        $profile = Profile::factory()->for($user)->create();

        $this->assertTrue($user->profile->is($profile));
        $this->assertTrue($profile->user->is($user));

        $user->delete();

        $this->assertModelMissing($user);
        $this->assertModelExists($profile);
        $this->assertNull($profile->fresh()->user_id);
    }

    /**
     * Al crear un perfil con campos obligatorios vacíos, deben mostrarse los mensajes
     * personalizados sin crear registros ni enviar notificaciones.
     */
    public function test_creating_a_profile_rejects_empty_required_fields(): void
    {
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        Livewire::test(CreateProfile::class)
            ->fillForm([
                'dni' => '',
                'first_name' => '',
                'last_name' => '',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'dni' => 'El número de DNI es obligatorio.',
                'first_name' => 'Los nombres son obligatorios.',
                'last_name' => 'Los apellidos son obligatorios.',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('profiles', 0);
    }

    /**
     * @return array<string, array{overrides: array<string, mixed>, field: string, expectedMessage: string}>
     */
    public static function invalidProfileFieldValuesProvider(): array
    {
        return [
            'dni menor a 8 digitos' => [
                'overrides' => ['dni' => '1234567'],
                'field' => 'dni',
                'expectedMessage' => 'El DNI debe tener exactamente 8 dígitos.',
            ],
            'dni mayor a 8 digitos' => [
                'overrides' => ['dni' => '123456789'],
                'field' => 'dni',
                'expectedMessage' => 'El DNI no puede superar los 8 dígitos.',
            ],
            'dni no numerico de 8 caracteres' => [
                'overrides' => ['dni' => '1234567A'],
                'field' => 'dni',
                'expectedMessage' => 'El DNI debe contener exactamente 8 dígitos numéricos.',
            ],
            'nombres demasiado cortos' => [
                'overrides' => ['first_name' => 'A'],
                'field' => 'first_name',
                'expectedMessage' => 'Los nombres deben tener al menos 2 caracteres.',
            ],
            'nombres demasiado largos' => [
                'overrides' => ['first_name' => str_repeat('a', 256)],
                'field' => 'first_name',
                'expectedMessage' => 'Los nombres no pueden superar los 255 caracteres.',
            ],
            'apellidos demasiado cortos' => [
                'overrides' => ['last_name' => 'B'],
                'field' => 'last_name',
                'expectedMessage' => 'Los apellidos deben tener al menos 2 caracteres.',
            ],
            'apellidos demasiado largos' => [
                'overrides' => ['last_name' => str_repeat('b', 256)],
                'field' => 'last_name',
                'expectedMessage' => 'Los apellidos no pueden superar los 255 caracteres.',
            ],
            'telefono demasiado largo' => [
                'overrides' => ['phone' => str_repeat('9', 21)],
                'field' => 'phone',
                'expectedMessage' => 'El número telefónico no puede superar los 20 caracteres.',
            ],
            'direccion demasiado larga' => [
                'overrides' => ['address' => str_repeat('c', 256)],
                'field' => 'address',
                'expectedMessage' => 'La dirección no puede superar los 255 caracteres.',
            ],
        ];
    }

    /**
     * Cada regla de validación personalizada del formulario debe rechazar valores fuera
     * de contrato con el mensaje visible esperado y sin modificar la base de datos.
     *
     * @param  array<string, mixed>  $overrides
     */
    #[DataProvider('invalidProfileFieldValuesProvider')]
    public function test_creating_a_profile_rejects_invalid_field_values(
        array $overrides,
        string $field,
        string $expectedMessage,
    ): void {
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        Livewire::test(CreateProfile::class)
            ->fillForm(array_merge([
                'dni' => '71234567',
                'first_name' => 'Juan Alberto',
                'last_name' => 'Sedano Quispe',
                'phone' => '987654321',
                'address' => 'Av. Universitaria 1234',
            ], $overrides))
            ->call('create')
            ->assertHasFormErrors([$field => $expectedMessage])
            ->assertNotNotified();

        $this->assertDatabaseCount('profiles', 0);
    }

    /**
     * El formulario debe rechazar tanto un DNI duplicado como una cuenta de usuario que
     * ya tenga un perfil vinculado, mostrando los mensajes personalizados.
     */
    public function test_creating_a_profile_rejects_duplicate_dni_and_already_linked_user(): void
    {
        $existingUser = User::factory()->create();
        Profile::factory()->for($existingUser)->create(['dni' => '76666666']);
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        Livewire::test(CreateProfile::class)
            ->fillForm([
                'user_id' => $existingUser->getKey(),
                'dni' => '76666666',
                'first_name' => 'Otra Persona',
                'last_name' => 'Mismo Dni',
            ])
            ->call('create')
            ->assertHasFormErrors([
                'user_id' => 'La cuenta de usuario seleccionada ya tiene un perfil vinculado.',
                'dni' => 'Este número de DNI ya se encuentra registrado.',
            ])
            ->assertNotNotified();

        $this->assertDatabaseCount('profiles', 1);
    }

    /**
     * La fotografía del perfil debe rechazar archivos que superen los 2 MB o que no tengan
     * formato JPG/PNG, sin persistir el perfil ni notificar éxito.
     */
    public function test_creating_a_profile_rejects_oversized_or_unsupported_photo_files(): void
    {
        $disk = config('filament.default_filesystem_disk');
        Storage::fake($disk);
        $this->signInWithPermissions('ViewAny_Profile', 'Create_Profile');

        Livewire::test(CreateProfile::class)
            ->fillForm([
                'dni' => '77777771',
                'first_name' => 'Rosa',
                'last_name' => 'Gomez',
                'photo' => UploadedFile::fake()->image('grande.jpg')->size(2049),
            ])
            ->call('create')
            ->assertHasFormErrors([
                'photo' => 'La fotografía no debe superar los 2 MB (2048 KB).',
            ])
            ->assertNotNotified();

        Livewire::test(CreateProfile::class)
            ->fillForm([
                'dni' => '77777772',
                'first_name' => 'Rosa',
                'last_name' => 'Gomez',
                'photo' => UploadedFile::fake()->image('animado.gif'),
            ])
            ->call('create')
            ->assertHasFormErrors(['photo'])
            ->assertNotNotified();

        $this->assertDatabaseCount('profiles', 0);
    }

    /**
     * Autentica a un ayudante, un rol que ya puede entrar al panel. Cada prueba decide
     * qué permisos adicionales recibe ese rol para operar sobre el recurso.
     */
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
