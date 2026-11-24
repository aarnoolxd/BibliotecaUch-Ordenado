<?php

namespace App\Filament\Resources\Profiles\Schemas;

use App\Models\Profile;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class ProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 4])
                    ->extraAttributes(['class' => 'profile-sheet-hero'])
                    ->schema([
                        ImageEntry::make('photo')
                            ->label('Fotografía')
                            ->hiddenLabel()
                            ->circular()
                            ->imageSize(112)
                            ->extraAttributes(['class' => 'profile-sheet-photo'])
                            ->visible(fn (Profile $record): bool => filled($record->photo)),
                        TextEntry::make('initials')
                            ->label('Iniciales')
                            ->hiddenLabel()
                            ->state(function (Profile $record): string {
                                $initials = mb_strtoupper(
                                    mb_substr(trim((string) $record->first_name), 0, 1)
                                    .mb_substr(trim((string) $record->last_name), 0, 1)
                                );

                                return $initials !== '' ? $initials : '—';
                            })
                            ->extraAttributes(['class' => 'profile-sheet-initials'])
                            ->visible(fn (Profile $record): bool => blank($record->photo)),
                        Group::make([
                            TextEntry::make('profile_heading')
                                ->hiddenLabel()
                                ->state('COMUNIDAD UCH')
                                ->extraAttributes(['class' => 'profile-sheet-eyebrow']),
                            TextEntry::make('full_name')
                                ->label('Nombre completo')
                                ->hiddenLabel()
                                ->size('lg')
                                ->weight(FontWeight::Bold)
                                ->extraAttributes(['class' => 'profile-sheet-name']),
                            TextEntry::make('account_status')
                                ->label('Cuenta de acceso')
                                ->hiddenLabel()
                                ->state(fn (Profile $record): string => $record->user ? 'Cuenta vinculada' : 'Sin cuenta vinculada')
                                ->badge()
                                ->color(fn (Profile $record): string => $record->user ? 'success' : 'gray'),
                        ])
                            ->columnSpan(['sm' => 3]),
                    ]),
                Grid::make(['default' => 1, 'sm' => 2])
                    ->extraAttributes(['class' => 'profile-sheet-academic'])
                    ->schema([
                        TextEntry::make('program')
                            ->label('Formación académica')
                            ->icon(Heroicon::OutlinedAcademicCap)
                            ->weight(FontWeight::SemiBold)
                            ->size('lg')
                            ->placeholder('Sin programa registrado')
                            ->columnSpanFull(),
                        TextEntry::make('cycle')
                            ->label('Ciclo')
                            ->formatStateUsing(fn (int|string $state): string => 'Ciclo '.$state)
                            ->badge()
                            ->color('primary')
                            ->placeholder('Sin ciclo registrado'),
                        TextEntry::make('user_type')
                            ->label('Tipo de usuario')
                            ->badge()
                            ->color('gray')
                            ->placeholder('Sin tipo registrado'),
                    ]),
                Grid::make(['default' => 1, 'sm' => 2])
                    ->extraAttributes(['class' => 'profile-sheet-contact'])
                    ->schema([
                        TextEntry::make('contact_heading')
                            ->hiddenLabel()
                            ->state('DATOS PERSONALES Y CONTACTO')
                            ->extraAttributes(['class' => 'profile-sheet-eyebrow'])
                            ->columnSpanFull(),
                        TextEntry::make('student_code')
                            ->label('Código de estudiante')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->copyable()
                            ->copyMessage('Código copiado'),
                        TextEntry::make('phone')
                            ->label('Número de celular')
                            ->icon(Heroicon::OutlinedPhone)
                            ->placeholder('Sin celular registrado')
                            ->copyable()
                            ->copyMessage('Número de celular copiado'),
                        TextEntry::make('user.email')
                            ->label('Correo electrónico')
                            ->icon(Heroicon::OutlinedEnvelope)
                            ->placeholder('Sin correo vinculado')
                            ->copyable()
                            ->copyMessage('Correo copiado'),
                        TextEntry::make('address')
                            ->label('Domicilio')
                            ->icon(Heroicon::OutlinedMapPin)
                            ->placeholder('Sin dirección registrada'),
                    ]),
            ]);
    }
}
