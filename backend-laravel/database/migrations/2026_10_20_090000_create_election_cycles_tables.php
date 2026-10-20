<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-cycle election archive.
     *
     * `election_candidate_records` already keeps the frozen candidate roster.
     * This adds the two things a roster alone cannot answer:
     *
     *  - `election_cycles` — the cycle header: WHEN the election ran, what it
     *    was called, how it ended (voting_closed / finalized / aborted), and
     *    the turnout. Without it a record row is a candidate with no election
     *    attached to it.
     *  - `election_cycle_positions` — the ballot as it was, including seats
     *    that nobody contested. The roster only carries positions that had a
     *    candidate, so a seat nobody ran for would be invisible.
     *
     * Both tables are foreign-key free by the same rule as the existing
     * archive: a record must outlive the live rows it was copied from, and a
     * later clean-slate reset deletes those rows. `cycle_id` is a plain string
     * that ties the three tables together.
     */
    public function up(): void
    {
        Schema::create('election_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_id')->unique();
            $table->string('term_label')->nullable();
            // voting_closed → finalized → (or) aborted
            $table->string('status', 32)->default('voting_closed');
            $table->string('election_title')->nullable();

            // The schedule the cycle actually ran under.
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->timestamp('voting_opens_at')->nullable();
            $table->timestamp('voting_closes_at')->nullable();
            $table->timestamp('term_ends_at')->nullable();

            $table->boolean('results_published')->default(false);

            // Turnout. Aggregate only, on purpose: the vote ledger has no
            // user foreign key (anonymity, H-1) and a per-voter turnout list
            // would rebuild exactly the link that design removes.
            $table->unsignedInteger('registered_students')->nullable();
            $table->unsignedInteger('voters_who_voted')->nullable();
            $table->unsignedInteger('votes_cast')->nullable();

            $table->unsignedInteger('candidates_total')->nullable();
            $table->unsignedInteger('seats_filled')->nullable();

            $table->timestamp('snapshot_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->timestamps();

            $table->index('term_label');
            $table->index('status');
        });

        Schema::create('election_cycle_positions', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_id');
            $table->string('position_slug')->nullable();
            $table->string('position_label')->nullable();
            $table->string('position_tier')->nullable();
            $table->unsignedTinyInteger('seat_count')->nullable();
            $table->unsignedTinyInteger('sort_order')->nullable();
            $table->string('scope_type', 32)->nullable();
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2', 32)->nullable();
            $table->string('scope_value_2')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('snapshot_at')->useCurrent();

            $table->index('cycle_id');
        });

        // The join key used to enrich a roster with the final tally when the
        // admin declares the winners. Nullable: rows written before this
        // migration (and rows whose candidate was deleted before the archive
        // ran) simply have no reference.
        if (Schema::hasTable('election_candidate_records')
            && ! Schema::hasColumn('election_candidate_records', 'candidate_ref')) {
            Schema::table('election_candidate_records', function (Blueprint $table) {
                $table->string('candidate_ref')->nullable()->after('cycle_id');
                $table->index('candidate_ref');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('election_cycle_positions');
        Schema::dropIfExists('election_cycles');

        if (Schema::hasTable('election_candidate_records')
            && Schema::hasColumn('election_candidate_records', 'candidate_ref')) {
            Schema::table('election_candidate_records', function (Blueprint $table) {
                $table->dropColumn('candidate_ref');
            });
        }
    }
};
