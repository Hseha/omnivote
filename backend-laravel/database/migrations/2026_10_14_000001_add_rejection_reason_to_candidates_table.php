<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional committee-supplied explanation shown to the applicant on the
 * student-facing "My Candidacy" screen. Cleared whenever the application is
 * re-approved so a stale reason never lingers on an active candidate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->string('rejection_reason', 1000)->nullable()->after('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn('rejection_reason');
        });
    }
};
