<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assigns the real electorate scope to every seeded ballot position.
 *
 * 2026_09_27_000002 added `scope_type` / `scope_value` but backfilled every
 * row to 'global' on purpose, so a deployment that never configures a scope
 * keeps its previous behaviour. That left the whole ballot open: a student in
 * one college could vote for a provincial candidate from another college,
 * because 'global' means "no restriction" and nothing else was ever set.
 *
 * The school elects per college, and `users.department` already exists, so the
 * per-college model is expressible with the 'department' scope after all. The
 * earlier migration's note that "there is no `province` column on `users`"
 * only blocks a literal *province* scope; the provincial tier here is contested
 * college-by-college, which `department` models exactly.
 *
 * The resulting model:
 *
 *   provincial  -> 'department'   one college election per college. A voter
 *                                may only vote in their own department, and
 *                                only for a candidate from it.
 *   year_level  -> 'year_level'   A 1st-year student may only vote for the
 *                                1st-year year-level representative, and so on
 *                                for 2nd/3rd/4th year. The candidate's college
 *                                is irrelevant — it is a national-tier seat.
 *   national    -> 'global'       Every student sees every national candidate,
 *                                whatever their college or year.
 *
 * `scope_value` is left NULL on purpose: Position::requiredValueFor() treats an
 * empty value as "the voter's OWN value for that attribute", which is the
 * semantics both scoped tiers need. Pinning a literal here would restrict the
 * seat to exactly one college or one year and lock every other group out.
 *
 * Safe to re-run: only touches rows still sitting at the migration default.
 * PositionController::update() separately refuses scope changes once ballots
 * exist for a position, so this cannot silently rewrite a live election.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('positions', 'scope_type')) {
            return;
        }

        // 1. Provincial: an election within each college.
        DB::table('positions')
            ->where('tier', 'provincial')
            ->update(['scope_type' => 'department', 'scope_value' => null]);

        // 2. Year-level representative: one seat per year level, contested by
        //    and voted for by the students of that year level alone.
        DB::table('positions')
            ->where('slug', 'year_level_representative')
            ->update(['scope_type' => 'year_level', 'scope_value' => null]);

        // 3. Everything else national: open to all students.
        DB::table('positions')
            ->where('tier', 'national')
            ->where('slug', '!=', 'year_level_representative')
            ->update(['scope_type' => 'global', 'scope_value' => null]);
    }

    public function down(): void
    {
        if (! Schema::hasColumn('positions', 'scope_type')) {
            return;
        }

        DB::table('positions')->update(['scope_type' => 'global', 'scope_value' => null]);
    }
};
