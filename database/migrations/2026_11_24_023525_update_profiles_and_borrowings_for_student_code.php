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
        Schema::table('profiles', function (Blueprint $table) {
            $table->renameColumn('dni', 'student_code');
            $table->string('student_type')->nullable();
            $table->string('modality')->nullable();
            $table->string('shift')->nullable();
        });

        Schema::table('borrowings', function (Blueprint $table) {
            $table->renameColumn('student_dni', 'student_code');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('borrowings', function (Blueprint $table) {
            $table->renameColumn('student_code', 'student_dni');
        });

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['student_type', 'modality', 'shift']);
            $table->renameColumn('student_code', 'dni');
        });
    }
};
