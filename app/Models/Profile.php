<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
}
