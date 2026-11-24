<?php

namespace App\Filament\Resources\Profiles\Schemas;

use App\Models\Profile;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ProfileForm
{
    /**
     * Limpia los campos académicos exclusivos de estudiantes cuando el perfil pertenece
     * a otro tipo de usuario.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function normalizeAcademicData(array $data): array
    {
        if (($data['user_type'] ?? null) !== Profile::USER_TYPE_STUDENT) {
            $data['program'] = null;
            $data['cycle'] = null;
            $data['modality'] = null;
            $data['shift'] = null;
        }

        return $data;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('user_id')
                    ->label('Cuenta de Usuario')
                    ->placeholder('Seleccione una cuenta para vincular')
                    ->helperText('Cuenta con la que la persona ingresa al sistema. Déjela vacía si no tiene una cuenta.')
                    ->relationship('user', 'name')
                    ->searchable()
                    ->preload()
                    ->nullable()
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'in' => 'Seleccione una cuenta de usuario válida.',
                        'unique' => 'La cuenta de usuario seleccionada ya tiene un perfil vinculado.',
                    ]),

                TextInput::make('student_code')
                    ->label('Código de estudiante')
                    ->placeholder('Ej: 25107002')
                    ->helperText('Código o identificador único del estudiante.')
                    ->required()
                    ->maxLength(50)
                    ->unique(ignoreRecord: true)
                    ->validationMessages([
                        'required' => 'El código de estudiante es obligatorio.',
                        'max' => 'El código no puede superar los 50 caracteres.',
                        'unique' => 'Este número de código ya se encuentra registrado.',
                    ]),

                TextInput::make('first_name')
                    ->label('Nombres')
                    ->placeholder('Ej: Juan Alberto')
                    ->helperText('Nombres completos de la persona, tal como aparecen en su DNI.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'Los nombres son obligatorios.',
                        'min' => 'Los nombres deben tener al menos 2 caracteres.',
                        'max' => 'Los nombres no pueden superar los 255 caracteres.',
                    ]),

                TextInput::make('last_name')
                    ->label('Apellido paterno')
                    ->placeholder('Ej: Diestra')
                    ->helperText('Primer apellido de la persona, tal como aparece en su DNI.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'El apellido paterno es obligatorio.',
                        'min' => 'El apellido paterno debe tener al menos 2 caracteres.',
                        'max' => 'El apellido paterno no puede superar los 255 caracteres.',
                    ]),

                TextInput::make('maternal_last_name')
                    ->label('Apellido materno')
                    ->placeholder('Ej: Espinoza')
                    ->helperText('Segundo apellido de la persona, tal como aparece en su DNI.')
                    ->required()
                    ->minLength(2)
                    ->maxLength(255)
                    ->validationMessages([
                        'required' => 'El apellido materno es obligatorio.',
                        'min' => 'El apellido materno debe tener al menos 2 caracteres.',
                        'max' => 'El apellido materno no puede superar los 255 caracteres.',
                    ]),

                Select::make('user_type')
                    ->label('Tipo de usuario')
                    ->placeholder('Seleccione un tipo de usuario')
                    ->helperText('Vínculo de la persona con la universidad.')
                    ->options(Profile::USER_TYPES)
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function (?string $state, Set $set): void {
                        if ($state !== Profile::USER_TYPE_STUDENT) {
                            $set('program', null);
                            $set('cycle', null);
                            $set('modality', null);
                            $set('shift', null);
                        }
                    })
                    ->required()
                    ->validationMessages([
                        'required' => 'El tipo de usuario es obligatorio.',
                        'in' => 'Seleccione un tipo de usuario de la lista.',
                    ]),

                Select::make('program')
                    ->label('Programa')
                    ->placeholder('Seleccione un programa')
                    ->helperText('Carrera o programa académico que cursa la persona en la universidad.')
                    ->options(Profile::PROGRAMS)
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('user_type') === Profile::USER_TYPE_STUDENT)
                    ->required(fn (Get $get): bool => $get('user_type') === Profile::USER_TYPE_STUDENT)
                    ->validationMessages([
                        'required' => 'El programa académico es obligatorio.',
                        'in' => 'Seleccione un programa académico de la lista.',
                    ]),

                Select::make('cycle')
                    ->label('Ciclo')
                    ->placeholder('Seleccione un ciclo')
                    ->helperText('Ciclo académico que cursa actualmente la persona.')
                    ->options(Profile::CYCLES)
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('user_type') === Profile::USER_TYPE_STUDENT)
                    ->required(fn (Get $get): bool => $get('user_type') === Profile::USER_TYPE_STUDENT)
                    ->validationMessages([
                        'required' => 'El ciclo es obligatorio.',
                        'in' => 'Seleccione un ciclo de la lista.',
                    ]),

                Select::make('modality')
                    ->label('Modalidad')
                    ->placeholder('Seleccione una modalidad')
                    ->options(Profile::MODALITIES)
                    ->native(false)
                    ->nullable()
                    ->visible(fn (Get $get): bool => $get('user_type') === Profile::USER_TYPE_STUDENT),

                Select::make('shift')
                    ->label('Turno')
                    ->placeholder('Seleccione un turno')
                    ->options(Profile::SHIFTS)
                    ->native(false)
                    ->nullable()
                    ->visible(fn (Get $get): bool => $get('user_type') === Profile::USER_TYPE_STUDENT),

                TextInput::make('phone')
                    ->label('Número de celular')
                    ->placeholder('Ej: 987654321')
                    ->helperText('Número de celular donde se puede contactar a la persona. Por ejemplo: 987654321.')
                    ->tel()
                    ->nullable()
                    ->regex('/^(?:\+51)?9[0-9]{8}$/')
                    ->maxLength(20)
                    ->validationMessages([
                        'regex' => 'Ingrese un celular válido de 9 dígitos que empiece con 9, con prefijo +51 opcional.',
                        'max' => 'El número telefónico no puede superar los 20 caracteres.',
                    ]),

                TextInput::make('address')
                    ->label('Dirección')
                    ->placeholder('Ej: Av. Universitaria 1234, Los Olivos')
                    ->helperText('Dirección donde vive la persona. Incluya calle o avenida, número y distrito.')
                    ->nullable()
                    ->maxLength(255)
                    ->validationMessages([
                        'max' => 'La dirección no puede superar los 255 caracteres.',
                    ]),

                FileUpload::make('photo')
                    ->label('Fotografía de Perfil')
                    ->helperText('Fotografía de la persona para identificarla en su ficha. Formatos permitidos: JPG o PNG (máx. 2 MB).')
                    ->nullable()
                    ->image()
                    ->avatar()
                    ->alignCenter()
                    ->columnSpanFull()
                    ->imageEditor()
                    ->acceptedFileTypes(['image/jpeg', 'image/png'])
                    ->maxSize(2048)
                    ->directory('profiles/photos')
                    ->validationMessages([
                        'image' => 'El archivo debe ser una imagen válida.',
                        'uploaded' => 'No se pudo subir la fotografía. Intente nuevamente.',
                        'max' => 'La fotografía no debe superar los 2 MB (2048 KB).',
                        'mimes' => 'La fotografía debe ser un archivo de formato JPG o PNG.',
                        'mimetypes' => 'La fotografía debe ser un archivo de formato JPG o PNG.',
                    ]),
            ]);
    }
}
