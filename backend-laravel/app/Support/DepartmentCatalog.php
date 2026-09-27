<?php

namespace App\Support;

/**
 * The school's full department structure: five Colleges, each offering one or
 * more degree Programs (courses). This is the single source of truth for the
 * admin College → Course cascading dropdowns and for the registrar-import
 * department guard ("an import may never introduce a new department").
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

    /** Degree programs offered under each college (keyed by college code). */
    public const COURSES = [
        ['name' => 'Bachelor of Elementary Education major in General Content', 'college' => 'EDUC'],
        ['name' => 'Bachelor of Secondary Education major in English', 'college' => 'EDUC'],
        ['name' => 'Bachelor of Arts major in Political Science', 'college' => 'CAS'],
        ['name' => 'Bachelor of Arts major in Communication', 'college' => 'CAS'],
        ['name' => 'Bachelor of Science in Information Technology', 'college' => 'CCS'],
        ['name' => 'Bachelor of Science in Criminology', 'college' => 'CCJE'],
        ['name' => 'Bachelor of Science in Office Administration', 'college' => 'BSOA'],
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

    /** All canonical college names, in display order. */
    public static function collegeNames(): array
    {
        return array_column(self::COLLEGES, 'name');
    }

    /** Canonical college name for a raw CSV/store value, or null when unknown. */
    public static function resolveCollege(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        foreach (self::COLLEGES as $college) {
            if (mb_strtolower($college['name']) === mb_strtolower($value)) {
                return $college['name'];
            }
        }

        return self::LEGACY_ALIASES[mb_strtolower($value)] ?? null;
    }

    /** Courses offered by a college, as plain names in display order. */
    public static function coursesOf(string $collegeName): array
    {
        $code = self::codeOf($collegeName);

        return array_values(array_column(array_filter(
            self::COURSES,
            fn (array $c) => $c['college'] === $code
        ), 'name'));
    }

    /** The canonical course name if `$course` is offered under `$collegeName`. */
    public static function resolveCourse(string $collegeName, ?string $course): ?string
    {
        $course = trim((string) $course);
        if ($course === '') {
            return null;
        }

        foreach (self::coursesOf($collegeName) as $offered) {
            if (mb_strtolower($offered) === mb_strtolower($course)) {
                return $offered;
            }
        }

        return null;
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