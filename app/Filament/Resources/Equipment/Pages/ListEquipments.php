<?php

namespace App\Filament\Resources\Equipment\Pages;

use App\Filament\Resources\Equipment\EquipmentResource;
use App\Filament\Resources\Equipment\Widgets\EquipmentStatusOverview;
use App\Models\Equipment;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Database\Eloquent\Builder;

class ListEquipments extends ListRecords
{
    protected static string $resource = EquipmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * UB-30 / Criterio 5: Widget de métricas en tiempo real en la cabecera.
     * UB-25 / Criterio 5: Indicador visual de carga está configurado en la tabla (deferLoading).
     */
    protected function getHeaderWidgets(): array
    {
        return [
            EquipmentStatusOverview::class,
        ];
    }

    /**
     * UB-118: Paginación fluida configurada directamente en EquipmentTable::configure().
     *
     * UB-138 / UB-133 / Criterio 3 (HU-22): Desglose interactivo por estado.
     * Al hacer clic en cada pestaña se filtran los equipos que componen dicha cifra.
     * UB-140 / Criterio 5 (HU-22): Los badges se actualizan en tiempo real (polling 15s en el widget).
     *
     * UB-119 / Criterio 3 (HU-04): El acordeón/Panel collapsible está implementado en EquipmentTable.
     * UB-117 / Criterio 5 (HU-04): emptyStateHeading/Description configurados en EquipmentTable.
     */
    public function getTabs(): array
    {
        return [
            'todos' => Tab::make('Todos los Recursos')
                ->badge(Equipment::count())
                ->badgeColor('primary'),

            'disponibles' => Tab::make('Disponibles')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Disponible'))
                ->badge(Equipment::where('status', 'Disponible')->count())
                ->badgeColor('success'),

            'prestados' => Tab::make('En Préstamo')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Prestado'))
                ->badge(Equipment::where('status', 'Prestado')->count())
                ->badgeColor('warning'),

            'mantenimiento' => Tab::make('En Mantenimiento')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Mantenimiento'))
                ->badge(Equipment::where('status', 'Mantenimiento')->count())
                ->badgeColor('danger'),

            'baja' => Tab::make('De Baja')
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', 'Baja'))
                ->badge(Equipment::where('status', 'Baja')->count())
                ->badgeColor('gray'),
        ];
    }
}
