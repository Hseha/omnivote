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

    /**
     * Positions that field MORE THAN ONE candidate per party. Senators are the
     * only multi-seat national race: 12 seats, 12 nominees per party (24 total),
     * and the voter picks 12. That makes it a real contest rather than a
     * foregone conclusion — roughly half the field does not win a seat.
     *
     * Value = nominees per party. Positions absent from this map get the
     * default of one candidate per party.
     */
    private const NATIONAL_NOMINEES_PER_PARTY = [
        'senator' => 12,
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

    /**
     * National race → college for each party, DERIVED from the catalog instead
     * of hardcoded names. Positions rotate through `collegeNames()` and the two
     * party candidates of a race always land on DIFFERENT colleges, so the field
     * stays mixed across all five colleges no matter how the catalog is renamed.
     * (year_level_representative is deliberately absent: it is a national seat
     * keyed by YEAR, not by party-vs-college, so it gets its own loop in run().)
     */
    private function nationalSlate(): array
    {
        $colleges = DepartmentCatalog::collegeNames();
        $n = count($colleges);
        $slate = [];
        $i = 0;

        foreach (self::NATIONAL_POSITIONS as $positionSlug) {
            if ($positionSlug === 'year_level_representative') {
                continue;
            }

            $slate[$positionSlug] = [
                'ASLE' => $colleges[$i % $n],
                'SVEA' => $colleges[($i + 1) % $n],
            ];
            $i++;
        }

        return $slate;
    }

    private array $usedNames = [];

    /**
     * Canonical college year levels. `Position::valuesMatch()` compares year
     * scopes as exact strings, so the year-level representative field must
     * cover every one of these or those students have nobody to vote for.
     */
    private const YEAR_LEVELS = ['1', '2', '3', '4'];

    /**
     * Minimum students per year level.
     *
     * A fixed floor, deliberately NOT derived from the current student count:
     * a "share of the school" target reads its own output, so each run would
     * see a larger total, raise the bar, and add students forever. A constant
     * makes re-running a no-op once every year is at or above it.
     */
    private const MIN_VOTERS_PER_YEAR = 30;

    public function run(): void
    {
        // Start the id counter above anything already in the table. Restarting
        // at 101 collides with the student_ids minted by previous runs, and
        // users.student_id is unique — the insert aborts the whole seed.
        $maxSeq = (int) (User::query()
            ->where('student_id', 'like', '2024-01%')
            ->selectRaw('MAX(CAST(SUBSTRING(student_id, 8) AS UNSIGNED)) AS max_seq')
            ->value('max_seq') ?? 100);

        $studentIdSeq = max(101, $maxSeq + 1);
        $counters = ['createdStudents' => 0, 'createdCandidates' => 0];

        // ---- National: one candidate per party per position, except senators,
        // which field a full multi-nominee slate per party ----
        foreach ($this->nationalSlate() as $positionSlug => $partyDepts) {
            $target = self::NATIONAL_NOMINEES_PER_PARTY[$positionSlug] ?? 1;
            $colleges = DepartmentCatalog::collegeNames();

            foreach ($partyDepts as $party => $college) {
                $positionId = DB::table('positions')->where('slug', $positionSlug)->value('id');
                if ($positionId === null) {
                    continue;
                }

                if ($target === 1) {
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

                    continue;
                }

                // Multi-nominee seat: top this party up to its target, spreading
                // nominees across colleges so no single college monopolises a
                // party slate. Counts by party (not college) because several
                // nominees legitimately share a department.
                $existing = Candidate::query()
                    ->where('position_id', $positionId)
                    ->whereRaw('LOWER(party_name) = ?', [mb_strtolower($party)])
                    ->count();

                for ($i = $existing; $i < $target; $i++) {
                    $this->createCandidateFor(
                        $positionId,
                        $positionSlug,
                        $party,
                        $colleges[$i % count($colleges)],
                        $studentIdSeq,
                        $counters,
                        // Presence is decided by the party count above, so skip
                        // the per-college "already exists" short-circuit that
                        // would otherwise stop at the first nominee per college.
                        force: true,
                    );
                }
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

        $this->ensureEveryYearLevelHasVoters($studentIdSeq, $counters);

        // ---- Year Level Representative: one nominee per YEAR per party ----
        // The position is scoped to `year_level`, so the field is a per-year
        // race, not a per-college one: a 1st-year student votes for the 1st-year
        // nominee of either party, and never for any other year's nominee.
        // Filing only one nominee per party (as before) left each year with at
        // most one party on the ballot and years with no candidate at all.
        $ylrPositionId = DB::table('positions')->where('slug', 'year_level_representative')->value('id');
        if ($ylrPositionId !== null) {
            $colleges = DepartmentCatalog::collegeNames();

            foreach (self::YEAR_LEVELS as $i => $year) {
                foreach (['ASLE', 'SVEA'] as $partyOffset => $party) {
                    $this->createCandidateFor(
                        $ylrPositionId,
                        'year_level_representative',
                        $party,
                        // Rotate colleges so the year-level bench spans the
                        // colleges; the seat itself is not college-bound.
                        $colleges[($i * 2 + $partyOffset) % count($colleges)],
                        $studentIdSeq,
                        $counters,
                        yearLevel: $year,
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

    /**
     * Guarantees every year level has enough students to elect its own
     * representative.
     *
     * The year-level representative seat is scoped to `year_level`, so a year
     * with no students is a seat that can never be contested. Earlier runs
     * only ever produced years 11/12 — 2 of the 4 levels — leaving the 3rd- and
     * 4th-year seats permanently uncontested and invisible to a voter.
     *
     * This adds plain students (no candidacy attached) rather than re-assigning
     * the ones already on the ballot. Two reasons: a candidate's year level is
     * exactly what qualifies them for a year-level seat, so moving one would
     * silently change the field; and a real school has far more voters than
     * candidates, so 110 candidates and 0 ordinary students was never a
     * representative shape to begin with.
     *
     * Idempotent — it only tops a year up to a fixed floor, and a re-run
     * finds every year already at or above it.
     */
    private function ensureEveryYearLevelHasVoters(int &$studentIdSeq, array &$counters): void
    {
        $years = self::YEAR_LEVELS;
        $colleges = DepartmentCatalog::collegeNames();

        foreach ($years as $i => $year) {
            $have = User::where('role', 'student')->where('year_level', $year)->count();

            for ($n = $have; $n < self::MIN_VOTERS_PER_YEAR; $n++) {
                $this->provisionStudent(
                    $colleges[$i % count($colleges)],
                    $studentIdSeq,
                    $counters,
                    requireNew: true,
                    yearLevel: $year,
                );
            }
        }
    }

    private function createCandidateFor(
        int $positionId,
        string $positionSlug,
        string $party,
        string $college,
        int &$studentIdSeq,
        array &$counters,
        bool $force = false,
        ?string $yearLevel = null,
    ): void {
        $position = DB::table('positions')->where('id', $positionId)->first();

        // A candidate already exists for this college under this party → skip
        // (idempotent re-runs). Keyed via the student's college. Multi-nominee
        // seats call with $force and do their own party-level counting instead.
        // A year-level seat keys on the year instead of the college, because it
        // is contested across the whole school.
        if (! $force) {
            $existing = Candidate::query()
                ->where('position_id', $positionId)
                ->whereRaw('LOWER(party_name) = ?', [mb_strtolower($party)])
                ->when(
                    $yearLevel !== null,
                    fn ($q) => $q->whereHas('user', fn ($u) => $u->where('year_level', $yearLevel)),
                    fn ($q) => $q->whereHas('user', fn ($u) => $u->where('department', $college)),
                )
                ->exists();
            if ($existing) {
                return;
            }
        }

        $user = $this->provisionStudent($college, $studentIdSeq, $counters, $force, $yearLevel);

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

    /**
     * @param bool $requireNew When true, skip forward to a name that is not
     *   already taken. Multi-nominee seats call this: the name walk restarts at
     *   index 0 on every run, so without this it lands on the same already-
     *   candidated students every time and silently creates nothing.
     */
    private function provisionStudent(
        string $college,
        int &$studentIdSeq,
        array &$counters,
        bool $requireNew = false,
        ?string $yearLevel = null,
    ): User {
        $name = $this->nextName();
        $email = $this->usernameFromName($name);

        $user = User::where('email', $email)->first() ?? User::where('name', $name)->first();

        if ($user !== null) {
            if (! $requireNew) {
                return $user;
            }
            // Name taken: keep walking until a genuinely unused one is found.
            do {
                $name = $this->nextName();
                $email = $this->usernameFromName($name);
                $user = User::where('email', $email)->first()
                    ?? User::where('name', $name)->first();
            } while ($user !== null);

            $this->command?->warn(
                "  Skipped taken name, provisioned {$name} instead."
            );
        }

        $studentId = '2024-01'.str_pad((string) $studentIdSeq++, 3, '0', STR_PAD_LEFT);
        // Cycle 1st-4th year so every year level has voters. The old 11/12
        // senior-high pair left 3rd and 4th year with nobody, which meant two
        // year-level representative seats could never be contested.
        $yearLevel ??= self::YEAR_LEVELS[($studentIdSeq - 1) % count(self::YEAR_LEVELS)];

        $user = User::create([
            'student_id' => $studentId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($studentId),
            'role' => 'student',
            'year_level' => $yearLevel,
            'block_number' => (string) ((((int) $yearLevel - 1) % 2) + 1),
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