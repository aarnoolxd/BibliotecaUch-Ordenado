<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

#[Fillable(['equipment_id', 'profile_id', 'student_dni', 'borrowing_date', 'due_date', 'return_date', 'status', 'observations', 'registered_by'])]
class Borrowing extends Model
{
    use HasFactory;

    /**
     * Estados en los que el equipo continúa bajo préstamo abierto.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = ['En curso', 'Vencido'];

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

    protected static function booted(): void
    {
        static::deleted(function (Borrowing $borrowing): void {
            if (! in_array($borrowing->status, self::ACTIVE_STATUSES, true)) {
                return;
            }

            DB::transaction(function () use ($borrowing): void {
                self::releaseEquipmentIfNoActiveBorrowings((int) $borrowing->equipment_id);
            });
        });
    }

    /**
     * Calcula la fecha límite de devolución según la categoría del equipo.
     */
    public static function calculateDueDate(string $equipmentType, Carbon $borrowingDate): Carbon
    {
        return match ($equipmentType) {
            'Laptop' => $borrowingDate->copy()->addDays(2),
            'Computadora' => $borrowingDate->copy()->addHours(6),
            'Periférico' => $borrowingDate->copy()->addDay(),
            default => $borrowingDate->copy()->addDay(),
        };
    }

    /**
     * Devuelve el equipo a estado Disponible si está Prestado y no tiene
     * ningún otro préstamo activo (En curso o Vencido).
     */
    public static function releaseEquipmentIfNoActiveBorrowings(int $equipmentId, ?int $ignoreBorrowingId = null): void
    {
        $equipment = Equipment::query()
            ->lockForUpdate()
            ->find($equipmentId);

        if ($equipment === null || $equipment->status !== 'Prestado') {
            return;
        }

        $hasOtherActiveBorrowing = self::query()
            ->where('equipment_id', $equipment->getKey())
            ->when(
                $ignoreBorrowingId !== null,
                fn ($query) => $query->whereKeyNot($ignoreBorrowingId),
            )
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();

        if (! $hasOtherActiveBorrowing) {
            $equipment->update(['status' => 'Disponible']);
        }
    }

    protected function casts(): array
    {
        return [
            'borrowing_date' => 'datetime',
            'due_date' => 'datetime',
            'return_date' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
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
