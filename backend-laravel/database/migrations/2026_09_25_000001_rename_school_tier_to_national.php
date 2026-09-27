<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Naming pass: the school-wide tier is "national" everywhere now, not
     * "school". Widen the MySQL enum, migrate existing rows, then narrow it
     * back so invalid values can't sneak in later.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE positions MODIFY tier ENUM('national','provincial','school') NOT NULL DEFAULT 'national'");
            DB::table('positions')->where('tier', 'school')->update(['tier' => 'national']);
            DB::statement("ALTER TABLE positions MODIFY tier ENUM('national','provincial') NOT NULL DEFAULT 'national'");
        } else {
            Schema::table('positions', function (Blueprint $table) {
                $table->enum('tier', ['national', 'provincial', 'school'])->default('national')->change();
            });
            DB::table('positions')->where('tier', 'school')->update(['tier' => 'national']);
            Schema::table('positions', function (Blueprint $table) {
                $table->enum('tier', ['national', 'provincial'])->default('national')->change();
            });
        }
    }

    public function down(): void
    {
        // Reverse: widen to re-add the old value, migrate rows back, narrow.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE positions MODIFY tier ENUM('national','provincial','school') NOT NULL DEFAULT 'national'");
            DB::table('positions')->where('tier', 'national')->update(['tier' => 'school']);
            DB::statement("ALTER TABLE positions MODIFY tier ENUM('school','provincial') NOT NULL DEFAULT 'school'");
        } else {
            Schema::table('positions', function (Blueprint $table) {
                $table->enum('tier', ['national', 'provincial', 'school'])->default('national')->change();
            });
            DB::table('positions')->where('tier', 'national')->update(['tier' => 'school']);
            Schema::table('positions', function (Blueprint $table) {
                $table->enum('tier', ['school', 'provincial'])->default('school')->change();
            });
        }
    }
};