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
        Schema::dropIfExists('borrowing_equipment');

        Schema::table('borrowings', function (Blueprint $table) {
            $table->unsignedBigInteger('equipment_id')->nullable(false)->change();
            $table->dateTime('due_date')->nullable(false)->change();
        });
    }
};
