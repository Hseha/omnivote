<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dedupe ledger for the timed vote reminders sent by
 * `omnivote:send-vote-reminders`. One row per user per stage, so the timer
 * firing every few minutes can never message the same voter twice for the
 * same stage.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vote_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('stage', 64);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'stage']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_reminders');
    }
};
