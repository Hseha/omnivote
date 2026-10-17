<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent per-cycle record of every candidate (winners and non-winners,
     * all parties) so a clean-slate election reset never destroys history.
     * Denormalized and foreign-key free: the record must outlive the live
     * candidates rows and any later resets.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('election_candidate_records', function (Blueprint $table) {
            $table->id();
            $table->string('term_label');
            $table->string('cycle_id');
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('student_id')->nullable();
            $table->string('department')->nullable();
            $table->string('year_level')->nullable();
            $table->string('position_slug')->nullable();
            $table->string('position_label')->nullable();
            $table->string('position_tier')->nullable();
            $table->string('party_name')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('election_status')->nullable();
            $table->integer('vote_total')->default(0);
            $table->integer('winner_rank')->nullable();
            $table->boolean('certified_winner')->default(false);
            $table->text('slogan')->nullable();
            $table->timestamp('snapshot_at')->useCurrent();
            $table->index(['term_label', 'cycle_id']);
            $table->index('party_name');
            $table->index('election_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('election_candidate_records');
    }
};
