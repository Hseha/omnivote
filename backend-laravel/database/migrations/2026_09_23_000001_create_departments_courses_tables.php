<?php

use App\Support\DepartmentCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        // Seed the school's real structure: five Colleges ... seven Programs.
        DB::table('departments')->insert(array_map(
            fn (array $c, int $i) => [
                'name' => $c['name'],
                'code' => $c['code'],
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            DepartmentCatalog::COLLEGES,
            array_keys(DepartmentCatalog::COLLEGES)
        ));

        $departmentIds = DB::table('departments')->pluck('id', 'code');

        DB::table('courses')->insert(array_map(
            fn (array $c, int $i) => [
                'department_id' => $departmentIds[$c['college']],
                'name' => $c['name'],
                'sort_order' => $i + 1,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            DepartmentCatalog::COURSES,
            array_keys(DepartmentCatalog::COURSES)
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('courses');
        Schema::dropIfExists('departments');
    }
};