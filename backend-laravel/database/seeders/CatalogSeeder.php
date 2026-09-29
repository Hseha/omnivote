<?php

namespace Database\Seeders;

use App\Models\Course;
use App\Models\Department;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds (or refreshes) the school's structural catalog from the canonical
 * DepartmentCatalog snapshot:
 *
 * - departments: the five Colleges, keyed by canonical name.
 * - courses: every program offered under a college, carrying its short `code`.
 * - department_aliases / course_aliases: the messy legacy spellings that the
 *   registrar CSV importer resolves against.
 *
 * Idempotent — it uses updateOrCreate keyed on the canonical identity, so a
 * re-run never duplicates rows (admin-added departments/courses/aliases
 * survive). The migration `2026_09_23_000001` already seeds the base tables on
 * a fresh install; this seeder exists so the catalog can be refreshed without
 * rolling back a migration.
 */
class CatalogSeeder extends Seeder
{
    public function run(): void
    {
        $departments = [];

        foreach (DepartmentCatalog::COLLEGES as $i => $college) {
            $departments[$college['code']] = Department::updateOrCreate(
                ['name' => $college['name']],
                [
                    'code' => $college['code'],
                    'sort_order' => isset($college['sort_order'])
                        ? $college['sort_order']
                        : $i + 1,
                ]
            );
        }

        foreach (DepartmentCatalog::COURSES as $i => $course) {
            $department = $departments[$course['college']] ?? null;
            if ($department === null) {
                continue;
            }

            Course::updateOrCreate(
                ['name' => $course['name']],
                [
                    'department_id' => $department->id,
                    'code' => $course['code'],
                    'sort_order' => $i + 1,
                ]
            );
        }

        foreach (DepartmentCatalog::departmentAliases() as $alias => $departmentName) {
            $department = Department::where('name', $departmentName)->first();
            if ($department === null) {
                continue;
            }

            $department->aliases()->updateOrCreate(
                ['alias' => strtolower($alias)],
                []
            );
        }

        foreach (DepartmentCatalog::courseAliases() as $collegeCode => $aliases) {
            $department = $departments[$collegeCode] ?? Department::where('code', $collegeCode)->first();
            if ($department === null) {
                continue;
            }

            foreach ($aliases as $alias => $courseCode) {
                $course = Course::where('department_id', $department->id)
                    ->where('code', $courseCode)
                    ->first();
                if ($course === null) {
                    continue;
                }

                $course->aliases()->updateOrCreate(
                    ['alias' => strtolower($alias)],
                    []
                );
            }
        }
    }
}