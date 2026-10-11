<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a SECOND electorate-scope dimension to ballot positions.
 *
 * THE PROBLEM: 2026_09_27_000002 gave a position a single `scope_type` /
 * `scope_value`, which can express "vote by your own college" OR "vote by your
 * own year level", but never both at once. The Year Level Representative seat
 * needs both: the school elects one representative per college PER year level,
 * so a 1st-year BSIT (CCS) student votes only for the 1st-year CCS
 * representative, and only students of that college+year contest it.
 *
 * THE FIX: two more columns. A position may now carry up to two scope
 * dimensions. `scope_type` remains the primary (default 'global'); a seat with
 * no non-global dimension behaves exactly as before. The Year Level
 * Representative is retargeted to `department` + `year_level` here.
 *
 * Grouping follows automatically: the results tally elects `seat_count` winners
 * per scope group, so a two-dimension seat elects one per (college, year).
 *
 * Safe to re-run: the backfill keys only on the canonical YLR slug.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'scope_type_2')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->string('scope_type_2', 32)->nullable()->after('scope_value');
                $table->string('scope_value_2')->nullable()->after('scope_type_2');
            });
        }

        // Year Level Representative: a per-college, per-year race.
        DB::table('positions')
            ->where('slug', 'year_level_representative')
            ->update([
                'scope_type' => 'department',
                'scope_value' => null,
                'scope_type_2' => 'year_level',
                'scope_value_2' => null,
            ]);
    }

    public function down(): void
    {
        DB::table('positions')
            ->where('slug', 'year_level_representative')
            ->update([
                'scope_type' => 'year_level',
                'scope_value' => null,
                'scope_type_2' => null,
                'scope_value_2' => null,
            ]);

        if (Schema::hasColumn('positions', 'scope_type_2')) {
            Schema::table('positions', function (Blueprint $table) {
                $table->dropColumn(['scope_type_2', 'scope_value_2']);
            });
        }
    }
};
