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
 *
 * NEVER runs in production: every test login uses a publicly-known password
 * (student_id), so seeding a live election database would hand working
 * ballots to anyone with repo access and pollute real turnout/results. The
 * intended target is the isolated staging database (`omnivote_staging`, see
 * deploy/setup-staging.sh). Like UserSeeder/StudentSeeder this returns early
 * with a warning instead of throwing, so a mistaken production invocation is
 * a no-op, not an outage.
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
     * Races left EMPTY on purpose so real users can file for them (e.g. the
     * instructor demonstrating the apply flow live). A test candidate would
     * consume the per-party seat (seat_count = 1 for each of these) and a real
     * applicant would hit a 409 `seat_taken`. Skipping the slug outright — not
     * just the insert — also means a re-run never disturbs a real application
     * that already occupies the seat.
     *
     * National: the two executive seats (both ASLE + SVEA slots).
     */
    private const RESERVED_NATIONAL_POSITIONS = ['president', 'vice_president'];

    /**
     * Provincial seats left empty for real users, in EVERY college (the
     * provincial tier is contested college-by-college, so the skip applies to
     * each college's own slate).
     */
    private const RESERVED_PROVINCIAL_POSITIONS = ['governor', 'vice_governor'];

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
            // year_level_representative is scoped by YEAR, not party-vs-college,
            // so it gets its own loop in run(); RESERVED_NATIONAL_POSITIONS are
            // left for real users to contest.
            if ($positionSlug === 'year_level_representative'
                || in_array($positionSlug, self::RESERVED_NATIONAL_POSITIONS, true)) {
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
     * Minimum students per (college, year) electorate.
     *
     * The Year Level Representative is scoped by department AND year level, so
     * each (college, year) pair is its own contest and needs its own voters —
     * a year that exists only inside one college leaves the other colleges'
     * seats for that year uncontested and invisible.
     *
     * A fixed floor, deliberately NOT derived from the current student count:
     * a "share of the school" target reads its own output, so each run would
     * see a larger total, raise the bar, and add students forever. A constant
     * makes re-running a no-op once every group is at or above it.
     */
    private const MIN_VOTERS_PER_GROUP = 8;

    public function run(): void
    {
        // Production guard: fake voters with publicly-known passwords must
        // never land in a live election database. Staging (`omnivote_staging`)
        // is the intended target; an explicit opt-in exists for the rare
        // non-production host that reports APP_ENV=production.
        if (app()->environment('production') && ! $this->demoSeedAllowed()) {
            $this->command?->warn(
                'TestBallotSeeder skipped: it provisions fake voters with publicly-known passwords '
                .'and must not run against a production database. Seed the staging database instead '
                .'(deploy/setup-staging.sh), or set ALLOW_DEMO_SEED=true to override explicitly.'
            );

            return;
        }

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
                // Governor / Vice Governor stay open in every college for real
                // users (demo apply flow).
                if (in_array($positionSlug, self::RESERVED_PROVINCIAL_POSITIONS, true)) {
                    continue;
                }

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

        // ---- Year Level Representative: one nominee per college per year, per
        // party. The seat is scoped to `department` + `year_level`, so every
        // college runs its own race in every year level (1 ASLE + 1 SVEA), and
        // only that college's students of that year vote in it. Five colleges ×
        // four years × two parties = 40 nominees.
        $ylrPositionId = DB::table('positions')->where('slug', 'year_level_representative')->value('id');
        if ($ylrPositionId !== null) {
            foreach (self::YEAR_LEVELS as $year) {
                foreach (DepartmentCatalog::collegeNames() as $college) {
                    foreach (['ASLE', 'SVEA'] as $party) {
                        $this->createCandidateFor(
                            $ylrPositionId,
                            'year_level_representative',
                            $party,
                            $college,
                            $studentIdSeq,
                            $counters,
                            yearLevel: $year,
                        );
                    }
                }
            }
        }

        // Normalize any pre-existing application's party casing to match the
        // canonical parties table ("asle" → "ASLE") so grouping is consistent.
        Candidate::query()
            ->whereRaw('LOWER(party_name) = ?', ['asle'])
            ->update(['party_name' => 'ASLE']);

        $this->command?->info(
            'Left empty for real-user applications: '
            .implode(', ', array_merge(
                self::RESERVED_NATIONAL_POSITIONS,
                self::RESERVED_PROVINCIAL_POSITIONS,
            )).'.'
        );

        $this->command?->info(
            "Test ballot seeded: {$counters['createdStudents']} students, "
            ."{$counters['createdCandidates']} candidates."
        );
    }

    /**
     * Guarantees every (college, year) electorate has enough students to elect
     * its own representative.
     *
     * The Year Level Representative is scoped by department AND year level, so
     * each (college, year) pair is an independent contest. A group with too few
     * students is a seat that can never be properly contested — or, with only
     * the two nominees in it, one that can only tie — so each pair is topped up
     * to a floor.
     *
     * This adds plain students (no candidacy attached) rather than re-assigning
     * the ones already on the ballot. Two reasons: a student's college and year
     * are exactly what place them in an electorate, so moving one would silently
     * change the field; and a real school has far more voters than candidates.
     *
     * Idempotent — it only tops a group up to a fixed floor, and a re-run finds
     * every group already at or above it.
     */
    private function ensureEveryYearLevelHasVoters(int &$studentIdSeq, array &$counters): void
    {
        foreach (self::YEAR_LEVELS as $year) {
            foreach (DepartmentCatalog::collegeNames() as $college) {
                $have = User::query()
                    ->where('role', 'student')
                    ->where('year_level', $year)
                    ->where('department', $college)
                    ->count();

                for ($n = $have; $n < self::MIN_VOTERS_PER_GROUP; $n++) {
                    $this->provisionStudent(
                        $college,
                        $studentIdSeq,
                        $counters,
                        requireNew: true,
                        yearLevel: $year,
                    );
                }
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

        // A candidate already exists for this college (and, for year-scoped
        // seats, this year) under this party → skip (idempotent re-runs). Keyed
        // via the student's own college/year, which is exactly what decides the
        // candidate's electorate group. Multi-nominee seats call with $force and
        // do their own party-level counting instead.
        if (! $force) {
            $existing = Candidate::query()
                ->where('position_id', $positionId)
                ->whereRaw('LOWER(party_name) = ?', [mb_strtolower($party)])
                ->whereHas('user', function ($u) use ($college, $yearLevel) {
                    $u->where('department', $college);
                    if ($yearLevel !== null) {
                        $u->where('year_level', $yearLevel);
                    }
                })
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

    /**
     * Explicit opt-in for demo seeding on a host that reports
     * APP_ENV=production (e.g. staging configured in production mode).
     * Reads ALLOW_DEMO_SEED from env ($_ENV/$_SERVER/getenv); anything
     * other than the literal string "true" (case-insensitive, trimmed)
     * keeps the guard closed. Free of database access so it stays trivially
     * testable.
     */
    public function demoSeedAllowed(): bool
    {
        foreach (['ALLOW_DEMO_SEED', 'allow_demo_seed'] as $key) {
            foreach ([$_ENV[$key] ?? null, $_SERVER[$key] ?? null, getenv($key)] as $raw) {
                if (is_string($raw) && strtolower(trim($raw)) === 'true') {
                    return true;
                }
            }
        }

        return false;
    }
}