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
            Schema::create('registrar_imports', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->string('student_id')->unique();
                $table->string('full_name');
                $table->string('grade_level');
                $table->timestamps();
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function () {
            Schema::dropIfExists('registrar_imports');
        });
    }
};
