<?php

namespace App\Support;

use App\Models\Course;
use App\Models\CourseAlias;
use App\Models\Department;
use App\Models\DepartmentAlias;
use Illuminate\Support\Facades\Schema;

/**
 * The school's full department structure: five Colleges, each offering one or
 * more degree Programs (courses). The `departments`/`courses` tables (plus the
 * `department_aliases`/`course_aliases` alias tables) are the source of truth
 * once the schema exists; the constants below remain the canonical seed data
 * and the fallback for schemas/migrations that predate the tables.
 *
 * A department on a user/feed row stores the canonical College NAME, while the
 * `code` is a short legacy/import-friendly alias (e.g. the CSV may say "CCS").
 */
class DepartmentCatalog
{
    /** Colleges with their legacy short codes (DB row order follows this list). */
    public const COLLEGES = [
        ['name' => 'College of Teacher Education', 'code' => 'EDUC'],
        ['name' => 'College of Arts and Sciences', 'code' => 'CAS'],
        ['name' => 'College of Computer Studies', 'code' => 'CCS'],
        ['name' => 'College of Criminal Justice Education', 'code' => 'CCJE'],
        ['name' => 'College of Office Administration', 'code' => 'BSOA'],
    ];

    /**
     * Degree programs offered under each college (keyed by college code).
     *
     * `code` is the short form a registrar feed is likely to use ("BSIT" rather
     * than the full program name). It is matched on import, so a feed may use
     * either and both land on the same canonical name.
     */
    public const COURSES = [
        ['name' => 'Bachelor of Elementary Education major in General Content', 'college' => 'EDUC', 'code' => 'BECED'],
        ['name' => 'Bachelor of Secondary Education major in English', 'college' => 'EDUC', 'code' => 'BSEED'],
        ['name' => 'Bachelor of Arts major in Political Science', 'college' => 'CAS', 'code' => 'BAPS'],
        ['name' => 'Bachelor of Arts major in Communication', 'college' => 'CAS', 'code' => 'BACOM'],
        ['name' => 'Bachelor of Science in Information Technology', 'college' => 'CCS', 'code' => 'BSIT'],
        ['name' => 'Bachelor of Science in Criminology', 'college' => 'CCJE', 'code' => 'BSCRIM'],
        ['name' => 'Bachelor of Science in Office Administration', 'college' => 'BSOA', 'code' => 'BSOA'],
    ];

    private const LEGACY_ALIASES = [
        'educ' => 'College of Teacher Education',
        'cte' => 'College of Teacher Education',
        'cas' => 'College of Arts and Sciences',
        'polsci' => 'College of Arts and Sciences',
        'ccs' => 'College of Computer Studies',
        'ccje' => 'College of Criminal Justice Education',
        'crim' => 'College of Criminal Justice Education',
        'bsoa' => 'College of Office Administration',
    ];

    /**
     * Extra spellings of a course, keyed by college code, for feeds that predate
     * the `code` above or use the program's common name. Mapped to the course
     * `code` so there is one place that knows the real program name.
     */
    private const COURSE_ALIASES = [
        'EDUC' => [
            'beed' => 'BECED',
            'bed' => 'BECED',
            'elementary education' => 'BECED',
            'bsed' => 'BSEED',
            'bssec' => 'BSEED',
            'bs' => 'BSEED',
            'secondary education' => 'BSEED',
        ],
        'CAS' => [
            'bapolsci' => 'BAPS',
            'polsci' => 'BAPS',
            'political science' => 'BAPS',
            'ba' => 'BAPS',
            'bacomm' => 'BACOM',
            'communication' => 'BACOM',
        ],
        'CCS' => [
            'bs in information technology' => 'BSIT',
            'information technology' => 'BSIT',
            'it' => 'BSIT',
        ],
        'CCJE' => [
            'bs criminology' => 'BSCRIM',
            'criminology' => 'BSCRIM',
        ],
        'BSOA' => [
            'bs in office administration' => 'BSOA',
            'office administration' => 'BSOA',
        ],
    ];

    /** The legacy department spellings → canonical college name, for seeding. */
    public static function departmentAliases(): array
    {
        return self::LEGACY_ALIASES;
    }

    /** The extra course spellings, keyed by college code → course code. */
    public static function courseAliases(): array
    {
        return self::COURSE_ALIASES;
    }

    /** All canonical college names, in display order. */
    public static function collegeNames(): array
    {
        if (Schema::hasTable('departments')) {
            $names = Department::query()
                ->orderBy('sort_order')
                ->pluck('name')
                ->filter(fn ($n) => $n !== null && trim((string) $n) !== '')
                ->values();

            if ($names->isNotEmpty()) {
                return $names->all();
            }
        }

        return array_column(self::COLLEGES, 'name');
    }

    /**
     * Canonical college name for a raw CSV/store value, or null when unknown.
     *
     * Consulted from the DB first (canonical name, short code, then the alias
     * table); falls back to the static catalog when the tables are absent —
     * e.g. migrations that run before `departments` exists.
     */
    public static function resolveCollege(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        if (Schema::hasTable('departments')) {
            $key = self::normalizeKey($value);

            $department = Department::query()
                ->whereRaw('LOWER(name) = ?', [$key])
                ->orWhere(function ($query) use ($key) {
                    $query->whereNotNull('code')->whereRaw('LOWER(code) = ?', [$key]);
                })
                ->first();

            if ($department !== null) {
                return $department->name;
            }

            if (Schema::hasTable('department_aliases')) {
                $alias = DepartmentAlias::query()
                    ->where('alias', $key)
                    ->with('department')
                    ->first();
                if ($alias?->department !== null) {
                    return $alias->department->name;
                }
            }
        }

        return self::resolveCollegeFromConstants($value);
    }

