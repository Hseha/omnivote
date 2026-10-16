<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The admin dashboard's "Hourly Voting Velocity" panel filters `vote_ledger`
 * by `recorded_at` over a trailing 24 h window and buckets by a formatted
 * `recorded_at` expression. The table shipped with indexes only on
 * `(position_key, candidate_ref)` and `receipt_hmac`, so that range predicate
 * had no supporting index and every dashboard load full-table-scanned and
 * filesorted the largest, fastest-growing table in the app.
 *
 * A composite `(recorded_at, receipt_hmac)` index lets MariaDB/MySQL satisfy
 * the range filter from the index while still covering the `COUNT(DISTINCT
 * receipt_hmac)` aggregate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vote_ledger', function (Blueprint $table) {
            $table->index(['recorded_at', 'receipt_hmac'], 'vote_ledger_recorded_at_receipt_index');
        });
    }

    public function down(): void
    {
        Schema::table('vote_ledger', function (Blueprint $table) {
            $table->dropIndex('vote_ledger_recorded_at_receipt_index');
        });
    }
};
