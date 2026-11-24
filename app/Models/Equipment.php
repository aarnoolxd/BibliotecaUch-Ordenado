<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

#[Fillable(['patrimonial_code', 'type', 'brand', 'model', 'serial_number', 'status', 'observations', 'photo'])]
class Equipment extends Model
{
    use HasFactory;

    /**
     * Categorías que actúan como equipo principal en un préstamo.
     *
     * @var list<string>
     */
    public const PRIMARY_BORROWING_TYPES = ['Computadora', 'Laptop'];

    /**
     * Categorías que actúan como dispositivos adicionales (N:N) en un préstamo.
     *
     * @var list<string>
     */
    public const ADDITIONAL_BORROWING_TYPES = ['Periférico', 'Otro'];

    public const STATUS_DISCONTINUED = 'Baja';

    public const OBSERVATIONS_MAX_LENGTH = 500;

    public const DISCONTINUE_SUCCESS = 'discontinued';

    public const DISCONTINUE_ALREADY_DISCONTINUED = 'already_discontinued';

    public const DISCONTINUE_ACTIVE_BORROWING = 'has_active_borrowing';

    public const DISCONTINUE_MISSING = 'missing';

    protected $table = 'equipment';

    protected $fillable = [
        'patrimonial_code',
        'type',
        'brand',
        'model',
        'serial_number',
        'status',
        'observations',
        'photo',
    ];

    protected static function booted(): void
    {
        static::deleting(function (Equipment $equipment): ?bool {
            $lockedEquipment = self::query()
                ->lockForUpdate()
                ->find($equipment->getKey());

            if ($lockedEquipment === null || $lockedEquipment->hasActiveBorrowing()) {
                return false;
            }

            return null;
        });

        static::updated(function (Equipment $equipment): void {
            if ($equipment->wasChanged('photo')) {
                $previousPhoto = $equipment->getOriginal('photo');

                if (is_string($previousPhoto) && trim($previousPhoto) !== '') {
                    DB::afterCommit(static function () use ($previousPhoto): void {
                        self::deletePhotoIfUnreferenced($previousPhoto);
                    });
                }
            }
        });

        static::deleted(function (Equipment $equipment): void {
            $photo = $equipment->photo;

            if (is_string($photo) && trim($photo) !== '') {
                DB::afterCommit(static function () use ($photo): void {
                    self::deletePhotoIfUnreferenced($photo);
                });
            }
        });
    }

    /**
     * Ejecuta el borrado del equipo dentro de una única transacción junto con el bloqueo
     * de fila en deleting para impedir asignaciones concurrentes antes del DELETE.
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
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Préstamos en los que este equipo actúa como recurso principal.
     */
    public function borrowings(): HasMany
    {
        return $this->hasMany(Borrowing::class, 'equipment_id');
    }

    /**
     * Préstamos en los que este recurso actúa como dispositivo adicional.
     */
    public function additionalBorrowings(): BelongsToMany
    {
        return $this->belongsToMany(Borrowing::class, 'borrowing_equipment')
            ->withTimestamps();
    }

    /**
     * Incidencias por daño registradas sobre este equipo.
     */
    public function incidents(): HasMany
    {
        return $this->hasMany(EquipmentIncident::class, 'equipment_id');
    }

    /**
     * Devuelve el color semántico de Filament para cada estado operativo del equipo.
     */
    public static function statusColor(string $status): string
    {
        return match ($status) {
            'Disponible' => 'success',
            'Prestado' => 'warning',
            'Mantenimiento' => 'danger',
            default => 'gray',
        };
    }

    /**
     * Devuelve el préstamo activo más reciente en el que participa el equipo
     * (como equipo principal o dispositivo adicional).
     */
    public function activeBorrowing(): ?Borrowing
    {
        return $this->borrowings()
            ->with('profile')
            ->whereIn('status', Borrowing::ACTIVE_STATUSES)
            ->latest('borrowing_date')
            ->first()
            ?? $this->additionalBorrowings()
                ->with('profile')
                ->whereIn('borrowings.status', Borrowing::ACTIVE_STATUSES)
                ->latest('borrowings.borrowing_date')
                ->first();
    }

    /**
     * Comprueba si el equipo mantiene un préstamo activo (como principal o adicional).
     */
    public function hasActiveBorrowing(): bool
    {
        return $this->borrowings()->whereIn('status', Borrowing::ACTIVE_STATUSES)->exists()
            || $this->additionalBorrowings()->whereIn('borrowings.status', Borrowing::ACTIVE_STATUSES)->exists();
    }

    /**
     * Registra la baja técnica (estado Baja) del equipo dentro de una transacción con bloqueo
     * de fila, impidiendo descontinuar equipos con préstamos activos o duplicar la baja.
     */
    public function discontinue(string $reason): string
    {
        $cleanReason = trim($reason);

        return DB::transaction(function () use ($cleanReason): string {
            $lockedEquipment = self::query()
                ->lockForUpdate()
                ->find($this->getKey());

            if ($lockedEquipment === null) {
                return self::DISCONTINUE_MISSING;
            }

            if ($lockedEquipment->status === self::STATUS_DISCONTINUED) {
                return self::DISCONTINUE_ALREADY_DISCONTINUED;
            }

            if ($lockedEquipment->hasActiveBorrowing()) {
                return self::DISCONTINUE_ACTIVE_BORROWING;
            }

            $lockedEquipment->update([
                'status' => self::STATUS_DISCONTINUED,
                'observations' => $this->formatDiscontinuedObservations(
                    $lockedEquipment->observations,
                    $cleanReason,
                    now()->format('d/m/Y H:i'),
                ),
            ]);

            $this->setRawAttributes($lockedEquipment->getAttributes(), sync: true);

            return self::DISCONTINUE_SUCCESS;
        });
    }

    /**
     * Construye el texto de observaciones de la baja técnica garantizando que no supere
     * el límite máximo permitido por el formulario de edición.
     */
    private function formatDiscontinuedObservations(?string $existingObservations, string $cleanReason, string $timestamp): string
    {
        $prefix = '[BAJA TÉCNICA - '.$timestamp.']: ';
        $maxReasonLength = self::OBSERVATIONS_MAX_LENGTH - mb_strlen($prefix);
        $decommissionNote = $prefix.self::truncateText($cleanReason, $maxReasonLength);

        $trimmedExisting = trim((string) $existingObservations);

        if ($trimmedExisting === '') {
            return $decommissionNote;
        }

        $separator = ' | ';
        $availableForExisting = self::OBSERVATIONS_MAX_LENGTH - mb_strlen($decommissionNote) - mb_strlen($separator);

        if ($availableForExisting <= 3) {
            return $decommissionNote;
        }

        return self::truncateText($trimmedExisting, $availableForExisting).$separator.$decommissionNote;
    }

    private static function truncateText(string $text, int $maxLength): string
    {
        if ($maxLength <= 0) {
            return '';
        }

        if (mb_strlen($text) <= $maxLength) {
            return $text;
        }

        if ($maxLength <= 3) {
            return mb_substr($text, 0, $maxLength);
        }

        return rtrim(mb_substr($text, 0, $maxLength - 3)).'...';
    }

    /**
     * Elimina una fotografía de equipo del disco configurado solo si ningún otro
     * equipo registrado la referencia.
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
