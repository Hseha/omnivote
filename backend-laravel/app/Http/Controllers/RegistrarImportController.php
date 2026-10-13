<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarImportRequest;
use App\Models\Course;
use App\Models\Department;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use App\Support\Notifier;
use App\Support\RegistrarCode;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Registrar CSV import + eligibility feed.
 *
 * Flow: admin uploads CSV (Student ID, Full Name, Email Address, Role) plus
 * optional section columns (Year Level, Block Number) from the
 * React Student Registry screen → rows are upserted into `registrar_imports`
 * → matching `users` accounts are provisioned (carrying the section metadata)
 * with a temporary password → students sign in on the Flutter app immediately.
 */
class RegistrarImportController extends Controller
{
    /**
     * POST /api/admin/registrar/import  (multipart, field name: `file`)
     */
    public function import(RegistrarImportRequest $request): JsonResponse
    {
        $file = $request->validated('file');
        $handle = fopen($file->getRealPath(), 'r');

        if ($handle === false) {
            return response()->json(['message' => 'Could not read the uploaded file.'], 422);
        }

        $headers = array_map('strtolower', array_map('trim', fgetcsv($handle) ?? []));
        $required = ['student id', 'full name', 'year level', 'department', 'block number'];
        $headerMap = [];
        foreach ($required as $needle) {
            foreach ($headers as $i => $header) {
                if (str_contains($header, $needle)) {
                    $headerMap[$needle] = $i;
                    break;
                }
            }
        }
        // Accept the new "Student Name" header as an alias for the name column
        // (and a bare "Name" for good measure) so both old and new CSVs import.
        if (! isset($headerMap['full name'])) {
            foreach ($headers as $i => $header) {
                if (str_contains($header, 'student name') || $header === 'name') {
                    $headerMap['full name'] = $i;
                    break;
                }
            }
        }
        if (count($headerMap) !== 5) {
            fclose($handle);

            return response()->json([
                'message' => 'CSV headers must include: Student ID, Student Name, Year Level, Department, and Block Number.',
            ], 422);
        }

        // Optional Course column (full program name, e.g. "Bachelor of Science
        // in Information Technology"). When present, a course must be one the
        // row's college actually offers.
        $courseColumn = null;
        foreach ($headers as $i => $header) {
            if (str_contains($header, 'course')) {
                $courseColumn = $i;
                break;
            }
        }

        $rows = [];
        $seenStudentIds = [];
        $duplicateInFile = 0;
        $duplicateLines = [];
        $rejectedRows = [];
        // Accounts may only carry departments that already exist (the catalog
        // of Colleges plus values already present on users or in the
        // eligibility feed). An import must NEVER introduce a new department:
        // unknown values are flagged and left unprovisioned. Legacy codes such
        // as "CCS" are accepted and normalized to the canonical college name.
        $knownDepartments = $this->knownDepartments();
        $line = 2;
        while (($data = fgetcsv($handle)) !== false) {
            $studentId = trim((string) ($data[$headerMap['student id']] ?? ''));
            if ($studentId === '') {
                $line++;

                continue;
            }

            $formatProblem = self::studentIdFormatProblem($studentId);
            if ($formatProblem !== null) {
                $rejectedRows[] = [
                    'line' => $line,
                    'column' => 'Student ID',
                    'student_id' => $studentId,
                    'reason' => $formatProblem,
                ];
                $line++;

                continue;
            }

            if (isset($seenStudentIds[$studentId])) {
                $duplicateInFile++;
                $duplicateLines[] = $line;
                $line++;

                continue;
            }
            $seenStudentIds[$studentId] = true;

            // Block number is strictly numeric: accept "1", "Block 1", "block 2"
            // (normalized to the bare digits) and reject alphabetic-only values
            // like "A" or "Block B". A blank cell is treated as a missing value.
            $rawBlock = trim((string) ($data[$headerMap['block number']] ?? ''));
            $blockResult = $this->normalizeBlockNumber($rawBlock);
            if ($blockResult['error'] !== null) {
                $rejectedRows[] = [
                    'line' => $line,
                    'column' => 'Block Number',
                    'student_id' => $studentId,
                    'reason' => $blockResult['error'],
                ];
                $line++;

                continue;
            }

            $fullName = trim((string) ($data[$headerMap['full name']] ?? ''));
            $yearLevel = trim((string) ($data[$headerMap['year level']] ?? ''));
            $rawDepartment = trim((string) ($data[$headerMap['department']] ?? ''));
            // Canonical college name: "CCS" and "College of Computer Studies"
            // both resolve here; anything unmappable keeps its literal value
            // (so it is either a known value or gets flagged below).
            $department = DepartmentCatalog::resolveCollege($rawDepartment) ?? $rawDepartment;
            $course = $courseColumn !== null
                ? trim((string) ($data[$courseColumn] ?? ''))
                : '';
            // A short code ("BSIT") or a full program name both land on the
            // canonical name; a genuine miss comes back null and is surfaced
            // as a non-blocking warning below.
            $resolvedCourse = $this->resolveCourseCanonical($department, $course);

            // Row cells must be complete so User Management never shows blank
            // records: every required column must carry a value or the row is
            // imported to the feed, flagged for review, and never provisions an
            // account. Login emails are derived from the name (see
            // usernameFromName), so a missing name can never be assigned one.
            $reviewReasons = [];
            if ($fullName === '') {
                $reviewReasons[] = "Line {$line}, column 'Student Name': missing student name in registrar CSV.";
            }
            if ($yearLevel === '') {
                $reviewReasons[] = "Line {$line}, column 'Year Level': missing year level in registrar CSV.";
            }
            if ($department === '') {
                $reviewReasons[] = "Line {$line}, column 'Department': missing department in registrar CSV.";
            }
            if ($department !== '' && ! in_array($department, $knownDepartments, true)) {
                $reviewReasons[] = "Line {$line}, column 'Department': department '{$rawDepartment}' is not in the current department list.";
            }
            if ($course !== '') {
                if ($department === '') {
                    $reviewReasons[] = "Line {$line}, column 'Course': a course was given but the department is missing.";
                } elseif (! in_array($department, $knownDepartments, true)) {
                    $reviewReasons[] = "Line {$line}, column 'Course': cannot resolve course '{$course}' for an unknown department.";
                }
            }
            if ($blockResult['value'] === null) {
                $reviewReasons[] = "Line {$line}, column 'Block Number': missing block number in registrar CSV.";
            }

            // An unrecognised course is deliberately NOT a review reason: those
            // block provisioning (see the $row['_needs_review'] gate), and a
            // typo in the Course column must never be the reason a student
            // does not get an account. It is recorded as a warning instead, so
            // the registrar sees the problem without the import costing anyone
            // their access.
            $courseWarning = null;
            if ($course !== ''
                && $resolvedCourse === null
                && $department !== ''
                && in_array($department, $knownDepartments, true)) {
                $courseWarning = "Course '{$course}' is not offered by {$department}; stored as typed.";
            }

            $rows[] = [
                'student_id' => $studentId,
                'full_name' => $fullName,
                'email' => null,
                'year_level' => $yearLevel,
                'block_number' => $blockResult['value'],
                'department' => $department,
                'course' => $resolvedCourse ?? ($course !== '' ? $course : null),
                '_line' => $line,
                '_needs_review' => $reviewReasons !== [],
                // The feed row keeps the warning even on a provisioned row, so
                // the registrar can trace where the odd course value came from.
                '_review_reason' => $reviewReasons || $courseWarning
                    ? implode(' ', array_merge($reviewReasons, array_filter([$courseWarning])))
                    : null,
                '_course_unmatched' => $courseWarning !== null,
                '_unknown_department' => $department !== '' && ! in_array($department, $knownDepartments, true),
            ];
            $line++;
        }
        fclose($handle);

        if (empty($rows)) {
            return response()->json(['message' => 'No valid rows found in the CSV.'], 422);
        }

        $created = 0;
        $updated = 0;
        $accountsProvisioned = 0;
        $accountsUpdated = 0;
        $skipped = 0;
        $skippedUnknownDepartment = 0;
        $flaggedForReview = 0;
        $unmatchedCourses = 0;
        $temporaryCredentials = [];
        $issuedCodes = [];
        // Rows this import updated but deliberately did NOT re-code (see step 1).
        $codesPreserved = 0;
        $usedEmails = [];

        // NOTE: every variable the closure writes must be listed by reference
        // below. A closure gets no access to the enclosing scope in PHP, so a
        // missing `&$name` silently throws the writes away — which is exactly how
        // `activation_codes` came back empty to every registrar while the codes
        // were still being generated and hashed in the database.
        DB::transaction(function () use ($rows, &$created, &$updated, &$accountsProvisioned, &$accountsUpdated, &$skipped, &$skippedUnknownDepartment, &$flaggedForReview, &$unmatchedCourses, &$rejectedRows, &$temporaryCredentials, &$issuedCodes, &$codesPreserved, &$usedEmails) {
            foreach ($rows as $row) {
                // The login email is derived from the student's name — it is
                // never read from the CSV — so a row without a name can never
                // be assigned an account (feed-only, flagged in step 1).
                $email = $row['full_name'] !== '' ? $this->uniqueEmail($row['full_name'], $row['student_id'], $usedEmails) : null;

                $importPayload = [
                    'full_name' => $row['full_name'],
                    'email' => $email,
                    'year_level' => $row['year_level'],
                    'block_number' => $row['block_number'],
                    'department' => $row['department'] !== '' ? $row['department'] : null,
                    'course' => $row['course'],
                    'role' => 'student',
                    'needs_review' => $row['_needs_review'],
                    'review_reason' => $row['_review_reason'],
                ];

                // 1. Upsert the eligibility feed row.
                $existingImport = RegistrarImport::where('student_id', $row['student_id'])->first();
                if ($existingImport) {
                    $existingImport->update($importPayload);
                    $importRow = $existingImport;
                    $updated++;
                } else {
                    $importRow = RegistrarImport::create(array_merge(
                        ['student_id' => $row['student_id']],
                        $importPayload
                    ));
                    $created++;
                }

                // Issue a registrar activation / recovery code (assessment M-3 +
                // M-5) — for a row THIS import created, and only for such a row.
                //
                // A code is a one-time secret the registrar hands to a student, so
                // replacing one silently invalidates the sheet already in that
                // student's hands. Every row used to be re-coded on every import:
                // a routine re-import (one new student, a refreshed year level)
                // invalidated every code in the file, and nothing said so. Rows
                // that already exist keep their code, and the count is reported in
                // `activation_codes_preserved` below. Replacing a code stays an
                // explicit action:
                //   POST /api/admin/registrar/imports/{import}/issue-code   (one row)
                //   POST /api/admin/registrar/imports/issue-codes           (bulk —
                //   also the way to code rows that have none, or spent one)
                // The plaintext is still handed to the admin ONCE, in
                // `activation_codes` below, and only a bcrypt hash is stored, so
                // possession of the database does not let anyone activate or
                // reset a student account.
                if ($importRow->wasRecentlyCreated) {
                    $issuedCodes[] = [
                        'student_id' => $row['student_id'],
                        'full_name' => $row['full_name'],
                        'activation_code' => RegistrarCode::issue($importRow),
                    ];
                } else {
                    $codesPreserved++;
                }

                if ($row['_course_unmatched'] ?? false) {
                    $unmatchedCourses++;
                }

                if ($row['_needs_review']) {
                    $flaggedForReview++;
                    if ($row['_unknown_department']) {
                        // Unknown departments are never provisioned and never
                        // added to the department list — the admin must add them
                        // deliberately via the Departments screen first.
                        $skippedUnknownDepartment++;
                    } else {
                        // Incomplete rows are stored in the feed and flagged, but
                        // never provision an account NOR touch an existing one —
                        // User Management must never show blank fields.
                        $skipped++;
                    }

                    continue;
                }

                // 2. A student is identified by their ID, so an account is ONLY
                // ever matched by student_id. Trusting a lone email hit would
                // silently overwrite a DIFFERENT student's account (the address
                // may be reused, or the CSV's ID/card may not exist yet), which
                // is worse than skipping — so accounts are never matched by email.
                $user = User::where('student_id', $row['student_id'])->first();

                if ($user) {
                    $user->update([
                        // Blank CSV cells must not wipe existing good values.
                        'name' => $row['full_name'] !== '' ? $row['full_name'] : $user->name,
                        'year_level' => $row['year_level'],
                        'block_number' => $row['block_number'],
                        'department' => $row['department'] !== '' ? $row['department'] : $user->department,
                        'course' => $row['course'] !== null ? $row['course'] : $user->course,
                        // A corrected re-import (complete row) clears the flag.
                        'needs_review' => $row['_needs_review'],
                        'review_reason' => $row['_review_reason'],
                        'role' => in_array($user->role, ['teacher', 'admin'], true)
                            ? $user->role
                            : 'student',
                    ]);
                    // Counted separately from the eligibility-feed update above:
                    // one re-imported row touches both the feed row and the
                    // existing account, and conflating the two made a single
                    // row report as "2 updated".
                    $accountsUpdated++;

                    continue;
                }

                // Rows reaching here are complete (no review flags) — a login email has
                // already been derived from the name, so provisioning is safe.
                //
                // The temporary password MUST be random (security assessment C-1):
                // it used to be the student ID, which is public data printed on
                // class lists, so anyone holding the list could sign in as the
                // student and cast their ballot. The value is returned once in
                // `temporary_credentials` for the admin to distribute.
                $tempPassword = TemporaryPassword::generate();
                User::create([
                    'student_id' => $row['student_id'],
                    'name' => $row['full_name'],
                    'email' => $email,
                    'password' => Hash::make($tempPassword),
                    'role' => 'student',
                    'year_level' => $row['year_level'],
                    'block_number' => $row['block_number'],
                    'department' => $row['department'] !== '' ? $row['department'] : null,
                    'course' => $row['course'],
                    'needs_review' => $row['_needs_review'],
                    'review_reason' => $row['_review_reason'],
                    'has_voted' => false,
                    'must_change_password' => true,
                ]);
                $accountsProvisioned++;
                $temporaryCredentials[] = [
                    'student_id' => $row['student_id'],
                    'full_name' => $row['full_name'],
                    'email' => $email,
                    'year_level' => $row['year_level'],
                    'block_number' => $row['block_number'],
                    'department' => $row['department'] !== '' ? $row['department'] : null,
                    'course' => $row['course'],
                    'temp_password' => $tempPassword,
                ];
            }
        });

        $this->notifyImportSummary($accountsProvisioned, $created, $updated, $flaggedForReview, $skipped, $skippedUnknownDepartment, $unmatchedCourses, $accountsUpdated);

        return response()->json([
            'message' => $codesPreserved > 0
                ? 'Import completed. Activation codes were issued only for the rows this import added; the '
                    .$codesPreserved.' existing code(s) were left unchanged — re-issue deliberately if a sheet leaked.'
                : 'Import completed.',
            'summary' => [
                'total_records' => count($rows),
                'created_eligibility_rows' => $created,
                'updated_eligibility_rows' => $updated,
                'accounts_provisioned' => $accountsProvisioned,
                // Existing accounts refreshed by this import (matched by
                // student_id). Reported separately so a re-import of an
                // already-provisioned student no longer reads as extra
                // eligibility rows.
                'accounts_updated' => $accountsUpdated,
                'duplicates_within_file' => $duplicateInFile,
                // Exact CSV lines (including the header offset) dropped as
                // in-file duplicate IDs, so the registrar can find them.
                'duplicate_lines' => array_values($duplicateLines),
                // Incomplete rows (missing required cell) — feed-only, never provisioned.
                'skipped_incomplete' => $skipped,
                // Rows whose department isn't in the current department list —
                // never provisioned, never added as a new department.
                'skipped_unknown_department' => $skippedUnknownDepartment,
                'email_conflict_skipped' => 0,
                'flagged_for_review' => $flaggedForReview,
                // Accounts were still created for these; the course value was
                // kept as typed because the college does not offer it.
                'courses_not_offered' => $unmatchedCourses,
                'rejected_rows' => $rejectedRows,
                // Codes issued for the rows this import created; the plaintext
                // values are in `activation_codes` below. Codes on rows that
                // already existed are deliberately left alone, so a re-import can
                // never invalidate a sheet that is already in a student's hands.
                'activation_codes_issued' => count($issuedCodes),
                'activation_codes_preserved' => $codesPreserved,
            ],
            'temporary_credentials' => $temporaryCredentials,
            // Registrar-issued activation / account-recovery codes. Plaintext,
            // shown once, never stored (assessment M-3 / M-5) — and now only the
            // newly added rows appear here.
            'activation_codes' => $issuedCodes,
        ], 201);
    }

