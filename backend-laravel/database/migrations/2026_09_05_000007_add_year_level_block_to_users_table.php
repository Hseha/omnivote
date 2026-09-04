<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Mirrors the registrar-import section metadata onto the user account so a
     * provisioned student carries their Year Level / Block Number after login —
     * enabling section-filtered rosters and ballot organization downstream.
     * Nullable; only populated when the CSV supplied the columns.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('year_level')->nullable()->after('role');
            $table->string('block_number')->nullable()->after('year_level');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['year_level', 'block_number']);
        });
    }
};
