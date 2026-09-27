<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User Management screen support columns:
 *   - department          — registrar CSV "Department"/"College" column (filterable)
 *   - failed_login_attempts / locked_until — per-account lockout after 5
 *                           failed admin logins (15-minute cooldown)
 *   - needs_review / review_reason — flags rows that provisioned with
 *                           incomplete data so they are visibly actionable
 *                           instead of rendering as blank "—" names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('department', 100)->nullable()->after('block_number');
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('department');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->boolean('needs_review')->default(false)->after('locked_until');
            $table->string('review_reason')->nullable()->after('needs_review');
            $table->index('department');
            $table->index('needs_review');
        });

        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->string('department', 100)->nullable()->after('block_number');
            $table->boolean('needs_review')->default(false)->after('department');
            $table->string('review_reason')->nullable()->after('needs_review');
            $table->index('needs_review');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['department']);
            $table->dropIndex(['needs_review']);
            $table->dropColumn(['department', 'failed_login_attempts', 'locked_until', 'needs_review', 'review_reason']);
        });

        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->dropIndex(['needs_review']);
            $table->dropColumn(['department', 'needs_review', 'review_reason']);
        });
    }
};
