<?php

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\CreateRecord;
use App\Filament\Resources\Roles\RoleResource;
use BezhanSalleh\FilamentShield\Support\Utils;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

class CreateRole extends CreateRecord
{
    public Collection $permissions;

    protected static string $resource = RoleResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $this->permissions = collect($data)
            ->filter(fn (mixed $permission, string $key): bool => ! in_array($key, ['name', 'guard_name', 'select_all'], true))
            ->values()
            ->flatten()
            ->unique();

        return Arr::only($data, ['name', 'guard_name']);
    }

    protected function afterCreate(): void
    {
        $permissionModels = collect();

        $this->permissions->each(function (string $permission) use ($permissionModels): void {
            $permissionModels->push(Utils::getPermissionModel()::firstOrCreate([
                'name' => $permission,
                'guard_name' => $this->data['guard_name'],
            ]));
        });

        $this->record->syncPermissions($permissionModels);
    }

    protected function getCreateConfirmationHeading(): string
    {
        return '¿Crear rol?';
    }

    protected function getCreateConfirmationDescription(): string
    {
        return 'Se guardará el rol y sus permisos.';
    }

    protected function getCreateConfirmationSubmitLabel(): string
    {
        return 'Crear';
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Rol creado';
    }
}
