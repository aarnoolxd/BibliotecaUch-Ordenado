<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['patrimonial_code', 'type', 'brand', 'model', 'serial_number', 'status', 'observations'])]
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

    protected $table = 'equipment';

    protected $fillable = [
        'patrimonial_code',
        'type',
        'brand',
        'model',
        'serial_number',
        'status',
        'observations',
    ];

    protected static function booted(): void
    {
        static::deleting(fn (Equipment $equipment): bool => ! $equipment->hasActiveBorrowing());
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
     * Comprueba si el equipo mantiene un préstamo activo (como principal o adicional).
     */
    public function hasActiveBorrowing(): bool
    {
        return $this->borrowings()->whereIn('status', Borrowing::ACTIVE_STATUSES)->exists()
            || $this->additionalBorrowings()->whereIn('borrowings.status', Borrowing::ACTIVE_STATUSES)->exists();
    }
}
