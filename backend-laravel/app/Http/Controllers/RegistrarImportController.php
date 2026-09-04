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
 * Role) plus optional section columns (Year Level, Block Number) from the
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
        $required = ['student id', 'full name', 'email address', 'grade level'];
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
                'message' => 'CSV headers must include: Student ID, Full Name, Email Address, and Grade Level.',
            ], 422);
        }

        // Optional section columns — matched only when present so legacy
        // 5-column CSVs keep importing unchanged.
        $optional = ['year level' => 'year_level', 'block number' => 'block_number'];
        foreach ($optional as $needle => $key) {
            foreach ($headers as $i => $header) {
                if (str_contains($header, $needle)) {
                    $headerMap[$needle] = $i;
                    break;
                }
            }
        }

        $rows = [];
        $seenStudentIds = [];
        $duplicateInFile = 0;
        $rejectedRows = [];
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

            // Block number is strictly numeric: accept "1", "Block 1", "block 2"
            // (normalized to the bare digits) and reject alphabetic-only values
            // like "A" or "Block B".
            $rawBlock = isset($headerMap['block number'])
                ? trim((string) ($data[$headerMap['block number']] ?? ''))
                : null;
            $blockResult = $this->normalizeBlockNumber($rawBlock);
            if ($blockResult['error'] !== null) {
                $rejectedRows[] = [
                    'line' => $line,
                    'student_id' => $studentId,
                    'reason' => $blockResult['error'],
                ];
                $line++;
                continue;
            }

            $rows[] = [
                'student_id' => $studentId,
                'full_name' => trim((string) ($data[$headerMap['full name']] ?? '')),
                'email' => strtolower(trim((string) ($data[$headerMap['email address']] ?? ''))),
                'grade_level' => trim((string) ($data[$headerMap['grade level']] ?? '')),
                'year_level' => isset($headerMap['year level'])
                    ? trim((string) ($data[$headerMap['year level']] ?? ''))
                    : null,
                'block_number' => $blockResult['value'],
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
                        'year_level' => $row['year_level'],
                        'block_number' => $row['block_number'],
                        'role' => 'student',
                    ]);
                    $updated++;
                } else {
                    RegistrarImport::create([
                        'student_id' => $row['student_id'],
                        'full_name' => $row['full_name'],
                        'email' => $row['email'],
                        'grade_level' => $row['grade_level'],
                        'year_level' => $row['year_level'],
                        'block_number' => $row['block_number'],
                        'role' => 'student',
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
                        'year_level' => $row['year_level'],
                        'block_number' => $row['block_number'],
                        'role' => in_array($user->role, ['teacher', 'admin'], true)
                            ? $user->role
                            : 'student',
                    ]);
                    $updated++;
                    continue;
                }

                // Temporary password: the student_id itself, stored hashed.
                $tempPassword = $row['student_id'];
                User::create([
                    'student_id' => $row['student_id'],
                    'name' => $row['full_name'],
                    'email' => $row['email'],
                    'password' => Hash::make($tempPassword),
                    'role' => 'student',
                    'year_level' => $row['year_level'],
                    'block_number' => $row['block_number'],
                    'has_voted' => false,
                ]);
                $accountsProvisioned++;
                $temporaryCredentials[] = [
                    'student_id' => $row['student_id'],
                    'full_name' => $row['full_name'],
                    'email' => $row['email'],
                    'year_level' => $row['year_level'],
                    'block_number' => $row['block_number'],
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
                'rejected_rows' => $rejectedRows,
            ],
            'temporary_credentials' => $temporaryCredentials,
        ], 201);
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
        $rows = RegistrarImport::query()
            ->when($request->query('search'), fn ($q, $s) => $q
                ->where('student_id', 'like', "%{$s}%")
                ->orWhere('full_name', 'like', "%{$s}%")
                ->orWhere('email', 'like', "%{$s}%")
                ->orWhere('grade_level', 'like', "%{$s}%")
                ->orWhere('year_level', 'like', "%{$s}%")
                ->orWhere('block_number', 'like', "%{$s}%"))
            ->when($request->query('year_level'), fn ($q, $y) => $q->where('year_level', $y))
            ->when($request->query('block_number'), fn ($q, $b) => $q->where('block_number', $b))
            ->orderByDesc('updated_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($rows);
    }
}