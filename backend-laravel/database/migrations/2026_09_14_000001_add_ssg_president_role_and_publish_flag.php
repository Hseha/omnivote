<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','teacher','candidate','student','ssg_president') NOT NULL DEFAULT 'student'");
        }

        Schema::table('election_settings', function (Blueprint $table): void {
            $table->text('value')->nullable()->change();
        });

        DB::table('election_settings')->updateOrInsert(
            ['key' => 'results_published'],
            ['value' => '0', 'updated_at' => now(), 'created_at' => now()],
        );
    }

    public function down(): void
    {
        DB::table('election_settings')->where('key', 'results_published')->delete();

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','teacher','candidate','student') NOT NULL DEFAULT 'student'");
        }
    }
};
