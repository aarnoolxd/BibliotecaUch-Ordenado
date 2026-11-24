<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable([
    'user_id',
    'dni',
    'first_name',
    'last_name',
    'maternal_last_name',
    'program',
    'cycle',
    'user_type',
    'phone',
    'address',
    'photo',
])]
class Profile extends Model
{
    use HasFactory;

    public const USER_TYPE_STUDENT = 'Estudiante';

    public const USER_TYPE_TEACHER = 'Docente';

    public const USER_TYPE_ADMINISTRATIVE = 'Administrativo';

    /**
     * Tipos de usuario permitidos para un perfil institucional.
     *
     * @var array<string, string>
     */
    public const USER_TYPES = [
        self::USER_TYPE_STUDENT => self::USER_TYPE_STUDENT,
        self::USER_TYPE_TEACHER => self::USER_TYPE_TEACHER,
        self::USER_TYPE_ADMINISTRATIVE => self::USER_TYPE_ADMINISTRATIVE,
    ];

    /**
     * Programas académicos disponibles para estudiantes.
     *
     * @var array<string, string>
     */
    public const PROGRAMS = [
        'Ingeniería de Sistemas e Informática' => 'Ingeniería de Sistemas e Informática',
        'Administración' => 'Administración',
        'Contabilidad' => 'Contabilidad',
    ];

    /**
     * Ciclos académicos disponibles para estudiantes.
     *
     * @var array<int, string>
     */
    public const CYCLES = [
        1 => 'Ciclo 1',
        2 => 'Ciclo 2',
        3 => 'Ciclo 3',
        4 => 'Ciclo 4',
        5 => 'Ciclo 5',
        6 => 'Ciclo 6',
        7 => 'Ciclo 7',
        8 => 'Ciclo 8',
        9 => 'Ciclo 9',
        10 => 'Ciclo 10',
    ];

    protected $fillable = [
        'user_id',
        'dni',
        'first_name',
        'last_name',
        'maternal_last_name',
        'program',
        'cycle',
        'user_type',
        'phone',
        'address',
        'photo',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Profile $profile): ?bool {
            $lockedProfile = self::query()
                ->lockForUpdate()
                ->find($profile->getKey());

            if ($lockedProfile === null || $lockedProfile->hasActiveBorrowing()) {
                return false;
            }

            return null;
        });

        static::updated(function (Profile $profile): void {
            if ($profile->wasChanged('dni')) {
                $profile->borrowings()->update(['student_dni' => $profile->dni]);
            }

            if ($profile->wasChanged('photo')) {
                $previousPhoto = $profile->getOriginal('photo');

                if (is_string($previousPhoto) && trim($previousPhoto) !== '') {
                    DB::afterCommit(static function () use ($previousPhoto): void {
                        self::deletePhotoIfUnreferenced($previousPhoto);
                    });
                }
            }
        });

        static::deleted(function (Profile $profile): void {
            $photo = $profile->photo;

            if (is_string($photo) && trim($photo) !== '') {
                DB::afterCommit(static function () use ($photo): void {
                    self::deletePhotoIfUnreferenced($photo);
                });
            }
        });
    }

    /**
     * Ejecuta el borrado del perfil dentro de una transacción con bloqueo de fila
     * para evitar eliminar un perfil que reciba un préstamo activo concurrentemente.
     */
    public function delete(): ?bool
    {
        return DB::transaction(fn (): ?bool => parent::delete());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cycle' => 'integer',
        ];
    }

    /**
     * Cuenta de usuario vinculada unívocamente a este perfil.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Préstamos asociados a este perfil.
     */
    public function borrowings(): HasMany
    {
        return $this->hasMany(Borrowing::class, 'profile_id');
    }

    /**
     * Determina si el perfil tiene al menos un préstamo activo (En curso o Vencido).
     */
    public function hasActiveBorrowing(): bool
    {
        return $this->borrowings()
            ->whereIn('status', Borrowing::ACTIVE_STATUSES)
            ->exists();
    }

    /**
     * Nombre completo consolidado del perfil.
     */
    public function getFullNameAttribute(): string
    {
        return collect([
            $this->first_name,
            $this->last_name,
            $this->maternal_last_name,
        ])
            ->map(fn (?string $part): string => trim((string) $part))
            ->filter(fn (string $part): bool => $part !== '')
            ->implode(' ');
    }

    /**
     * Elimina una fotografía de perfil del disco configurado solo si ningún otro
     * perfil registrado la referencia.
     */
    private static function deletePhotoIfUnreferenced(string $photoPath): void
    {
        try {
            $isStillReferenced = self::query()
                ->where('photo', $photoPath)
                ->exists();

            if ($isStillReferenced) {
                return;
            }

            $disk = (string) config('filament.default_filesystem_disk', 'local');
            Storage::disk($disk)->delete($photoPath);
        } catch (Throwable) {
            // Conserva el archivo si no es posible verificar o acceder al almacenamiento.
        }
    }
}
