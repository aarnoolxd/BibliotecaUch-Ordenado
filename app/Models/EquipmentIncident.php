<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['equipment_id', 'borrowing_id', 'description', 'photo'])]
class EquipmentIncident extends Model
{
    use HasFactory;

    protected $table = 'equipment_incidents';

    protected $fillable = [
        'equipment_id',
        'borrowing_id',
        'description',
        'photo',
    ];

    /**
     * Disco privado garantizado para almacenar fotografías de evidencia de incidencias.
     * Si el disco por defecto de Filament está configurado como public, usa local privado.
     */
    public static function photoDisk(): string
    {
        $configuredDisk = (string) config('filament.default_filesystem_disk', 'local');

        return $configuredDisk === 'public' ? 'local' : $configuredDisk;
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
     * Equipo en el que se reportó el daño.
     */
    public function equipment(): BelongsTo
    {
        return $this->belongsTo(Equipment::class, 'equipment_id');
    }

    /**
     * Préstamo durante cuya devolución se reportó el daño.
     */
    public function borrowing(): BelongsTo
    {
        return $this->belongsTo(Borrowing::class, 'borrowing_id');
    }
}
