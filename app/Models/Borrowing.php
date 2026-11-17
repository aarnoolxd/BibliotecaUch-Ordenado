<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['equipment_id', 'profile_id', 'student_dni', 'borrowing_date', 'due_date', 'return_date', 'status', 'observations', 'registered_by'])]
class Borrowing extends Model
{
    use HasFactory;

    protected $table = 'borrowings';

    protected $fillable = [
        'equipment_id',
        'profile_id',
        'student_dni',
        'borrowing_date',
        'due_date',
        'return_date',
        'status',
        'observations',
        'registered_by',
    ];

    protected function casts(): array
    {
        return [
            'borrowing_date' => 'datetime',
            'due_date'       => 'datetime',
            'return_date'    => 'datetime',
            'created_at'     => 'datetime',
            'updated_at'     => 'datetime',
        ];
    }

    /**
     * Equipo o recurso prestado.
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /**
     * Perfil del estudiante vinculado mediante DNI.
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class, 'profile_id');
    }

    /**
     * Usuario que registró el préstamo.
     */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
