<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\User;
use App\Support\DepartmentCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * TEST-ONLY ballot population seeder.
 *
 * - National: every position gets one ASLE + one SVEA candidate, and the two
 *   party candidates for each race come from DIFFERENT departments/courses
 *   (mixed across all five colleges).
 * - Provincial: every college has its OWN full provincial slate — 1 ASLE + 1
 *   SVEA candidate for each of the seven provincial positions.
 *
 * Students are provisioned exactly like the registrar CSV import does
 * (name-derived login email, student_id as the initial password,
 * must_change_password = true), so nothing is "hardcoded" in the client —
 * the app renders everything from the API/DB. Rerun-safe: existing
 * students/candidates are matched and skipped.
 */
class TestBallotSeeder extends Seeder
{
    private const NATIONAL_POSITIONS = [
        'president', 'vice_president', 'secretary', 'treasurer',
        'auditor', 'press_officer', 'senator', 'year_level_representative',
        'property_custodian',
    ];

    private const PROVINCIAL_POSITIONS = [
        'governor', 'vice_governor', 'provincial_secretary',
        'provincial_treasurer', 'provincial_auditor',
        'provincial_press_officer', 'provincial_custodian',
    ];

    private const FIRST_NAMES = [
        'Jasmine', 'Marco', 'Andrea', 'Paolo', 'Kristine', 'Bryan', 'Erica',
        'Gerald', 'Liza', 'Patrick', 'Nicole', 'Vincent', 'Sarah', 'Dennis',
        'Rachel', 'Angelo', 'Camille', 'Renz', 'Trixie', 'Miguel', 'Hannah',
        'Ivan', 'Dianne', 'Karl', 'Patricia', 'Adrian', 'Sofia', 'Marc',
        'Aira', 'Rafael', 'Bianca', 'Elijah', 'Chloe', 'Nathan', 'Denise',
        'Jerome', 'Maria', 'Allan', 'Gina', 'Jonard',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Cruz', 'Bautista',
        'Dela Cruz', 'Aquino', 'Ramos', 'Fernandez', 'Villanueva', 'Navarro',
        'Castillo', 'Lim', 'Domingo', 'Ocampo', 'Mendiola', 'Soriano',
        'Salvacion', 'Manalo', 'Coronel', 'Pineda', 'Lacson', 'Abadilla',
        'Ventura', 'Suarez', 'Mercado', 'Rivera', 'Villamor', 'Tolentino',
        'Dizon', 'Rosales', 'Padilla', 'Estrada', 'Malonzo',
    ];

    /** National race → department for each party (mixed across colleges). */
    private const NATIONAL_SLATE = [
        'president' => [
            'ASLE' => 'College of Arts and Sciences',
            'SVEA' => 'College of Computer Studies',
        ],
        'vice_president' => [
            'ASLE' => 'College of Teacher Education',
            'SVEA' => 'College of Criminal Justice Education',
        ],
        'secretary' => [
            'ASLE' => 'College of Office Administration',
            'SVEA' => 'College of Arts and Sciences',
        ],
        'treasurer' => [
            'ASLE' => 'College of Computer Studies',
            'SVEA' => 'College of Teacher Education',
        ],
        'auditor' => [
            'ASLE' => 'College of Criminal Justice Education',
            'SVEA' => 'College of Office Administration',
        ],
        'press_officer' => [
            'ASLE' => 'College of Arts and Sciences',
            'SVEA' => 'College of Computer Studies',
        ],
        'senator' => [
            // ASLE → Senator is the existing real student John Michael (CCS).
            'ASLE' => 'College of Computer Studies',
            'SVEA' => 'College of Criminal Justice Education',
        ],
        'year_level_representative' => [
            'ASLE' => 'College of Teacher Education',
            'SVEA' => 'College of Office Administration',
        ],
        'property_custodian' => [
            'ASLE' => 'College of Criminal Justice Education',
            'SVEA' => 'College of Arts and Sciences',
        ],
    ];

    private array $usedNames = [];

