<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `avatar_url` text column to `users` backing the Settings → Profile
 * avatar picker (DiceBear pixel-art URLs or compact PNG data URLs, per the
 * frontend cap). Nullable with no backfill: accounts created before this
 * column keep falling back to initials/DiceBear like before.
 *
 * Append-only on purpose: the whole 2FA/settings WIP in this branch treats
 * these small column migrations as one-shot, individually revertible steps
 * (see the 2FA columns migration), so avatar_url gets its own migration rather
 * than being folded into an earlier file.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('avatar_url')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('avatar_url');
        });
    }
};
