<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A self-chosen login handle for the SSG President web console.
     *
     * Student handles are name-derived slugs stored in `email`; the governance
     * console instead lets the seated President pick a username when they redeem
     * their one-time setup link (a provisional value is provisioned at grant
     * time so there is something to change). Both the password login and this
     * column are unique identifiers: login accepts either, so the account keeps
     * working on mobile with its existing email while the console uses the
     * username.
     *
     * Nullable because no existing account has one, and unique so two accounts
     * can never collide on the same handle.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};
