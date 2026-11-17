<?php

namespace App\Filament\Pages\Auth;

use Filament\Auth\Pages\Login as BaseLogin;
use Override;

class CustomLogin extends BaseLogin
{
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