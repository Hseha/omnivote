<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Aligns the Security → password policy with the student app so the
     * client-side hint and the server-side validation agree. Previously the
     * admin had saved `passwordMinLength = 12` but the mobile screen told
     * students "at least 8 characters", which caused a confusing "at least
     * 12" error on the change-temporary-password screen.
     *
     * @return void
     */
    public function up(): void
    {
        DB::table('election_settings')
            ->where('key', 'admin.settings.security.passwordMinLength')
            ->update(['value' => '8']);
    }

    public function down(): void
    {
        DB::table('election_settings')
            ->where('key', 'admin.settings.security.passwordMinLength')
            ->update(['value' => '12']);
    }
};
