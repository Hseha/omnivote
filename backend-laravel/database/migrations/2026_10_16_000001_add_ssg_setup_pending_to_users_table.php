<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marks an SSG President whose one-time setup link has not yet been
     * redeemed. While pending, the account keeps working (so the in-app
     * invitation is reachable), but the SSG governance features stay locked
     * until the President sets their own password via the invite.
     *
     * Deliberately separate from `must_change_password`: that gate (security
     * assessment C-1) hard-blocks every student route, including the
     * notification feed that carries the invite, so reusing it would make the
     * link undeliverable. This flag is the narrower, SSG-only equivalent.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('ssg_setup_pending')->default(false)->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('ssg_setup_pending');
        });
    }
};
