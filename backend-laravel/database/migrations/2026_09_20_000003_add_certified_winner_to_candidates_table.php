<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certified-winner tracking for the SSG President grant flow.
 * A candidate row is flagged by an admin once results are ratified;
 * only candidates with certified_winner = true may be granted the
 * ssg_president role on their linked user account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->boolean('certified_winner')->default(false)->after('approval_status');
            $table->timestamp('certified_at')->nullable()->after('certified_winner');
            $table->index('certified_winner');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['certified_winner']);
            $table->dropColumn(['certified_winner', 'certified_at']);
        });
    }
};
