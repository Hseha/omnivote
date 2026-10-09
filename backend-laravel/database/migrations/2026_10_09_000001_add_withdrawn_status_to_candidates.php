<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Widen approval_status to allow students to withdraw/forfeit a candidacy
     * (self-service cancel). Existing rows keep their value; only the enum
     * definition changes (MySQL) or the column widens (other drivers).
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE candidates MODIFY approval_status ENUM('pending','approved','rejected','withdrawn') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('candidates', function (Blueprint $table) {
                $table->enum('approval_status', ['pending', 'approved', 'rejected', 'withdrawn'])
                    ->default('pending')
                    ->change();
            });
        }
    }

    public function down(): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            // Widen to hold the legacy value, migrate rows back, narrow again.
            DB::statement("ALTER TABLE candidates MODIFY approval_status ENUM('pending','approved','rejected','withdrawn','legacy') NOT NULL DEFAULT 'pending'");
            DB::table('candidates')
                ->where('approval_status', 'withdrawn')
                ->update(['approval_status' => 'rejected']);
            DB::statement("ALTER TABLE candidates MODIFY approval_status ENUM('pending','approved','rejected','legacy') NOT NULL DEFAULT 'pending'");
            DB::statement("ALTER TABLE candidates MODIFY approval_status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending'");
        } else {
            Schema::table('candidates', function (Blueprint $table) {
                $table->enum('approval_status', ['pending', 'approved', 'rejected'])->default('pending')->change();
            });
        }
    }
};