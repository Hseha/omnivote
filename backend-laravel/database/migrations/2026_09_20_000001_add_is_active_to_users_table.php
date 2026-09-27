<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the `is_active` boolean to `users` so administrators can disable
 * compromised or departed accounts without deleting them (preserving vote-audit
 * integrity). Inactive users are rejected by both admin and student login flows.
 *
 * Backfills every existing account as active on upgrade.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('has_voted');
            }
        });

        // Existing accounts (created before this flag existed) remain active.
        DB::table('users')
            ->whereNull('is_active')
            ->update(['is_active' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
