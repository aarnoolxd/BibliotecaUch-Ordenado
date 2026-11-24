<?php

namespace Tests\Feature\Database\Migrations;

use App\Models\Borrowing;
use App\Models\Equipment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BorrowingsSchemaMigrationTest extends TestCase
{
    use LazilyRefreshDatabase;

    /**
     * Cada condición incompatible (equipment_id nulo, due_date nula o asignaciones en
     * borrowing_equipment) debe detener de forma independiente la reversión antes de
     * ejecutar operaciones destructivas.
     */
    public function test_rollback_aborts_before_destructive_changes_when_incompatible_borrowings_exist(): void
    {
        $migration = $this->loadMigration();

        // 1. Solo equipment_id nulo (due_date explícita y sin filas en borrowing_equipment)
        $missingEquipmentBorrowing = Borrowing::factory()
            ->withoutPrimaryEquipment()
            ->create([
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-23 12:00:00',
            ]);

        try {
            $migration->down();
            $this->fail('Se esperaba RuntimeException al intentar revertir con préstamos sin equipment_id.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('1 préstamo(s) sin equipment_id', $exception->getMessage());
            $this->assertStringContainsString('0 préstamo(s) sin due_date', $exception->getMessage());
            $this->assertStringContainsString('0 asignación(es)', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('borrowing_equipment'));
        $this->assertModelExists($missingEquipmentBorrowing);

        $missingEquipmentBorrowing->delete();

        // 2. Solo due_date nula (equipment_id presente y sin filas en borrowing_equipment)
        $laptop = Equipment::factory()->ofType('Laptop')->withStatus('Prestado')->create();
        $unlimitedLaptopBorrowing = Borrowing::factory()
            ->for($laptop)
            ->create(['due_date' => null]);

        try {
            $migration->down();
            $this->fail('Se esperaba RuntimeException al intentar revertir con préstamos sin due_date.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('0 préstamo(s) sin equipment_id', $exception->getMessage());
            $this->assertStringContainsString('1 préstamo(s) sin due_date', $exception->getMessage());
            $this->assertStringContainsString('0 asignación(es)', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('borrowing_equipment'));
        $this->assertModelExists($unlimitedLaptopBorrowing);

        $unlimitedLaptopBorrowing->delete();

        // 3. Solo asignaciones en borrowing_equipment (equipment_id y due_date presentes)
        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $mouse = Equipment::factory()->ofType('Periférico')->withStatus('Prestado')->create();
        $borrowingWithAdditional = Borrowing::factory()
            ->for($computer)
            ->withAdditionalEquipment($mouse)
            ->create([
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-23 12:00:00',
            ]);

        try {
            $migration->down();
            $this->fail('Se esperaba RuntimeException al intentar revertir con asignaciones en borrowing_equipment.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('0 préstamo(s) sin equipment_id', $exception->getMessage());
            $this->assertStringContainsString('0 préstamo(s) sin due_date', $exception->getMessage());
            $this->assertStringContainsString('1 asignación(es)', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasTable('borrowing_equipment'));
        $this->assertSame(1, DB::table('borrowing_equipment')->count());
        $this->assertModelExists($borrowingWithAdditional);
    }

    /**
     * Cuando todos los préstamos existentes cumplen el esquema anterior (equipment_id y due_date no nulos),
     * la reversión elimina borrowing_equipment y restaura las restricciones NOT NULL conservando los datos.
     */
    public function test_rollback_succeeds_when_all_borrowings_satisfy_the_legacy_schema(): void
    {
        $migration = $this->loadMigration();

        $computer = Equipment::factory()->ofType('Computadora')->withStatus('Prestado')->create();
        $compatibleBorrowing = Borrowing::factory()
            ->for($computer)
            ->create([
                'borrowing_date' => '2026-11-23 10:00:00',
                'due_date' => '2026-11-23 12:00:00',
            ]);

        try {
            $migration->down();

            $this->assertFalse(Schema::hasTable('borrowing_equipment'));
            $this->assertModelExists($compatibleBorrowing);

            $columns = collect(Schema::getColumns('borrowings'))->keyBy('name');

            $this->assertFalse($columns['equipment_id']['nullable']);
            $this->assertFalse($columns['due_date']['nullable']);
        } finally {
            if (! Schema::hasTable('borrowing_equipment')) {
                $migration->up();
            }
        }
    }

    private function loadMigration(): Migration
    {
        /** @var Migration $migration */
        $migration = require database_path('migrations/2026_11_23_174510_update_borrowings_and_create_borrowing_equipment_table.php');

        return $migration;
    }
}