    /**
     * Notify panel admins that a registrar import just completed.
     */
    private function notifyImportSummary(int $provisioned, int $created, int $updated, int $flagged, int $skipped, int $skippedUnknownDepartment, int $unmatchedCourses = 0, int $accountsUpdated = 0): void
    {
        Notifier::toAdmins(
            'notifyOnRegistration',
            'info',
            'Registrar import completed',
            'Provisioned '.$provisioned.' account(s); '.$created.' eligibility row(s) created, '
                .$updated.' updated, '.$flagged.' flagged for review'
                .($accountsUpdated > 0 ? ', '.$accountsUpdated.' existing account(s) updated from an earlier import' : '')
                .($skipped > 0 ? ', '.$skipped.' incomplete row(s) left unprovisioned.' : '.')
                .($skippedUnknownDepartment > 0 ? ' '.$skippedUnknownDepartment.' row(s) skipped for unknown department.' : '')
                .($unmatchedCourses > 0 ? ' '.$unmatchedCourses.' row(s) carry a course that the college does not offer; check the Course values.' : ''),
            '/users',
        );
    }

    /**
     * Departments an import may assign to accounts: the `departments` table
     * (the seeded catalog plus anything an admin added — source of truth,
     * falling back to the static catalog when the table is absent) plus any
     * values already present on users or in the eligibility feed (resolved to
     * their canonical college names). An import never adds to this set —
     * unknown departments are flagged and left unprovisioned instead of
     * quietly creating new department values.
     */
    /**
     * Resolve a CSV course to its canonical program, or null when unknown.
     *
     * The `courses` table (maintained by the admin Courses manager) is the
     * single source of truth, so a full program name is matched against it
     * first. Short codes ("BSIT") and legacy spellings are then resolved through
     * the static catalog and re-checked against the table, which is what stops
     * a code from being attached to a program its college does not offer.
     *
     * Returning null (rather than the typed value) is deliberate: the caller
     * needs to know the course was unrecognised so it can flag the row. The
     * registrar's value is still stored and still never blocks provisioning.
     */
    private function resolveCourseCanonical(?string $department, string $course): ?string
    {
        if ($department === '' || $course === '') {
            return null;
        }

        return DepartmentCatalog::resolveCourse($department, trim($course));
    }

