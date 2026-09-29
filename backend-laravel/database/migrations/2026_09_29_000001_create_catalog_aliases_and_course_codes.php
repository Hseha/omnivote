<?php

use App\Support\DepartmentCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Completes the normalization started by `2026_09_23_000001`:
 *
 * - `courses.code` — the short registrar-friendly form ("BSIT") now lives on the
 *   course row instead of only in the static catalog.
 * - `department_aliases` / `course_aliases` — the messy legacy spellings that
 *   used to be hardcoded (LEGACY_ALIASES, COURSE_ALIASES) become rows keyed to
 *   the canonical departments/courses, so matching on CSV import is fully
 *   data-driven and admin-maintainable.
 *
 * The static DepartmentCatalog constants remain the single canonical snapshot
 * this migration (and the CatalogSeeder) reads from — there is deliberately no
 * duplicate dataset here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->string('code')->nullable()->after('name');
        });

        Schema::create('department_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });

        Schema::create('course_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });

        $departmentIds = DB::table('departments')->pluck('id', 'code');

        // Backfill the short codes onto courses that predate the `code` column.
        foreach (DepartmentCatalog::COURSES as $course) {
            $departmentId = $departmentIds[$course['college']] ?? null;
            if ($departmentId === null) {
                continue;
            }

            DB::table('courses')
                ->where('department_id', $departmentId)
                ->where('name', $course['name'])
                ->update(['code' => $course['code']]);
        }

        // Map every legacy department spelling onto its canonical college.
        foreach (DepartmentCatalog::departmentAliases() as $alias => $departmentName) {
            $departmentId = DB::table('departments')->where('name', $departmentName)->value('id');
            if ($departmentId === null) {
                continue;
            }

            DB::table('department_aliases')->insert([
                'department_id' => $departmentId,
                'alias' => $alias,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        // Map every legacy course spelling onto the course its college offers,
        // keyed through the short `code` so alias → code → canonical row.
        foreach (DepartmentCatalog::courseAliases() as $collegeCode => $aliases) {
            $departmentId = $departmentIds[$collegeCode] ?? null;
            if ($departmentId === null) {
                continue;
            }

            foreach ($aliases as $alias => $courseCode) {
                $courseId = DB::table('courses')
                    ->where('department_id', $departmentId)
                    ->where('code', $courseCode)
                    ->value('id');
                if ($courseId === null) {
                    continue;
                }

                DB::table('course_aliases')->insert([
                    'course_id' => $courseId,
                    'alias' => strtolower($alias),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('course_aliases');
        Schema::dropIfExists('department_aliases');

        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('code');
        });
    }
};