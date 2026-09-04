<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegistrarImportRequest;
use App\Models\RegistrarImport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;

/**
 * Registrar CSV import + eligibility feed.
 *
 * Flow: admin uploads CSV (Student ID, Full Name, Email Address, Grade Level,
 * Role) from the React Student Registry screen → rows are upserted into
 * `registrar_imports` → matching `users` accounts are provisioned with a
 * temporary password → students sign in on the Flutter app immediately.
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
        $required = ['student id', 'full name', 'email address', 'grade level', 'role'];
        $headerMap = [];
        foreach ($required as $needle) {
            foreach ($headers as $i => $header) {
                if (str_contains($header, $needle)) {
                    $headerMap[$needle] = $i;
                    break;
                }
            }
        }
        if (count($headerMap) !== count($required)) {
            fclose($handle);
            return response()->json([
                'message' => 'CSV headers must include: Student ID, Full Name, Email Address, Grade Level, Role.',
            ], 422);
        }

        $rows = [];
        $seenStudentIds = [];
        $duplicateInFile = 0;
        $line = 2;
        while (($data = fgetcsv($handle)) !== false) {
            $studentId = trim((string) ($data[$headerMap['student id']] ?? ''));
            if ($studentId === '') {
                $line++;
                continue;
            }

            if (isset($seenStudentIds[$studentId])) {
                $duplicateInFile++;
                $line++;
                continue;
            }
            $seenStudentIds[$studentId] = true;

            $rows[] = [
                'student_id' => $studentId,
                'full_name' => trim((string) ($data[$headerMap['full name']] ?? '')),
                'email' => strtolower(trim((string) ($data[$headerMap['email address']] ?? ''))),
                'grade_level' => trim((string) ($data[$headerMap['grade level']] ?? '')),
                'role' => strtolower(trim((string) ($data[$headerMap['role']] ?? 'student'))) === 'teacher' ? 'teacher' : 'student',
                '_line' => $line,
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
        $skipped = 0;
        $temporaryCredentials = [];

        DB::transaction(function () use ($rows, &$created, &$updated, &$accountsProvisioned, &$skipped, &$temporaryCredentials) {
            foreach ($rows as $row) {
                // 1. Upsert the eligibility feed row.
                $existingImport = RegistrarImport::where('student_id', $row['student_id'])->first();
                if ($existingImport) {
                    $existingImport->update([
                        'full_name' => $row['full_name'],
                        'email' => $row['email'],
                        'grade_level' => $row['grade_level'],
                        'role' => $row['role'],
                    ]);
                    $updated++;
                } else {
                    RegistrarImport::create([
                        'student_id' => $row['student_id'],
                        'full_name' => $row['full_name'],
                        'email' => $row['email'],
                        'grade_level' => $row['grade_level'],
                        'role' => $row['role'],
                    ]);
                    $created++;
                }

                // 2. Provision (or update) the login account for every record.
                if ($row['email'] === '') {
                    $skipped++;
                    continue;
                }

                $user = User::where('student_id', $row['student_id'])->first()
                    ?? User::where('email', $row['email'])->first();

                if ($user) {
                    $user->update([
                        'name' => $row['full_name'],
                        'email' => $row['email'],
                        'role' => $user->role === 'teacher' ? 'teacher' : $row['role'],
                    ]);
                    $updated++;
                    continue;
                }

                // Temporary password policy: >= 8 chars, upper + lower + digit.
                $tempPassword = 'Tmp-'.strtoupper(substr(md5($row['student_id']), 0, 6)).'!1';
                User::create([
                    'student_id' => $row['student_id'],
                    'name' => $row['full_name'],
                    'email' => $row['email'],
                    'password' => Hash::make($tempPassword),
                    'role' => $row['role'],
                    'has_voted' => false,
                ]);
                $accountsProvisioned++;
                $temporaryCredentials[] = [
                    'student_id' => $row['student_id'],
                    'full_name' => $row['full_name'],
                    'email' => $row['email'],
                    'temp_password' => $tempPassword,
                ];
            }
        });

        return response()->json([
            'message' => 'Import completed.',
            'summary' => [
                'total_records' => count($rows),
                'created_eligibility_rows' => $created,
                'updated_eligibility_rows' => $updated,
                'accounts_provisioned' => $accountsProvisioned,
                'duplicates_within_file' => $duplicateInFile,
                'skipped_no_email' => $skipped,
            ],
            'temporary_credentials' => $temporaryCredentials,
        ], 201);
    }

    /**
     * GET /api/admin/registrar/imports — list the imported eligibility feed.
     */
    public function index(Request $request): JsonResponse
    {
        $rows = RegistrarImport::query()
            ->when($request->query('search'), fn ($q, $s) => $q
                ->where('student_id', 'like', "%{$s}%")
                ->orWhere('full_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%"))
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rows);
    }
}