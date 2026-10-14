<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a `user_agent` column to `audit_logs`.
 *
 * Admin sign-ins are audited through a Tailscale Funnel reverse proxy that
 * strips the real client address (every nginx log line is loopback), so the
 * source IP alone says nothing. The User-Agent is what actually distinguishes
 * the owner's browser from a scanner, so the admin-auth audit stores it.
 *
 * Nullable and additive: existing rows keep working and older code paths that
 * never pass a User-Agent simply store null.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('audit_logs') || Schema::hasColumn('audit_logs', 'user_agent')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('audit_logs') || ! Schema::hasColumn('audit_logs', 'user_agent')) {
            return;
        }

        Schema::table('audit_logs', function (Blueprint $table): void {
            $table->dropColumn('user_agent');
        });
    }
};
