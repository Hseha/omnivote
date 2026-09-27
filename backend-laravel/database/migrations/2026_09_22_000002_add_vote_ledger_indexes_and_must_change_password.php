<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Harden the two hot paths identified in the backend infrastructure audit:
 *
 *  - `vote_ledger` previously had zero indexes, so every leaderboard, receipt
 *    lookup and SSG roster full-table-scanned the largest table in the app.
 *    Two covering indexes now serve those queries.
 *
 *  - `users.must_change_password` backs the "set your own password on first
 *    login" flow for registrar-provisioned accounts (the old behaviour used
 *    the student ID as the permanent password, which is public data).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vote_ledger', function (Blueprint $table) {
            $table->index(['position_key', 'candidate_ref'], 'vote_ledger_position_candidate_index');
            $table->index('receipt_hmac');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });

        Schema::table('vote_ledger', function (Blueprint $table) {
            $table->dropIndex('vote_ledger_position_candidate_index');
            $table->dropIndex('vote_ledger_receipt_hmac_index');
        });
    }
};