<?php

namespace App\Filament\Resources\Borrowings\Schemas;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Infolists\Components\ViewEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Schema;
use Filament\Support\Enums\FontWeight;
use Filament\Support\Icons\Heroicon;

class BorrowingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 4])
                    ->extraAttributes(['class' => 'borrowing-sheet-hero'])
                    ->schema([
                        ImageEntry::make('profile.photo')
                            ->label('Fotografía del solicitante')
                            ->hiddenLabel()
                            ->circular()
                            ->imageSize(112)
                            ->extraAttributes(['class' => 'borrowing-sheet-photo'])
                            ->visible(fn (Borrowing $record): bool => filled($record->profile?->photo)),
                        TextEntry::make('borrower_initials')
                            ->label('Iniciales')
                            ->hiddenLabel()
                            ->state(function (Borrowing $record): string {
                                $profile = $record->profile;

                                if ($profile === null) {
                                    return '—';
                                }

                                $initials = mb_strtoupper(
                                    mb_substr(trim((string) $profile->first_name), 0, 1)
                                    .mb_substr(trim((string) $profile->last_name), 0, 1)
                                );

                                return $initials !== '' ? $initials : '—';
                            })
                            ->extraAttributes(['class' => 'borrowing-sheet-initials'])
                            ->visible(fn (Borrowing $record): bool => blank($record->profile?->photo)),
                        Group::make([
                            TextEntry::make('borrowing_heading')
                                ->hiddenLabel()
                                ->state('BIBLIOTECA UCH')
                                ->extraAttributes(['class' => 'borrowing-sheet-eyebrow']),
                            TextEntry::make('borrower_name')
                                ->label('Solicitante')
                                ->hiddenLabel()
                                ->state(fn (Borrowing $record): string => $record->profile?->full_name ?: 'Estudiante no vinculado')
                                ->size('lg')
                                ->weight(FontWeight::Bold)
                                ->extraAttributes(['class' => 'borrowing-sheet-title']),
                            TextEntry::make('status')
                                ->label('Estado del préstamo')
                                ->hiddenLabel()
                                ->badge()
                                ->color(fn (string $state): string => match ($state) {
                                    Borrowing::STATUS_IN_PROGRESS => 'info',
                                    Borrowing::STATUS_RETURNED => 'success',
                                    Borrowing::STATUS_OVERDUE => 'danger',
                                    default => 'gray',
                                }),
                        ])
                            ->columnSpan(['sm' => 3]),
                    ]),

                Grid::make(['default' => 1, 'sm' => 2])
                    ->extraAttributes(['class' => 'borrowing-sheet-section'])
                    ->schema([
                        TextEntry::make('resources_heading')
                            ->hiddenLabel()
                            ->state('RECURSOS ASIGNADOS')
                            ->extraAttributes(['class' => 'borrowing-sheet-eyebrow'])
                            ->columnSpanFull(),
                        TextEntry::make('primary_equipment_summary')
                            ->label('Equipo principal')
                            ->icon(Heroicon::OutlinedComputerDesktop)
                            ->weight(FontWeight::SemiBold)
                            ->state(function (Borrowing $record): ?string {
                                $equipment = $record->equipment;

                                if ($equipment === null) {
                                    return null;
                                }

                                return "[{$equipment->patrimonial_code}] {$equipment->brand} {$equipment->model}";
                            })
                            ->placeholder('Sin equipo principal (solo periféricos)'),
                        TextEntry::make('additional_equipment_summary')
                            ->label('Periféricos / Otros dispositivos')
                            ->icon(Heroicon::OutlinedCpuChip)
                            ->state(fn (Borrowing $record): array => $record->additionalEquipment
                                ->map(fn (Equipment $item): string => "[{$item->patrimonial_code}] {$item->brand} {$item->model}")
                                ->all())
                            ->badge()
                            ->color('gray')
                            ->placeholder('Sin dispositivos adicionales'),
                    ]),

                Grid::make(['default' => 1, 'sm' => 3])
                    ->extraAttributes(['class' => 'borrowing-sheet-section'])
                    ->schema([
                        TextEntry::make('timeline_heading')
                            ->hiddenLabel()
                            ->state('CRONOLOGÍA Y CONTROL')
                            ->extraAttributes(['class' => 'borrowing-sheet-eyebrow'])
                            ->columnSpanFull(),
                        TextEntry::make('borrowing_date')
                            ->label('Fecha de préstamo')
                            ->icon(Heroicon::OutlinedCalendar)
                            ->dateTime('d/m/Y H:i'),
                        TextEntry::make('due_date')
                            ->label('Límite de devolución')
                            ->icon(Heroicon::OutlinedClock)
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Sin límite'),
                        TextEntry::make('return_date')
                            ->label('Fecha de devolución')
                            ->icon(Heroicon::OutlinedCheckCircle)
                            ->dateTime('d/m/Y H:i')
                            ->placeholder('Pendiente de devolución'),
                        TextEntry::make('student_dni')
                            ->label('DNI del solicitante')
                            ->icon(Heroicon::OutlinedIdentification)
                            ->copyable()
                            ->copyMessage('DNI copiado'),
                        TextEntry::make('profile.phone')
                            ->label('Celular de contacto')
                            ->icon(Heroicon::OutlinedPhone)
                            ->placeholder('Sin celular registrado')
                            ->copyable()
                            ->copyMessage('Celular copiado'),
                        TextEntry::make('registeredBy.name')
                            ->label('Registrado por')
                            ->icon(Heroicon::OutlinedUserCircle)
                            ->placeholder('No registrado'),
                    ]),

                Grid::make(1)
                    ->extraAttributes(['class' => 'borrowing-sheet-observations'])
                    ->schema([
                        TextEntry::make('observations_heading')
                            ->hiddenLabel()
                            ->state('OBSERVACIONES')
                            ->extraAttributes(['class' => 'borrowing-sheet-eyebrow']),
                        TextEntry::make('observations')
                            ->hiddenLabel()
                            ->placeholder('Sin observaciones registradas.'),
                    ]),

                Grid::make(1)
                    ->extraAttributes(['class' => 'borrowing-sheet-incidents'])
                    ->visible(fn (Borrowing $record): bool => $record->incidents->isNotEmpty())
                    ->schema([
                        TextEntry::make('incidents_heading')
                            ->hiddenLabel()
                            ->state('INCIDENCIAS POR DAÑO REPORTADAS')
                            ->extraAttributes(['class' => 'borrowing-sheet-eyebrow borrowing-sheet-eyebrow-danger']),
                        RepeatableEntry::make('incidents')
                            ->hiddenLabel()
                            ->contained(true)
                            ->grid(['default' => 1])
                            ->schema([
                                Grid::make(['default' => 1, 'sm' => 4])
                                    ->schema([
                                        Group::make([
                                            TextEntry::make('incident_equipment')
                                                ->label('Dispositivo afectado')
                                                ->icon(Heroicon::OutlinedExclamationTriangle)
                                                ->badge()
                                                ->color('danger')
                                                ->state(function (EquipmentIncident $record): string {
                                                    $equipment = $record->equipment;

                                                    if ($equipment === null) {
                                                        return 'Recurso eliminado';
                                                    }

                                                    return "[{$equipment->patrimonial_code}] {$equipment->brand} {$equipment->model}";
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
