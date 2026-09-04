<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds the optional Year Level / Block Number section metadata to the
     * registrar import feed. Columns are nullable so existing 5-column CSVs
     * (Student ID, Full Name, Email Address, Grade Level, Role) keep importing
     * unchanged; when present they drive roster filtering and ballot grouping.
     */
    public function up(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->string('year_level')->nullable()->after('grade_level');
            $table->string('block_number')->nullable()->after('year_level');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->dropColumn(['year_level', 'block_number']);
        });
    }
};
