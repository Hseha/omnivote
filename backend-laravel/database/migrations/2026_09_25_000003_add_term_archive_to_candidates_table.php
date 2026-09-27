<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Winner term archiving for the "end of term" lifecycle.
 *
 * Certified winners are flagged with an `archived_at` timestamp and the
 * school-year label of the term they served once the configured Term Ends
 * date passes (or an admin archives the term manually). Archived winners are
 * excluded from the live results + officer roster but remain browsable so
 * an admin can look up who won each school year.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('certified_at');
            $table->string('term_label', 32)->nullable()->after('archived_at');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('candidates', function (Blueprint $table) {
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['archived_at', 'term_label']);
        });
    }
};