<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['user_id', 'dni', 'first_name', 'last_name', 'phone', 'address', 'photo'])]
class Profile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'dni',
        'first_name',
        'last_name',
        'phone',
        'address',
        'photo',
    ];

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
        return trim("{$this->first_name} {$this->last_name}");
    }
}