    /** Courses offered by a college, as plain names in display order. */
    public static function coursesOf(string $collegeName): array
    {
        if (Schema::hasTable('departments') && Schema::hasTable('courses')) {
            $department = Department::query()->where('name', $collegeName)->first();
            if ($department !== null) {
                $names = $department->courses()
                    ->orderBy('sort_order')
                    ->pluck('name')
                    ->filter(fn ($n) => $n !== null && trim((string) $n) !== '')
                    ->values();

                if ($names->isNotEmpty()) {
                    return $names->all();
                }
            }
        }

        $code = self::codeOf($collegeName);

        return array_values(array_column(array_filter(
            self::COURSES,
            fn (array $c) => $c['college'] === $code
        ), 'name'));
    }

    /**
     * The canonical course name if `$course` is offered under `$collegeName`.
     *
     * Accepts the full program name, its short code ("BSIT"), and the older
     * spellings in the `course_aliases` table (with the static catalog as the
     * fallback when the table is absent), all case- and whitespace-insensitive.
     * A code is only honoured under the college that actually offers it, so
     * "BSIT" cannot silently attach to a Criminology student.
     */
    public static function resolveCourse(string $collegeName, ?string $course): ?string
    {
        $course = trim((string) $course);
        if ($course === '') {
            return null;
        }

        $key = self::normalizeKey($course);

        $hasDepartments = Schema::hasTable('departments');
        $hasCourses = Schema::hasTable('courses');

        if ($hasDepartments && $hasCourses) {
            $department = Department::query()->where('name', $collegeName)->first();
            if ($department === null) {
                // The college itself is unknown — resolveCourseCanonical treats
                // that as a miss regardless of what the constants know.
                return null;
            }

            $fromDb = self::resolveCourseFromDb($department, $key);
            if ($fromDb !== null) {
                return $fromDb;
            }

            // The DB rows may predate the `code`/alias columns (or were kept
            // name-only). Match through the catalog constants and confirm the
            // canonical name is genuinely offered by this college's rows, so a
            // code never attaches to a college that does not run the program.
            $legacy = self::resolveCourseFromConstants($collegeName, $key);
            if ($legacy !== null) {
                $offered = Course::query()
                    ->where('department_id', $department->id)
                    ->where('name', $legacy)
                    ->exists();
                if ($offered) {
                    return $legacy;
                }
            }

            return null;
        }

        return self::resolveCourseFromConstants($collegeName, $key);
    }

    /**
     * Resolve a course against a single college's DB rows only: canonical name
     * first, then its short `code`, then the alias table scoped to that
     * college. Returns null when the phrasing is not one of its programs —
     * mirroring the pre-migration guard "a code is only honoured under the
     * college that offers it".
     */
    private static function resolveCourseFromDb(Department $department, string $key): ?string
    {
        $hasCodeColumn = Schema::hasColumn('courses', 'code');
        $columns = $hasCodeColumn ? ['id', 'name', 'code'] : ['id', 'name'];

        $courses = Course::query()
            ->where('department_id', $department->id)
            ->orderBy('sort_order')
            ->get($columns);

        foreach ($courses as $offered) {
            if (self::normalizeKey($offered->name) === $key) {
                return $offered->name;
            }
        }

        if ($hasCodeColumn) {
            foreach ($courses as $offered) {
                if ($offered->code !== null && self::normalizeKey($offered->code) === $key) {
                    return $offered->name;
                }
            }
        }

        if (Schema::hasTable('course_aliases')) {
            $alias = CourseAlias::query()
                ->where('alias', $key)
                ->whereHas('course', fn ($query) => $query->where('department_id', $department->id))
                ->with('course:id,name')
                ->first();

            if ($alias?->course !== null) {
                return $alias->course->name;
            }
        }

        return null;
    }

    /** The original constants-only resolution, used when the tables are absent. */
    private static function resolveCourseFromConstants(string $collegeName, string $key): ?string
    {
        $code = self::codeOf($collegeName);
        $aliases = self::COURSE_ALIASES[$code] ?? [];

        foreach (self::coursesOf($collegeName) as $offered) {
            if (self::normalizeKey($offered) === $key) {
                return $offered;
            }
        }

        $wanted = $aliases[$key] ?? $key;

        foreach (self::COURSES as $entry) {
            if ($entry['college'] !== $code) {
                continue;
            }
            if (self::normalizeKey($entry['code']) === self::normalizeKey($wanted)
                || self::normalizeKey($entry['name']) === self::normalizeKey($wanted)) {
                return $entry['name'];
            }
        }

        return null;
    }

    /** The constants-only college resolution, used when the tables are absent. */
    private static function resolveCollegeFromConstants(string $value): ?string
    {
        foreach (self::COLLEGES as $college) {
            if (mb_strtolower($college['name']) === mb_strtolower($value)) {
                return $college['name'];
            }
        }

        return self::LEGACY_ALIASES[mb_strtolower($value)] ?? null;
    }

    /** Lowercased and space-collapsed so " B.S.I.T. " style feeds still match. */
    private static function normalizeKey(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }

    /** Legacy short code for a college name (used to seed/look up the DB row). */
    private static function codeOf(string $collegeName): string
    {
        foreach (self::COLLEGES as $college) {
            if ($college['name'] === $collegeName) {
                return $college['code'];
            }
        }

        return '';
    }
}