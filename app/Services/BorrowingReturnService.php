<?php

namespace App\Services;

use App\Models\Borrowing;
use App\Models\Equipment;
use App\Models\EquipmentIncident;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BorrowingReturnService
{
    /**
     * Procesa la devolución de un préstamo evaluando cada recurso (principal y adicionales).
     * Solo crea registros en equipment_incidents cuando un recurso presenta daños (has_damage = true).
     *
     * @param  array<int, array{equipment_id: int|string, has_damage?: bool, description?: ?string, photo?: ?string}>  $evaluations
     * @return array{borrowing: Borrowing, incidents: Collection<int, EquipmentIncident>, released_count: int, maintenance_count: int}
     */
    public function registerReturn(Borrowing $borrowing, array $evaluations): array
    {
        return DB::transaction(function () use ($borrowing, $evaluations): array {
            /** @var Borrowing $lockedBorrowing */
            $lockedBorrowing = Borrowing::query()
                ->with(['equipment', 'additionalEquipment'])
                ->lockForUpdate()
                ->findOrFail($borrowing->getKey());

            if (! in_array($lockedBorrowing->status, Borrowing::ACTIVE_STATUSES, true)) {
                throw ValidationException::withMessages([
                    'borrowing' => 'Este préstamo ya cuenta con una devolución registrada.',
                ]);
            }

            $authoritativeIds = $lockedBorrowing->allEquipmentIds();
            $indexedEvaluations = [];

            foreach ($evaluations as $index => $item) {
                $equipmentId = (int) ($item['equipment_id'] ?? 0);

                if (! in_array($equipmentId, $authoritativeIds, true)) {
                    throw ValidationException::withMessages([
                        "items.{$index}.equipment_id" => 'El recurso indicado no pertenece a este préstamo.',
                    ]);
                }

                $indexedEvaluations[$equipmentId] = [
                    'index' => $index,
                    'has_damage' => (bool) ($item['has_damage'] ?? false),
                    'description' => isset($item['description']) ? trim((string) $item['description']) : null,
                    'photo' => is_string($item['photo'] ?? null) && trim($item['photo']) !== '' ? $item['photo'] : null,
                ];
            }

            $lockedEquipments = Equipment::query()
                ->whereIn('id', $authoritativeIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var Collection<int, EquipmentIncident> $incidents */
            $incidents = collect();
            $releasedCount = 0;
            $maintenanceCount = 0;

            foreach ($authoritativeIds as $equipmentId) {
                /** @var Equipment|null $equipment */
                $equipment = $lockedEquipments->get($equipmentId);

                if ($equipment === null) {
                    continue;
                }

                $evaluation = $indexedEvaluations[$equipmentId] ?? [
                    'index' => 0,
                    'has_damage' => false,
                    'description' => null,
                    'photo' => null,
                ];

                if ($evaluation['has_damage']) {
                    $errors = [];

                    if (blank($evaluation['description'])) {
                        $errors["items.{$evaluation['index']}.description"] = 'Debe describir el daño detectado en el recurso.';
                    }

                    if (blank($evaluation['photo'])) {
                        $errors["items.{$evaluation['index']}.photo"] = 'Debe adjuntar una fotografía de evidencia del daño.';
                    }

                    if ($errors !== []) {
                        throw ValidationException::withMessages($errors);
                    }

                    $incident = EquipmentIncident::query()->create([
                        'equipment_id' => $equipmentId,
                        'borrowing_id' => $lockedBorrowing->getKey(),
                        'description' => $evaluation['description'],
                        'photo' => $evaluation['photo'],
                    ]);

                    $incidents->push($incident);
                    $equipment->update(['status' => 'Mantenimiento']);
                    $maintenanceCount++;
                } else {
                    Borrowing::releaseEquipmentIfNoActiveBorrowings(
                        equipmentId: $equipmentId,
                        ignoreBorrowingId: (int) $lockedBorrowing->getKey(),
                    );
                    $releasedCount++;
                }
            }

            $lockedBorrowing->update([
                'status' => Borrowing::STATUS_RETURNED,
                'return_date' => now(),
            ]);

            return [
                'borrowing' => $lockedBorrowing->fresh(['equipment', 'additionalEquipment', 'incidents']),
                'incidents' => $incidents,
                'released_count' => $releasedCount,
                'maintenance_count' => $maintenanceCount,
            ];
        });
    }
}
