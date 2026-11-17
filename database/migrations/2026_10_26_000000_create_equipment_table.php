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
        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->string('patrimonial_code')->unique()->comment('Código patrimonial institucional UCH');
            $table->string('type')->comment('Categoría o tipo de equipo (Computadora, Laptop, Periférico, etc.)');
            $table->string('brand')->comment('Marca del fabricante');
            $table->string('model')->comment('Modelo o denominación comercial');
            $table->string('serial_number')->unique()->comment('Número de serie único');
            $table->string('status')->default('Disponible')->comment('Estado operativo: Disponible, Prestado, Mantenimiento, Baja');
            $table->text('observations')->nullable()->comment('Observaciones adicionales');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('equipment');
    }
};
