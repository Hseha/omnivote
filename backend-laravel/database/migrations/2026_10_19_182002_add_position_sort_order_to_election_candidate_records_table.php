<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('election_candidate_records', function (Blueprint $table) {
            // Ballot ordering for the Past Terms archive. Positions can be
            // deleted by a clean-slate reset before a term is reviewed, so the
            // sort key is denormalized onto the snapshot — the same
            // FK-free-by-design rule as the rest of this table.
            $table->unsignedTinyInteger('position_sort_order')->nullable()->after('position_tier');
        });
    }

    public function down(): void
    {
        Schema::table('election_candidate_records', function (Blueprint $table) {
            $table->dropColumn('position_sort_order');
        });
    }
};