    private function knownDepartments(): array
    {
        return collect(
            Schema::hasTable('departments')
                ? Department::query()->orderBy('sort_order')->pluck('name')
                : DepartmentCatalog::collegeNames()
        )
            ->merge(
                User::query()
                    ->whereNotNull('department')
                    ->where('department', '!=', '')
                    ->distinct()
                    ->pluck('department')
                    ->map(fn ($d) => DepartmentCatalog::resolveCollege($d) ?? $d)
            )
            ->merge(
                RegistrarImport::query()
                    ->whereNotNull('department')
                    ->where('department', '!=', '')
                    ->distinct()
                    ->pluck('department')
                    ->map(fn ($d) => DepartmentCatalog::resolveCollege($d) ?? $d)
            )
            ->filter(fn ($d) => trim((string) $d) !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Build a unique login handle from the student's name, e.g.
     * "John Michael Valles" → john.michael.valles.
     *
     * Registrar-provisioned students sign in with this plain name-derived
     * handle (no email domain) for a simpler UX. Runs every part of the name
     * together with dots (works for both single and multiple given names) and
     * bumps a numeric suffix when the handle is already taken by an existing
     * account or another row in this same file, so the unique constraint on
     * users.email can never be violated.
     */
    private function uniqueEmail(string $fullName, string $studentId, array &$usedEmails): string
    {
        $base = $this->usernameFromName($fullName, $studentId);
        $candidate = $base;
        $suffix = 2;

        while (in_array($candidate, $usedEmails, true)
            || User::where('email', $candidate)->exists()) {
            $candidate = $base.$suffix++;
        }

        $usedEmails[] = $candidate;

        return $candidate;
    }

    /**
     * Slugify a full name into a reusable local-part: lowercase ASCII-alphanum
     * words joined by dots ("John Michael Valles" → "john.michael.valles").
     * Falls back to "student.<id>" when the name has no ASCII letters.
     */
    private function usernameFromName(string $fullName, string $studentId): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($fullName)), '.');

