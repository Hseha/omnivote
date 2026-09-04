<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reconciles the `candidates` table with the domain objects the rest of
     * the codebase already assumes:
     *   - `position_id`   (canonical FK, replaces legacy `ssg_office` text enum)
     *   - `candidate_ref` (opaque UUID used on the anonymous vote ledger)
     *   - `slogan`, `party_name`, `photo_path`, `platform_points`
     *
     * The legacy `ssg_office` column is relaxed to NULL so existing rows stay
     * intact while new applications (which only write `position_id`) succeed.
     */
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->foreignId('position_id')->nullable()->after('user_id')
                ->constrained('positions')->nullOnDelete();
            $table->string('candidate_ref', 36)->nullable()->unique()->after('position_id');
            $table->string('slogan', 255)->nullable()->after('platform_statement');
            $table->string('party_name', 255)->nullable()->after('slogan');
            $table->string('photo_path')->nullable()->after('party_name');
            $table->json('platform_points')->nullable()->after('photo_path');
        });

        // Relax the legacy enum so inserts that only provide position_id succeed.
        // MySQL-specific; the app is MySQL (see .env).
        DB::statement(
            "ALTER TABLE candidates MODIFY ssg_office ENUM("
            . "'President','Vice_President','Secretary','Treasurer','Auditor'"
            . ") NULL DEFAULT NULL"
        );
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropColumn([
                'position_id',
                'candidate_ref',
                'slogan',
                'party_name',
                'photo_path',
                'platform_points',
            ]);
        });
    }
};