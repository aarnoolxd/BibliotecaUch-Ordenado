<?php

namespace App\Models;

use Carbon\Carbon;
use Filament\Support\Colors\Color;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

#[Fillable(['equipment_id', 'profile_id', 'student_dni', 'borrowing_date', 'due_date', 'return_date', 'status', 'observations', 'registered_by'])]
class Borrowing extends Model
{
    use HasFactory;

    public const STATUS_IN_PROGRESS = 'En curso';

    public const STATUS_RETURNED = 'Devuelto';

    public const STATUS_OVERDUE = 'Vencido';

    /**
     * Estados en los que el préstamo continúa abierto.
     *
     * @var list<string>
     */
    public const ACTIVE_STATUSES = [self::STATUS_IN_PROGRESS, self::STATUS_OVERDUE];

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

    /**
     * IDs de equipos capturados antes del borrado en cascada de la pivote.
     *
     * @var list<int>
     */
    protected array $equipmentIdsPendingRelease = [];

    protected static function booted(): void
    {
        static::deleting(function (Borrowing $borrowing): void {
            if (! in_array($borrowing->status, self::ACTIVE_STATUSES, true)) {
                $borrowing->equipmentIdsPendingRelease = [];

                return;
            }

            $borrowing->equipmentIdsPendingRelease = $borrowing->allEquipmentIds();
        });

        static::deleted(function (Borrowing $borrowing): void {
            if ($borrowing->equipmentIdsPendingRelease === []) {
                return;
            }

            $equipmentIds = $borrowing->equipmentIdsPendingRelease;
            $borrowing->equipmentIdsPendingRelease = [];

            DB::transaction(function () use ($equipmentIds): void {
                foreach ($equipmentIds as $equipmentId) {
                    self::releaseEquipmentIfNoActiveBorrowings($equipmentId);
                }
            });
        });
    }

    /**
     * Color semántico unificado para badges de estado (tabla y ficha modal).
     *
     * @return string|array<int, string>
     */
    public static function statusColor(string $status): string|array
    {
        return match ($status) {
            self::STATUS_IN_PROGRESS => 'info',
            self::STATUS_RETURNED => Color::Emerald,
            self::STATUS_OVERDUE => 'danger',
            default => 'gray',
        };
    }

    /**
     * Marca como Vencido todos los préstamos En curso cuya fecha límite (due_date) ya expiró.
     */
    public static function markOverdueBorrowings(): int
    {
        return self::query()
            ->where('status', self::STATUS_IN_PROGRESS)
            ->whereNotNull('due_date')
            ->where('due_date', '<', now())
            ->update(['status' => self::STATUS_OVERDUE]);
    }

    /**
     * Calcula la fecha límite de devolución.
     * Solo las computadoras tienen plazo fijo (+2 horas); laptops, periféricos y otros no tienen límite.
     */
    public static function calculateDueDate(?string $equipmentType, Carbon $borrowingDate): ?Carbon
    {
        if ($equipmentType === 'Computadora') {
            return $borrowingDate->copy()->addHours(2);
        }

        return null;
    }

    /**
     * Devuelve el equipo a estado Disponible si está Prestado y no tiene
     * ningún otro préstamo activo (ni como equipo principal ni como dispositivo adicional).
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
            ->when(
                $ignoreBorrowingId !== null,
                fn (Builder $query): Builder => $query->whereKeyNot($ignoreBorrowingId),
            )
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->where(function (Builder $query) use ($equipment): void {
                $query
                    ->where('equipment_id', $equipment->getKey())
                    ->orWhereHas(
                        'additionalEquipment',
                        fn (Builder $additionalQuery): Builder => $additionalQuery->whereKey($equipment->getKey()),
                    );
            })
            ->exists();

        if (! $hasOtherActiveBorrowing) {
            $equipment->update(['status' => 'Disponible']);
        }
    }

    /**
     * Retorna todos los IDs de equipos asociados a este préstamo (principal + adicionales),
     * ordenados ascendentemente para bloqueos determinísticos.
     *
     * @return list<int>
     */
    public function allEquipmentIds(): array
    {
        $ids = $this->additionalEquipment()
            ->pluck('equipment.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($this->equipment_id !== null) {
            $ids[] = (int) $this->equipment_id;
        }

        $ids = array_values(array_unique($ids));
        sort($ids);

        return $ids;
    }

    /**
     * @return array<string, string>
     */
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
     * Equipo principal prestado (Computadora o Laptop), opcional.
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /**
     * Dispositivos adicionales (Periférico u Otro) asociados al préstamo.
     */
    public function additionalEquipment(): BelongsToMany
    {
        return $this->belongsToMany(Equipment::class, 'borrowing_equipment')
            ->withTimestamps();
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

    /**
     * Incidencias por daño registradas durante la devolución de este préstamo.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(EquipmentIncident::class, 'borrowing_id');
    }
}
