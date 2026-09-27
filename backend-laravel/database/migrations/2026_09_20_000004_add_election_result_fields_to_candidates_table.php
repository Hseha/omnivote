<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Auto-determined election winners for the results/tally flow.
 *
 * After the admin finalizes results (POST /api/admin/results/finalize),
 * each approved candidate is marked:
 *
 *   - elected  — won its seat outright (top N by votes, N = position seat_count)
 *   - tied     — sits on the seat boundary with equal votes (needs an admin
 *                tie-break via POST /api/admin/candidates/{id}/resolve-tie)
 *   - pending  — not a winner (runner-up, unfilled seat, or not finalized yet)
 *
 * `winner_rank` and `vote_total` are a snapshot of the finalized tally so a
 * recount replaces the row values deterministically instead of mutating state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('election_status', 16)->default('pending')->after('certified_at');
            $table->unsignedInteger('winner_rank')->nullable()->after('election_status');
            $table->unsignedInteger('vote_total')->default(0)->after('winner_rank');
            $table->index('election_status');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['election_status']);
            $table->dropColumn(['election_status', 'winner_rank', 'vote_total']);
        });
    }
};
