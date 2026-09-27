<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the `registration_closed` phase used by the time-driven election
 * timeline for the gap between Registration Closes and Voting Opens (and the
 * period before Registration Opens), where candidacy applications are blocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('phases')->updateOrInsert(
            ['name' => 'registration_closed'],
            ['description' => 'Registration is closed', 'created_at' => now(), 'updated_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('phases')->where('name', 'registration_closed')->delete();
    }
};
