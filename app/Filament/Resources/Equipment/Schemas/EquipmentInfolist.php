<?php

namespace App\Filament\Resources\Equipment\Schemas;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class EquipmentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 4])
                    ->extraAttributes(['class' => 'equipment-sheet-hero'])
                    ->schema([
                        ViewEntry::make('photo')
                            ->label('Fotografía del equipo')
                            ->hiddenLabel()
                            ->view('filament.infolists.lightbox-image')
                            ->viewData(fn (Equipment $record): array => [
                                'alt' => trim("[{$record->patrimonial_code}] {$record->brand} {$record->model}"),
                            ])
                            ->extraAttributes(['class' => 'equipment-sheet-photo'])
                            ->visible(fn (Equipment $record): bool => filled($record->photo)),
                        IconEntry::make('type')
                            ->label('Categoría')
                            ->hiddenLabel()
                            ->icon(fn (?string $state): Heroicon => match ($state) {
                                'Computadora' => Heroicon::OutlinedComputerDesktop,
                                'Laptop' => Heroicon::OutlinedDeviceTablet,
                                'Periférico' => Heroicon::OutlinedCpuChip,
                                default => Heroicon::OutlinedCube,
                            })
                            ->extraAttributes(['class' => 'equipment-sheet-icon'])
                            ->visible(fn (Equipment $record): bool => blank($record->photo)),
                        Group::make([
                            TextEntry::make('equipment_heading')
                                ->hiddenLabel()
                                ->state('INVENTARIO UCH')
                                ->extraAttributes(['class' => 'equipment-sheet-eyebrow']),
                            TextEntry::make('equipment_title')
                                ->label('Equipo')
                                ->hiddenLabel()
                                ->state(fn (Equipment $record): string => trim("{$record->brand} {$record->model}"))
                                ->size('lg')
                                ->weight(FontWeight::Bold)
                                ->extraAttributes(['class' => 'equipment-sheet-title']),
                            TextEntry::make('status')
                                ->label('Estado')
                                ->hiddenLabel()
                                ->badge()
                                ->color(fn (string $state): string => Equipment::statusColor($state)),
                        ])
                            ->columnSpan(['sm' => 3]),
                    ]),

                Grid::make(['default' => 1, 'sm' => 3])
                    ->extraAttributes(['class' => 'equipment-sheet-section'])
                    ->schema([
                        TextEntry::make('specs_heading')
                            ->hiddenLabel()
                            ->state('IDENTIFICACIÓN DEL EQUIPO')
                            ->extraAttributes(['class' => 'equipment-sheet-eyebrow'])
                            ->columnSpanFull(),
                        TextEntry::make('patrimonial_code')
                            ->label('Código patrimonial')
                            ->icon(Heroicon::OutlinedQrCode)
                            ->weight(FontWeight::SemiBold)
                            ->copyable()
                            ->copyMessage('Código copiado'),
                        TextEntry::make('serial_number')
                            ->label('Número de serie')
                            ->icon(Heroicon::OutlinedHashtag)
                            ->copyable()
                            ->copyMessage('Serie copiada'),
                        TextEntry::make('type')
                            ->label('Categoría')
                            ->badge()
                            ->color('info'),
                        TextEntry::make('brand')
                            ->label('Marca')
                            ->icon(Heroicon::OutlinedTag),
                        TextEntry::make('model')
                            ->label('Modelo')
                            ->icon(Heroicon::OutlinedCpuChip),
                        TextEntry::make('created_at')
                            ->label('Fecha de registro')
                            ->icon(Heroicon::OutlinedCalendar)
                            ->dateTime('d/m/Y H:i'),
                    ]),

                Grid::make(['default' => 1, 'sm' => 3])
                    ->extraAttributes(['class' => 'equipment-sheet-section'])
                    ->schema([
                        TextEntry::make('activity_heading')
                            ->hiddenLabel()
                            ->state('ESTADO Y ACTIVIDAD')
                            ->extraAttributes(['class' => 'equipment-sheet-eyebrow'])
                            ->columnSpanFull(),
                        TextEntry::make('active_borrowing')
                            ->label('Préstamo activo')
                            ->icon(Heroicon::OutlinedUserCircle)
                            ->state(function (Equipment $record): ?string {
                                $borrowing = $record->activeBorrowing();

                                if ($borrowing === null) {
                                    return null;
                                }

                                $borrower = $borrowing->profile?->full_name ?: $borrowing->student_code;

                                return "{$borrower} ({$borrowing->status})";
                            })
                            ->badge()
                            ->color(function (Equipment $record): string|array {
                                $borrowing = $record->activeBorrowing();

                                return $borrowing !== null
                                    ? Borrowing::statusColor($borrowing->status)
                                    : 'gray';
                            })
                            ->placeholder('Sin préstamo activo'),
                        TextEntry::make('total_borrowings')
                            ->label('Préstamos registrados')
                            ->icon(Heroicon::OutlinedBookmark)
                            ->state(function (Equipment $record): int {
                                $primaryCount = $record->borrowings_count ?? $record->borrowings()->count();
                                $additionalCount = $record->additional_borrowings_count ?? $record->additionalBorrowings()->count();

                                return (int) $primaryCount + (int) $additionalCount;
                            }),
                        TextEntry::make('updated_at')
                            ->label('Última actualización')
                            ->icon(Heroicon::OutlinedClock)
                            ->dateTime('d/m/Y H:i'),
                    ]),

                Grid::make(1)
                    ->extraAttributes(['class' => 'equipment-sheet-observations'])
                    ->schema([
                        TextEntry::make('observations_heading')
                            ->hiddenLabel()
                            ->state('OBSERVACIONES')
                            ->extraAttributes(['class' => 'equipment-sheet-eyebrow']),
                        TextEntry::make('observations')
                            ->hiddenLabel()
                            ->placeholder('Sin observaciones registradas.'),
                    ]),

                Grid::make(1)
                    ->extraAttributes(['class' => 'equipment-sheet-incidents'])
                    ->visible(fn (Equipment $record): bool => $record->incidents->isNotEmpty())
                    ->schema([
                        TextEntry::make('incidents_heading')
                            ->hiddenLabel()
                            ->state('INCIDENCIAS REPORTADAS')
                            ->extraAttributes(['class' => 'equipment-sheet-eyebrow equipment-sheet-eyebrow-danger']),
                        RepeatableEntry::make('incidents')
                            ->hiddenLabel()
                            ->contained(true)
                            ->grid(['default' => 1])
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 4])
                                    ->schema([
                                        Group::make([
                                            TextEntry::make('incident_borrower')
                                                ->label('Préstamo asociado')
                                                ->icon(Heroicon::OutlinedExclamationTriangle)
                                                ->badge()
                                                ->color('danger')
                                                ->state(function (EquipmentIncident $record): string {
                                                    $borrowing = $record->borrowing;

                                                    if ($borrowing === null) {
                                                        return 'Préstamo no disponible';
                                                    }

                                                    $borrower = $borrowing->profile?->full_name ?: 'Estudiante no vinculado';

                                                    return "{$borrower} — Cód. {$borrowing->student_code}";
                                                }),
                                            TextEntry::make('created_at')
                                                ->label('Reportado el')
                                                ->dateTime('d/m/Y H:i'),
                                            TextEntry::make('description')
                                                ->label('Detalle del daño'),
                                        ])
                                            ->columnSpan(['sm' => 3]),
                                        ViewEntry::make('photo')
                                            ->label('Evidencia')
                                            ->view('filament.infolists.lightbox-image')
                                            ->viewData(fn (EquipmentIncident $record): array => [
                                                'disk' => EquipmentIncident::photoDisk(),
                                                'alt' => $record->equipment !== null
                                                    ? "Evidencia — [{$record->equipment->patrimonial_code}] {$record->equipment->brand} {$record->equipment->model}"
                                                    : 'Evidencia fotográfica del daño',
                                                'caption' => $record->description,
                                            ])
                                            ->visible(fn (EquipmentIncident $record): bool => filled($record->photo)),
                                    ]),
                            ]),
                    ]),
            ]);
    }
}
