<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the redundant `registrar_imports.grade_level` column.
 *
 * The eligibility feed has carried a separate `year_level` section column
 * since 2026-09-05, and `grade_level` was never propagated onto `users`
 * (the account table only has `year_level`, `block_number`, `department`),
 * so it is unused everywhere downstream. The CSV import previously required
 * a Grade Level header; that requirement is removed in the same change, so
 * the NOT NULL column must go too or every import would fail.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->dropColumn('grade_level');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            // NULLABLE on purpose: re-adding a NOT NULL column with no default
            // fails outright on a populated table, which is precisely the state
            // of the database during an emergency rollback. Making it nullable
            // keeps `migrate:rollback` executable under pressure; the column is
            // unused downstream, so no backfill is possible or needed.
            $table->string('grade_level')->nullable();
        });
    }
};