<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds an electorate scope to ballot positions (security assessment M-4).
 *
 * THE PROBLEM: `VoteController::resolveSelections()` checked only that a
 * position was active, the slug matched the seat, `seat_count` was not
 * exceeded, and the candidate was approved *for that position*. Nothing
 * constrained WHO could vote in WHICH seat. Concretely a first-year student
 * could cast a vote in `year_level_representative`, `governor` or `senator`,
 * and the server recorded it. Flutter filtered the ballot by year level, but
 * the server is authoritative and any HTTP client skips the client-side filter.
 *
 * THE FIX: each position declares who may vote in it. Two new columns:
 *
 *   scope_type  — 'global' (default) | 'year_level' | 'department' | 'course'
 *   scope_value — a literal required value, or NULL meaning "the voter's own
 *                 value for that attribute" (the common case: a year-level
 *                 representative seat is contested by, and voted for by, the
 *                 students of that year level only).
 *
 * WHY 'global' IS THE DEFAULT: every existing row gets 'global', which means
 * "no restriction" and reproduces the previous behaviour exactly. A deployment
 * that never configures a scope is therefore completely unaffected — this
 * migration cannot change an existing election's outcome. Administrators opt
 * into scoping per position.
 *
 * WHY ONLY year_level / department / course: those are the attributes a
 * student account actually carries. There is no `province` column on `users`,
 * so a 'province' scope could not actually be evaluated and would be a control
 * that only looks enforced — provincial seats are deliberately left 'global'
 * until the schema can support the check.
 *
 * SCOPE IS ENFORCED AT WRITE TIME, IN `resolveSelections()`. The assessment
 * also suggested re-filtering in the tally, but that is incompatible with the
 * H-1 anonymity fix: `vote_ledger` deliberately carries no user foreign key, so
 * there is nothing to filter on. Validating before the rows are written is
 * authoritative — every ledger row was checked against the voter's scope on the
 * way in. `PositionController::update()` additionally refuses to change a
 * scope once ballots exist for that position, so an admin cannot retroactively
 * make already-counted votes out of scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->string('scope_type', 32)->default('global')->after('tier');
            $table->string('scope_value')->nullable()->after('scope_type');
        });

        // Backfill safety: any row written before this migration (or by a
        // seeder that omits the columns) is explicitly unrestricted.
        DB::table('positions')->whereNull('scope_type')->update(['scope_type' => 'global']);
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropColumn(['scope_type', 'scope_value']);
        });
    }
};
