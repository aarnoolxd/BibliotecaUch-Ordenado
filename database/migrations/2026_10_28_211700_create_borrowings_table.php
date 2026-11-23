<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('borrowings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('equipment_id')->nullable()->constrained('equipment')->cascadeOnDelete();
            $table->foreignId('profile_id')->nullable()->constrained('profiles')->nullOnDelete();
            $table->string('student_dni', 8);
            $table->dateTime('borrowing_date');
            $table->dateTime('due_date')->nullable();
            $table->dateTime('return_date')->nullable();
            $table->string('status', 30)->default('En curso'); // En curso, Devuelto, Vencido
            $table->text('observations')->nullable();
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
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
        Schema::dropIfExists('borrowing_equipment');
        Schema::dropIfExists('borrowings');
    }
};
