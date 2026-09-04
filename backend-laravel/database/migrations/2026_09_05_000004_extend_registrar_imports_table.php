<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extends `registrar_imports` with the email/role columns required by the
     * CSV import (docs/Admin Registry: Student ID, Full Name, Email Address,
     * Grade Level, Role). These rows are the eligibility feed that
     * `StudentRegistrationRequest` validates `student_id` against.
     */
    public function up(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->string('email')->nullable()->after('full_name');
            $table->enum('role', ['student', 'teacher'])->default('student')->after('grade_level');
        });
    }

    public function down(): void
    {
        Schema::table('registrar_imports', function (Blueprint $table) {
            $table->dropColumn(['email', 'role']);
        });
    }
};