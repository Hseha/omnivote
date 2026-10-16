<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks how many times a candidate's application has been rejected in the
 * current election cycle. At 3 rejections the student is locked out from
 * reapplying for 24 hours (anti-spam guard). Both columns are cleared on
 * election reset so a new cycle starts with a clean slate.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->unsignedInteger('rejection_count')->default(0)->after('rejection_reason');
            $table->timestamp('reapply_blocked_until')->nullable()->after('rejection_count');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn(['rejection_count', 'reapply_blocked_until']);
        });
    }
};
