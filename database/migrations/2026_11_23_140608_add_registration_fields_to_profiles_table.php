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
            $table->string('maternal_last_name')->nullable();
            $table->string('program')->nullable();
            $table->unsignedTinyInteger('cycle')->nullable();
            $table->string('user_type')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['maternal_last_name', 'program', 'cycle', 'user_type']);
        });
    }
};