        if ($slug === '') {
            $slug = 'student.'.preg_replace('/[^a-z0-9]/', '', strtolower($studentId));
        }

        return $slug;
    }

    /**
     * Student-ID integrity gate for the eligibility feed.
     *
     * The cell is read with fgetcsv and kept as a trimmed string everywhere
     * (never cast, never used arithmetically), but a spreadsheet round-trip
     * can still silently mangle it *before* it reaches us — Excel drops
     * leading zeros ("00123" → "123") and converts long digit runs to
     * exponential notation ("202400000123" → "2.024E+11"). Once mangled there
     * is no way to recover the original, so the only safe response is to
     * reject the row and ask the registrar to re-export as text.
     *
     * Rule: the value must match `config('registrar.student_id_pattern')`.
     * The default is deliberately permissive (printable ASCII, no
     * whitespace-only values); tighten it to the school's real ID scheme
     * (e.g. `^\d{4}-\d{4}$`) once that is confirmed — see OPEN QUESTION below.
     *
     * OPEN QUESTION: the expected student_id format/length is NOT defined
     * anywhere in this codebase (migrations use plain string(255), samples
     * like '2024-0001' are 9 chars but no rule enforces it). Until it is,
     * leading-zero loss is only *detectable* (all-zero-prefix collapse on a
     * known length), not enforceable — so this gate rejects obvious
     * corruption (exponents, spaces, control characters) and the length check
     * below is the part to tighten.
     *
     * @return string|null human-readable reason, or null when acceptable
     */
    public static function studentIdFormatProblem(string $studentId): ?string
    {
        if ($studentId === '') {
            return 'Student ID is empty.';
        }

        if (preg_match('/[\s\x00-\x1F\x7F]/', $studentId) === 1) {
            return "Student ID '{$studentId}' contains whitespace or control characters — check for wrapped cells or stray spaces.";
        }

        if (preg_match('/[eE][+-]?\d+\s*$/', $studentId) === 1) {
            return "Student ID '{$studentId}' looks like spreadsheet exponential notation (e.g. 2.024E+11) — leading digits were lost in Excel. Re-export the ID column as text.";
        }

        $pattern = (string) config('registrar.student_id_pattern', '/^[\x20-\x7E]+$/');
        $delimiter = $pattern[0] ?? '';

        if ($delimiter === '' || @preg_match($pattern, '') === false) {
            Log::warning('registrar.student_id_pattern is not a valid PCRE pattern; skipping format gate.');

            return null;
        }

        if (preg_match($pattern, $studentId) !== 1) {
            return "Student ID '{$studentId}' does not match the expected format. If the ID had leading zeros, Excel may have dropped them — re-export the ID column as text.";
        }

        return null;
    }

    /**
     * Normalize a block-number cell to its numeric form.
     *
     * Accepts bare digits ("1"), prefixed labels ("Block 1", "block 2") and
     * returns the bare numeric string. Purely alphabetic designations ("A",
     * "Block B") are rejected with a human-readable error so the import can
     * surface exactly which row failed. A null/empty input is treated as
     * "field absent" and stored as null (the column is optional).
     */
    private function normalizeBlockNumber(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['value' => null, 'error' => null];
        }

        $digits = preg_replace('/\D/', '', $raw);

        if ($digits === '') {
            return [
                'value' => null,
                'error' => "Block number '{$raw}' is not numeric — alphabetic block designations are not allowed. Use a numeric value such as 1, 2, or Block 1.",
            ];
        }

        return ['value' => $digits, 'error' => null];
    }

    /**
     * GET /api/admin/registrar/imports — list the imported eligibility feed.
     */
    public function index(Request $request): JsonResponse
    {
        // Scalar-typed filters (security assessment M-1 pattern) + a bounded
        // page size. `per_page` previously went straight into paginate() with
        // no validation, so `?per_page=1000000` dumped the whole
        // registrar_imports table in one response (L-4); /api/candidates
        // already clamps at 100 and this endpoint now matches.
        $request->validate([
            'search' => ['nullable', 'string', 'max:255'],
            'year_level' => ['nullable', 'string', 'max:32'],
            'block_number' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $rows = RegistrarImport::query()
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                // Grouped: an un-grouped set of orWhere() calls escapes the
                // surrounding scope, so combining `search` with year_level/
                // block_number silently matched rows the filter excluded (L-5).
                // Mirrors AdminUserController::filteredQuery().
                fn ($w) => $w->where('student_id', 'like', "%{$s}%")
                    ->orWhere('full_name', 'like', "%{$s}%")
                    ->orWhere('email', 'like', "%{$s}%")
                    ->orWhere('year_level', 'like', "%{$s}%")
                    ->orWhere('block_number', 'like', "%{$s}%")
            ))
            ->when($request->query('year_level'), fn ($q, $y) => $q->where('year_level', $y))
            ->when($request->query('block_number'), fn ($q, $b) => $q->where('block_number', $b))
            ->orderByDesc('updated_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 20))));

        return response()->json($rows);
    }

    /**
     * POST /api/admin/registrar/imports/{import}/issue-code
     *
     * Re-issues the activation / account-recovery code for one eligibility row
     * and returns the plaintext exactly once.
     *
     * This is the recovery path a locked-out voter actually needs (assessment
     * M-3). Students have no mailbox, so the registrar is the only party that
     * can verify identity in person; re-issuing here invalidates any previous
     * code and lets the student redeem the new one without an administrator
     * touching their account.
     */
    public function issueCode(RegistrarImport $import): JsonResponse
    {
        $code = RegistrarCode::issue($import);

        return response()->json([
            'message' => 'Activation code issued. It is shown once — distribute it securely.',
            'activation_code' => $code,
            'student_id' => $import->student_id,
            'full_name' => $import->full_name,
        ]);
    }

    /**
     * POST /api/admin/registrar/imports/issue-codes — bulk re-issue.
     *
     * Regenerates codes for the whole feed (optionally filtered to rows that
     * have no usable code), so a registrar can reissue an entire term's sheet
     * after a leak without importing the CSV again.
     */
    public function issueCodes(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'only_missing' => ['sometimes', 'boolean'],
        ]);

        $query = RegistrarImport::query();

        if ($validated['only_missing'] ?? false) {
            $query->where(function ($q) {
                $q->whereNull('activation_code_hash')
                    ->orWhereNotNull('activation_code_used_at');
            });
        }

        $issued = [];
        foreach ($query->cursor() as $import) {
            $issued[] = [
                'student_id' => $import->student_id,
                'full_name' => $import->full_name,
                'activation_code' => RegistrarCode::issue($import),
            ];
        }

        return response()->json([
            'message' => 'Issued '.count($issued).' activation code(s).',
            'issued_count' => count($issued),
            'activation_codes' => $issued,
        ]);
    }
}