    public function run(): void
    {
        $studentIdSeq = 101;
        $counters = ['createdStudents' => 0, 'createdCandidates' => 0];

        // ---- National: 2 party candidates per position, mixed colleges ----
        foreach (self::NATIONAL_SLATE as $positionSlug => $partyDepts) {
            foreach ($partyDepts as $party => $college) {
                $positionId = DB::table('positions')->where('slug', $positionSlug)->value('id');
                if ($positionId === null) {
                    continue;
                }

                // ASLE → Senator is already filed by the real student John
                // Michael (College of Computer Studies); keep his candidacy.
                if ($positionSlug === 'senator' && $party === 'ASLE') {
                    continue;
                }

                $this->createCandidateFor(
                    $positionId,
                    $positionSlug,
                    $party,
                    $college,
                    $studentIdSeq,
                    $counters,
                );
            }
        }

        // ---- Provincial: full 1 ASLE + 1 SVEA slate per college ----
        foreach (DepartmentCatalog::collegeNames() as $college) {
            foreach (self::PROVINCIAL_POSITIONS as $positionSlug) {
                $positionId = DB::table('positions')->where('slug', $positionSlug)->value('id');
                if ($positionId === null) {
                    continue;
                }

                foreach (['ASLE', 'SVEA'] as $party) {
                    $this->createCandidateFor(
                        $positionId,
                        $positionSlug,
                        $party,
                        $college,
                        $studentIdSeq,
                        $counters,
                    );
                }
            }
        }

        // Normalize any pre-existing application's party casing to match the
        // canonical parties table ("asle" → "ASLE") so grouping is consistent.
        Candidate::query()
            ->whereRaw('LOWER(party_name) = ?', ['asle'])
            ->update(['party_name' => 'ASLE']);

        $this->command?->info(
            "Test ballot seeded: {$counters['createdStudents']} students, "
            ."{$counters['createdCandidates']} candidates."
        );
    }

    private function createCandidateFor(
        int $positionId,
        string $positionSlug,
        string $party,
        string $college,
        int &$studentIdSeq,
        array &$counters,
    ): void {
        $position = DB::table('positions')->where('id', $positionId)->first();

        // A provincial candidate already exists for this college under this
        // party → skip (idempotent re-runs). Keyed via the student's college.
        $existing = Candidate::query()
            ->where('position_id', $positionId)
            ->whereRaw('LOWER(party_name) = ?', [mb_strtolower($party)])
            ->whereHas('user', fn ($q) => $q->where('department', $college))
            ->exists();
        if ($existing) {
            return;
        }

        $user = $this->provisionStudent($college, $studentIdSeq, $counters);

        if (Candidate::where('user_id', $user->id)->exists()) {
            return;
        }

        $course = $this->pickCourse($college, $user->id);

        Candidate::create([
            'user_id' => $user->id,
            'position_id' => $positionId,
            'candidate_ref' => (string) Str::uuid(),
            'party_name' => $party,
            'slogan' => "{$user->name} para sa {$position->label}, sama na sa {$party}!",
            'platform_statement' => "I am running for {$position->label} under {$party} representing the {$college}. Focus on student services, transparent student funds, and active listening to every campus voice.",
            'platform_points' => [
                'Student-focused programs and services',
                'Transparent handling of student funds',
                'Open dialogue with every student organization',
            ],
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);
        $counters['createdCandidates']++;
    }

    private function provisionStudent(string $college, int &$studentIdSeq, array &$counters): User
    {
        $name = $this->nextName();
        $email = $this->usernameFromName($name);

        $user = User::where('email', $email)->first() ?? User::where('name', $name)->first();
        if ($user !== null) {
            return $user;
        }

        $studentId = '2024-01'.str_pad((string) $studentIdSeq++, 3, '0', STR_PAD_LEFT);
        $user = User::create([
            'student_id' => $studentId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($studentId),
            'role' => 'student',
            'year_level' => $studentIdSeq % 2 === 0 ? '11' : '12',
            'block_number' => $studentIdSeq % 2 === 0 ? '1' : '2',
            'department' => $college,
            'course' => $this->pickCourse($college, $studentIdSeq),
            'has_voted' => false,
            'must_change_password' => true,
            'is_active' => true,
        ]);
        $counters['createdStudents']++;

        return $user;
    }

    /** Cycle a college's offered programs so course values stay valid/real. */
    private function pickCourse(string $college, int $seed): ?string
    {
        $courses = DepartmentCatalog::coursesOf($college);
        if ($courses === []) {
            return null;
        }

        return $courses[$seed % count($courses)];
    }

    private function nextName(): string
    {
        $offset = count($this->usedNames);
        $first = self::FIRST_NAMES[$offset % count(self::FIRST_NAMES)];
        // A multiplier coprime to the surname count cycles through all last
        // names evenly instead of clamping onto one surname for a whole run.
        $last = self::LAST_NAMES[($offset * 12) % count(self::LAST_NAMES)];

        $name = $first.' '.$last;
        $suffix = 2;
        while (in_array($name, $this->usedNames, true)) {
            $name = $first.' '.$last.' '.$suffix++;
        }

        $this->usedNames[] = $name;

        return $name;
    }

    /**
     * Name-derived login handle, mirroring RegistrarImportController.
     * "John Michael Valles" → "john.michael.valles".
     */
    private function usernameFromName(string $fullName): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($fullName)), '.');

        return $slug === '' ? 'student.'.Str::uuid()->toString() : $slug;
    }
}