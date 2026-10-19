<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * FCM registration tokens for app installs.
 *
 * Each row is one device that opted in to phone push; `token` is unique so a
 * re-login on the same device reassigns the row to the new account instead of
 * leaving a stale token pointing at a signed-out user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token', 512);
            $table->string('platform', 16)->default('android');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique('token');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_tokens');
    }
};
