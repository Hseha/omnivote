<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Archive users (soft-archive) so they disappear from the dashboard's
     * Total Users and all per-role counts without destroying the row (which
     * would orphan candidates, votes, notifications, and audit history).
     *
     * Archiving sets `archived_at` AND flips `is_active` off, so the existing
     * is_active sign-in guards already block the account everywhere; the
     * timestamp is the marker every count/listing filters on.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};