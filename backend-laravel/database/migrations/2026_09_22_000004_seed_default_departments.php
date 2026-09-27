<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const DEPARTMENT_SETTING_KEY = 'admin.departments.list';

    private const DEFAULT_DEPARTMENTS = ['CCS', 'CCJE', 'BSOA', 'EDUC', 'PolSci', 'CAS'];

    public function up(): void
    {
        if (DB::table('election_settings')->where('key', self::DEPARTMENT_SETTING_KEY)->exists()) {
            return;
        }

        DB::table('election_settings')->insert([
            'key' => self::DEPARTMENT_SETTING_KEY,
            'value' => json_encode(self::DEFAULT_DEPARTMENTS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('election_settings')->where('key', self::DEPARTMENT_SETTING_KEY)->delete();
    }
};