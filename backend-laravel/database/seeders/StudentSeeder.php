<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Seeder;

/**
 * Seeds a demo student account: "John Michael" in the College of Computer
 * Studies (CCS).
 *
 * Mirrors the registrar CSV import flow (RegistrarImportController): an
 * eligibility row is upserted into `registrar_imports` first, then the matching
 * `users` account is provisioned with a fixed test login (john.michael), the
 * student ID as temporary
 * password, and `must_change_password` so the student must rotate it on first
 * login. Idempotent — re-running never duplicates or overwrites credentials.
 */
class StudentSeeder extends Seeder
{
    /** Student ID assigned to the demo account (must stay unique). */
    private const STUDENT_ID = '2024-0075';

    /** Department code the demo account belongs to (resolved via the catalog). */
    private const DEPARTMENT_CODE = 'CCS';

    /**
     * The CCS catalog entry (name, code, catalog sort order). Read from the
     * single DepartmentCatalog snapshot so the demo student never drifts from
     * what the importer/ballot engine resolves for the same code.
     */
    private function departmentEntry(): array
    {
        foreach (DepartmentCatalog::COLLEGES as $i => $college) {
            if ($college['code'] === self::DEPARTMENT_CODE) {
                return $college + ['sort_order' => $i + 1];
            }
        }

        throw new \LogicException("Department code ".self::DEPARTMENT_CODE." missing from the catalog.");
    }

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // This seeder creates a *voter*: a fixed student ID with its own
        // registrar_imports eligibility row and a password equal to that ID
        // (above). On a live database it would hand a working ballot to whoever
        // knows the ID, so it refuses to run there. Deployments only ever run
        // `migrate --force` (deploy/deploy.sh does not pass --seed); this exists
        // for the operator who types the flag by hand on the wrong host.
        if (app()->environment('production')) {
            $this->command?->warn('StudentSeeder skipped: it provisions a demo voter ('.self::STUDENT_ID.') and must not run in production.');

            return;
        }

        $fullName = 'John Michael';
        // Fixed test login, mirroring the import-derived plain handle
        // (john.michael) — kept in sync on re-runs.
        $email = 'john.michael';
        $department = $this->departmentEntry();

        // The import guard never introduces new departments — CCS is part of
        // the seeded catalog, but firstOrCreate keeps this seeder safe on a
        // database where the migration seeder hasn't run.
        Department::firstOrCreate(
            ['name' => $department['name']],
            ['code' => $department['code'], 'sort_order' => $department['sort_order']]
        );

        // 1. Eligibility feed row (what StudentRegistrationRequest validates
        //    against and what turnout/roster figures are derived from).
        RegistrarImport::updateOrCreate(
            ['student_id' => self::STUDENT_ID],
            [
                'full_name' => $fullName,
                'email' => $email,
                'role' => 'student',
                'year_level' => '1',
                'block_number' => '1',
                'department' => $department['name'],
                'needs_review' => false,
                'review_reason' => null,
            ]
        );

        // 2. Provision the account only when its student_id doesn't exist yet —
        //    a re-run must never touch an existing password.
        $existing = User::where('student_id', self::STUDENT_ID)->first();

        if ($existing) {
            // Sync only the login email so the test address stays the single
            // source of truth across re-runs; credentials stay untouched.
            if ($existing->email !== $email) {
                $existing->update(['email' => $email]);
            }

            return;
        }

        User::create([
            'student_id' => self::STUDENT_ID,
            'name' => $fullName,
            'email' => $email,
            'password' => self::STUDENT_ID, // temp password = student ID (hashed by the model cast)
            'role' => 'student',
            'year_level' => '1',
            'block_number' => '1',
            'department' => $department['name'],
            'is_active' => true,
            'has_voted' => false,
            'needs_review' => false,
            'must_change_password' => true,
        ]);
    }
}