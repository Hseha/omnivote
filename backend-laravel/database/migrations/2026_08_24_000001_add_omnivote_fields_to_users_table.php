<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            Schema::table('users', function (Blueprint $table) {
                $table->string('student_id')->nullable()->unique()->after('id');
                $table->enum('role', ['admin', 'teacher', 'candidate', 'student'])->default('student')->after('student_id');
                $table->boolean('has_voted')->default(false)->after('role');
                $table->index('has_voted', 'users_has_voted_index');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            Schema::table('users', function (Blueprint $table) {
                $table->dropIndex('users_has_voted_index');
                $table->dropUnique(['student_id']);
                $table->dropColumn(['student_id', 'role', 'has_voted']);
            });
        });
    }
};
