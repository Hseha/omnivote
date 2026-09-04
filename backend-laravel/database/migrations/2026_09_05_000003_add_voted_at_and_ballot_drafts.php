<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adds `users.voted_at` (referenced by VoteController but missing from the
     * schema) and creates `ballot_drafts` — a user-scoped, pre-submission draft
     * store that lets `GET /api/ballot/me` return the in-progress ballot. Once
     * submitted only the receipt token is kept; the actual choices live solely
     * in the anonymous `vote_ledger` table.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('voted_at')->nullable()->after('has_voted');
        });

        Schema::create('ballot_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->json('selections')->nullable();     // { position_slug: [candidate_ref, ...] }
            $table->string('status', 20)->default('draft'); // draft | submitted
            $table->string('receipt_token', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ballot_drafts');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('voted_at');
        });
    }
};