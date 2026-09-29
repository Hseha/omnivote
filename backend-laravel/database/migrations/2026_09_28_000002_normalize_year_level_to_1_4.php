<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Normalises `users.year_level` to the canonical college year values 1-4.
 *
 * THE PROBLEM: the column is a free-text `varchar(255)` and has accumulated
 * three different conventions — senior-high '11'/'12' from the demo seeder,
 * a lone '1' from a real student, and NULL. Position::valuesMatch() compares
 * year scopes as exact case-insensitive strings, so '1' !== '11' !== '12'.
 * With year_level_representative scoped by year level, that silently
 * disenfranchises anyone whose stored value does not match the candidate's:
 * a student stored as '1' could not vote for a 1st-year representative, and a
 * student stored as '11' could not vote for a student stored as '1'.
 *
 * THE FIX: collapse the senior-high numbering onto the college numbering
 * (grade 11 is 1st year college, grade 12 is 2nd year) so one canonical set
 * survives: '1', '2', '3', '4'.
 *
 * WHAT THIS DELIBERATELY DOES NOT DO: it never redistributes or invents a
 * year level. Every student keeps the year they already had — a real registry
 * must not have its students silently moved between year levels because a demo
 * seeder changed its numbering convention. Ensuring all four year levels are
 * actually populated is the seeder's job (TestBallotSeeder), not a migration's.
 *
 * Values outside 1-4 that have no defensible mapping (an empty string, or
 * something like 'N/A') become NULL rather than a guess. A NULL year level is
 * refused a year-level seat — which is the safe direction: it is a data-quality
 * problem to fix at the registrar, not something to paper over with a wrong
 * year.
 */
return new class extends Migration
{
    /** Senior-high grade -> college year. */
    private const LEGACY_MAP = [
        '11' => '1',
        '12' => '2',
        '13' => '3',
        '14' => '4',
    ];

    public function up(): void
    {
        if (! Schema::hasColumn('users', 'year_level')) {
            return;
        }

        foreach (DB::table('users')->whereNotNull('year_level')->distinct()->pluck('year_level') as $raw) {
            $value = mb_strtolower(trim((string) $raw));
            $value = self::LEGACY_MAP[$value] ?? $value;

            // Anything that is not already a canonical 1-4 becomes NULL.
            $normalised = in_array($value, ['1', '2', '3', '4'], true) ? $value : null;

            DB::table('users')
                ->where('year_level', $raw)
                ->update(['year_level' => $normalised]);
        }
    }

    public function down(): void
    {
        // Not reversible: the pre-migration value was a mix of conventions and
        // there is no way to know which student meant '11' and which meant '1'.
        // A down() that guessed would be worse than no down() at all.
    }
};
