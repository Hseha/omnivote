<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Retires the legacy `admin.departments.list` election setting.
     *
     * The key was introduced when departments were held in `election_settings`
     * as a plain string list of short codes. The `departments`/`courses` tables
     * (and their alias tables) are the source of truth now, and no application
     * code reads the stale key — the registrar import guard even asserts that
     * the importer never touches it. This migration purges whatever value an
     * earlier install seeded so the dead config does not linger.
     */
    public function up(): void
    {
        if (Schema::hasTable('election_settings')) {
            DB::table('election_settings')->where('key', 'admin.departments.list')->delete();
        }
    }

    public function down(): void
    {
        // The key is retired; rolling back intentionally does not re-seed it.
    }
};