<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Filament\Support\Enums\Width;
use Override;

class CustomLogin extends BaseLogin
{
    public function getMaxWidth(): Width
    {
        return Width::Small;

    }

    #[Override]
    public function getHeading(): string
    {
        return '';
    }

    #[Override]
    public function getExtraBodyAttributes(): array
    {
        return [
            'class' => 'login-page',
        ];
    }
}
// antes correr en terminal ./vendor/bin/sail artisan migrate --seed
// contraseña test@example.com al iniciar sesión es password
