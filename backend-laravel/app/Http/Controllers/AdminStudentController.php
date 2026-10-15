<?php

namespace App\Http\Controllers;

use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\DepartmentCatalog;
use App\Support\RegistrarCode;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Manual single-student provisioning from the React User Management screen.
 *
 * POST /api/admin/users/students — create one student account for a learner
 *        who has no account yet (the registrar CSV import stays the bulk path;
 *        this covers the "one student missing from the sheet" case).
 *
 * Mirrors the per-student provisioning the registrar import performs: a
 * `users` row (role student, random temporary password, must-change flag),
 * a `registrar_imports` eligibility row, and a one-time activation code for
 * self-service password recovery. Both secrets are returned exactly once.
 *
 * The route requires auth + permission:manage_accounts (admin only).
 */
class AdminStudentController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'year_level' => ['required', 'string', 'max:32'],
            'block_number' => ['required', 'string', 'max:16'],
            'department' => ['required', 'string', 'max:100'],
            'course' => ['nullable', 'string', 'max:191'],
        ]);

        $studentId = trim($validated['student_id']);
        $name = trim($validated['name']);

        // Hold a hand-typed ID to the same integrity gate a CSV cell passes
        // through, so spreadsheet-style corruption (exponential notation,
        // stray spaces, control characters) is caught with the same message.
        $idProblem = RegistrarImportController::studentIdFormatProblem($studentId);
        if ($idProblem !== null) {
            return response()->json(['message' => $idProblem], 422);
        }

        // Reject duplicates on BOTH the account table and the eligibility
        // feed, naming which one conflicts so the admin knows whether an
        // account already exists or only a registrar record does.
        if (User::where('student_id', $studentId)->exists()) {
            return response()->json([
                'message' => "A user account with Student ID '{$studentId}' already exists. Open that account in the list instead of creating a second one.",
            ], 422);
        }

        if (RegistrarImport::where('student_id', $studentId)->exists()) {
            return response()->json([
                'message' => "Student ID '{$studentId}' is already present in the registrar eligibility feed but has no account. Re-run the registrar import for this student, or correct the ID.",
            ], 422);
        }

        // Block numbers are numeric everywhere in this system ("Block 3" is
        // normalized to "3"); alphabetic designations are rejected with the
        // same wording the CSV import uses.
        $blockRaw = trim($validated['block_number']);
        $blockNumber = preg_replace('/\D/', '', $blockRaw);
        if ($blockNumber === '') {
            return response()->json([
                'message' => "Block number '{$blockRaw}' is not numeric — alphabetic block designations are not allowed. Use a numeric value such as 1, 2, or Block 1.",
            ], 422);
        }

        [$department, $course] = $this->resolveDepartmentCourse(
            $validated['department'],
            $validated['course'] ?? null,
        );

        $email = $this->deriveEmail($name, $studentId);

        // Random, never derived from the student ID (security assessment C-1:
        // IDs are public data printed on class lists). Returned once below.
        $tempPassword = TemporaryPassword::generate();

        [$user, $activationCode] = DB::transaction(function () use (
            $studentId,
            $name,
            $email,
            $tempPassword,
            $validated,
            $blockNumber,
            $department,
            $course,
        ) {
            // The eligibility feed is what self-service activation and
            // password recovery key off, so a manually-created student gets
            // the same registrar row a CSV import would have produced.
            $import = RegistrarImport::create([
                'student_id' => $studentId,
                'full_name' => $name,
                'email' => $email,
                'role' => 'student',
                'year_level' => trim($validated['year_level']),
                'block_number' => $blockNumber,
                'department' => $department,
                'course' => $course,
                'needs_review' => false,
            ]);

            // Plaintext returned once; only the bcrypt hash is stored.
            $activationCode = RegistrarCode::issue($import);

            $user = User::create([
                'student_id' => $studentId,
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($tempPassword),
                'role' => 'student',
                'year_level' => trim($validated['year_level']),
                'block_number' => $blockNumber,
                'department' => $department,
                'course' => $course,
                'needs_review' => false,
                'has_voted' => false,
                'must_change_password' => true,
                'is_active' => true,
            ]);

            return [$user, $activationCode];
        });

        AuditLog::record(
            $request,
            'student_account_created',
            "Admin manually created student account '{$name}' (Student ID {$studentId}, handle {$email}).",
            'user',
            $user->id,
        );

        return response()->json([
            'message' => 'Student account created.',
            'temporary_password' => $tempPassword,
            'activation_code' => $activationCode,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'student_id' => $user->student_id,
                'role' => $user->role,
                'year_level' => $user->year_level,
                'block_number' => $user->block_number,
                'department' => $user->department,
                'course' => $user->course,
                'is_active' => (bool) $user->is_active,
            ],
        ], 201);
    }

    /**
     * Validate a College → Course pair: the college must be in the current
     * department list (legacy codes like "CCS" resolve to the canonical
     * name), and a course, when given, must be one the college actually
     * offers. Mirrors AdminUserController::resolveDepartmentCourse.
     */
    private function resolveDepartmentCourse(?string $department, ?string $course): array
    {
        $department = $department !== null ? trim($department) : null;
        $course = $course !== null ? trim($course) : null;

        if ($department === '') {
            $department = null;
        }
        if ($course === '') {
            $course = null;
        }

        if ($department !== null) {
            $canonical = DepartmentCatalog::resolveCollege($department);
            if ($canonical === null) {
                abort(422, "Department '{$department}' is not in the current department list.");
            }
            $department = $canonical;
        }

        if ($course !== null) {
            if ($department === null) {
                abort(422, 'A department is required when a course is chosen.');
            }
            $resolved = DepartmentCatalog::resolveCourse($department, $course);
            if ($resolved === null) {
                abort(422, "Course '{$course}' is not offered by {$department}.");
            }
            $course = $resolved;
        }

        return [$department, $course];
    }

    /**
     * Derive the login handle from the full name, matching the registrar
     * import's convention exactly: lowercase ASCII-alphanum words joined by
     * dots ("John Michael Valles" → "john.michael.valles"), falling back to
     * "student.<id>" when the name has no ASCII letters, with a numeric
     * suffix bump so users.email uniqueness can never be violated.
     */
    private function deriveEmail(string $fullName, string $studentId): string
    {
        $base = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($fullName)), '.');

        if ($base === '') {
            $base = 'student.'.preg_replace('/[^a-z0-9]/', '', strtolower($studentId));
        }

        $candidate = $base;
        $suffix = 2;

        while (User::where('email', $candidate)->exists()) {
            $candidate = $base.$suffix++;
        }

        return $candidate;
    }
}
