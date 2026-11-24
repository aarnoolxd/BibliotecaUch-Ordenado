<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasAvatar;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Traits\HasRoles;
use Throwable;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAvatar
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    /**
     * Ejecuta el borrado del usuario dentro de una transacción con bloqueo de fila
     * antes de disparar los eventos Eloquent (como el detach de roles de Spatie),
     * impidiendo eliminar cuentas super-admin o cuentas con préstamos activos.
     */
    public function delete(): ?bool
    {
        return DB::transaction(function (): ?bool {
            $lockedUser = self::query()
                ->lockForUpdate()
                ->find($this->getKey());

            if (
                $lockedUser === null
                || $lockedUser->hasRole(Role::SUPER_ADMIN)
                || $lockedUser->hasActiveBorrowing()
            ) {
                return false;
            }

            return parent::delete();
        });
    }

    /**
     * Relación uno a uno con el perfil de metadatos personales.
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Préstamos registrados por esta cuenta de operador.
     */
    public function registeredBorrowings(): HasMany
    {
        return $this->hasMany(Borrowing::class, 'registered_by');
    }

    /**
     * Comprueba si la cuenta tiene préstamos activos registrados o pendientes en su perfil vinculado.
     */
    public function hasActiveBorrowing(): bool
    {
        if ($this->registeredBorrowings()->whereIn('status', Borrowing::ACTIVE_STATUSES)->exists()) {
            return true;
        }

        return $this->profile()
            ->whereHas('borrowings', fn ($query) => $query->whereIn('status', Borrowing::ACTIVE_STATUSES))
            ->exists();
    }

    /**
     * Obtiene la URL temporal firmada (o pública si el disco configurado es public)
     * del avatar de Filament a partir de la fotografía del perfil.
     */
    public function getFilamentAvatarUrl(): ?string
    {
        $photo = $this->profile?->photo;

        if (blank($photo)) {
            return null;
        }

        $diskName = config('filament.default_filesystem_disk');

        /** @var FilesystemAdapter $disk */
        $disk = Storage::disk($diskName);

        if ($diskName !== 'public') {
            try {
                return $disk->temporaryUrl(
                    $photo,
                    now()->addMinutes((int) config('filament.temporary_file_url_expiry_minutes', 30))->endOfHour(),
                );
            } catch (Throwable) {
                // El driver configurado no soporta URLs temporales firmadas.
            }
        }

        return $disk->url($photo);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->hasRole(Role::PANEL_ROLES);
    }
}
