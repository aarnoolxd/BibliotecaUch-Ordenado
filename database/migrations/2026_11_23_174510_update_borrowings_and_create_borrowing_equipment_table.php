<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_id')->nullable()->change();
            $table->dateTime('due_date')->nullable()->change();
        });

        Schema::create('borrowing_equipment', function (Blueprint $table) {
            $table->id();
            $table->foreignId('borrowing_id')->constrained('borrowings')->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['borrowing_id', 'equipment_id']);
            $table->index('equipment_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $missingEquipmentCount = DB::table('borrowings')
            ->whereNull('equipment_id')
            ->count();

        $missingDueDateCount = DB::table('borrowings')
            ->whereNull('due_date')
            ->count();

        $additionalAssignmentsCount = Schema::hasTable('borrowing_equipment')
            ? DB::table('borrowing_equipment')->count()
            : 0;

        if ($missingEquipmentCount > 0 || $missingDueDateCount > 0 || $additionalAssignmentsCount > 0) {
            throw new RuntimeException(
                "No se puede revertir la migración de borrowings sin intervención manual: existen {$missingEquipmentCount} préstamo(s) sin equipment_id, {$missingDueDateCount} préstamo(s) sin due_date y {$additionalAssignmentsCount} asignación(es) de equipos adicionales en borrowing_equipment. Resuelva o migre esos registros antes de ejecutar el rollback."
            );
        }

        Schema::dropIfExists('borrowing_equipment');

        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_id')->nullable(false)->change();
            $table->dateTime('due_date')->nullable(false)->change();
        });
    }
};
