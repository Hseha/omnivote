<?php

namespace Database\Seeders;

use App\Models\Candidate;
use App\Models\Position;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\CandidateRoleSync;
use App\Support\DepartmentCatalog;
use App\Support\SeatCap;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Demo slate seeder — a full, realistic field of candidates with a small
 * number of seats deliberately left open.
 *
 * ## The shape of the slate
 *
 * NATIONAL (one race for the whole school):
 *   - every single-seat post is filled with one nominee per party
 *     (Vice President, Secretary, Treasurer, Auditor, Press Officer,
 *     Property Custodian);
 *   - `senator` is a 12-seat race, so it fields 12 nominees per party;
 *   - `year_level_representative` is one race per (college, year level), so it
 *     fields one nominee per party in every one of them;
 *   - `president` is left EMPTY in both parties.
 *
 * PROVINCIAL (one race per college):
 *   - every provincial post is filled with one nominee per party in EVERY
 *     college (Vice Governor, Provincial Secretary, Provincial Treasurer,
 *     Provincial Auditor, Provincial Press Officer, Provincial Custodian);
 *   - `governor` is filled in every college EXCEPT the one in
 *     `OPEN_PROVINCIAL_COLLEGE`, whose race is left empty in both parties.
 *
 * So exactly four seats are open: President ASLE, President SVEA, and the
 * Governor ASLE / Governor SVEA seats of the open college. Those are the
 * races a real applicant can file for — a demo candidate must not consume
 * them.
 *
 * Every seeded candidate is filed as `approved`, and the slate is built to
 * respect the seat cap in every race it touches (one per party per college
 * for the provincial posts, one per party per college and year for the
 * year-level seat, 12 per party for senator), so the panel can still approve
 * and re-approve every one of them. The cap is asked for through
 * `SeatCap`, the same helper the apply and approve paths use, so the seeder
 * can never produce a slate the admin could not approve.
 *
 * ## The accounts
 *
 * Real, distinct Filipino names (no " 2" suffixes from a re-run), each with
 * its own student ID, a registrar eligibility row, and a login handle derived
 * from the name — the same shape the registrar CSV import and the console's
 * "Add single student" produce.
 *
 * The password is the student ID, so a demo login is reproducible without a
 * spreadsheet. That makes it a PUBLICLY KNOWN credential: this seeder is for
 * a test cycle on a throwaway roster, never for a real election.
 *
 * NEVER runs in production without an explicit opt-in: see
 * `TestBallotSeeder::demoSeedAllowed()` and the `ALLOW_DEMO_SEED` flag.
 */
class DemoSlateSeeder extends Seeder
{
    /** The national races that stay empty so a real applicant can file. */
    private const OPEN_NATIONAL_POSITIONS = ['president'];

    /** The provincial race that stays empty, and the college that owns it. */
    private const OPEN_PROVINCIAL_POSITION = 'governor';

    private const OPEN_PROVINCIAL_COLLEGE = 'College of Computer Studies';

    /** National single-seat posts: one nominee per party. */
    private const NATIONAL_SINGLE_SEATS = [
        'vice_president',
        'secretary',
        'treasurer',
        'auditor',
        'press_officer',
        'property_custodian',
    ];

    /** Provincial posts: one race per college, one nominee per party. */
    private const PROVINCIAL_SEATS = [
        'vice_governor',
        'provincial_secretary',
        'provincial_treasurer',
        'provincial_auditor',
        'provincial_press_officer',
        'provincial_custodian',
    ];

    /** `senator` has 12 seats, so it fields a full slate per party. */
    private const SENATOR_NOMINEES_PER_PARTY = 12;

    private const PARTIES = ['ASLE', 'SVEA'];

    private const YEAR_LEVELS = ['1', '2', '3', '4'];

    /**
     * Minimum voters per (college, year) electorate.
     *
     * A year level with no voters is a seat that can never be contested, so
     * every group is topped up to this floor with plain students (no
     * candidacy attached). A constant floor — not a share of the roster — so
     * a re-run is a no-op instead of a generator.
     */
    private const MIN_VOTERS_PER_GROUP = 8;

    /** The demo student IDs run from here upwards. */
    private const STUDENT_ID_BASE = 101;

    /**
     * 40 first names x 40 last names, walked so that every pair is used
     * exactly once before any name repeats.
     */
    private const FIRST_NAMES = [
        'Jasmine', 'Marco', 'Andrea', 'Paolo', 'Kristine', 'Bryan', 'Erica', 'Gerald',
        'Liza', 'Patrick', 'Nicole', 'Vincent', 'Sarah', 'Dennis', 'Rachel', 'Angelo',
        'Camille', 'Renz', 'Trixie', 'Miguel', 'Hannah', 'Ivan', 'Dianne', 'Karl',
        'Patricia', 'Adrian', 'Sofia', 'Marc', 'Aira', 'Rafael', 'Bianca', 'Elijah',
        'Chloe', 'Nathan', 'Denise', 'Jerome', 'Maria', 'Allan', 'Gina',
    ];

    private const LAST_NAMES = [
        'Santos', 'Reyes', 'Garcia', 'Mendoza', 'Cruz', 'Bautista', 'Dela Cruz', 'Aquino',
        'Ramos', 'Fernandez', 'Villanueva', 'Navarro', 'Castillo', 'Lim', 'Domingo', 'Ocampo',
        'Mendiola', 'Soriano', 'Salvacion', 'Manalo', 'Coronel', 'Pineda', 'Lacson', 'Abadilla',
        'Ventura', 'Suarez', 'Mercado', 'Rivera', 'Villamor', 'Tolentino', 'Dizon', 'Rosales',
        'Padilla', 'Estrada', 'Malonzo', 'Agbayani', 'Buenaventura', 'Dimaculangan', 'Pangilinan',
    ];

    public function run(): void
    {
        if (app()->environment('production') && ! (new TestBallotSeeder())->demoSeedAllowed()) {
            $this->command?->warn(
                'DemoSlateSeeder skipped: it provisions accounts with publicly-known passwords '
                .'and must not run against a production database without an explicit opt-in. '
                .'Set ALLOW_DEMO_SEED=true to override explicitly.'
            );

            return;
        }

        $positions = Position::query()->whereIn('slug', array_merge(
            self::NATIONAL_SINGLE_SEATS,
            self::PROVINCIAL_SEATS,
            ['senator', 'year_level_representative', 'president', self::OPEN_PROVINCIAL_POSITION],
        ))->get()->keyBy('slug');

        $missing = array_values(array_diff(
            array_merge(self::NATIONAL_SINGLE_SEATS, self::PROVINCIAL_SEATS, ['senator', 'year_level_representative']),
            $positions->keys()->all(),
        ));

        if ($missing !== []) {
            $this->command?->warn('DemoSlateSeeder skipped: the positions '.implode(', ', $missing).' do not exist. Run the migrations first.');

            return;
        }

        $colleges = DepartmentCatalog::collegeNames();
        $openCollege = $this->openCollege($colleges);

        $plan = $this->plan($colleges, $openCollege);

        $created = 0;
        $skipped = 0;
        $students = 0;

        foreach ($plan as $index => $slot) {
            $position = $positions->get($slot['position']);

            if ($position === null) {
                continue;
            }

            $user = $this->ensureStudent($index, $slot, $colleges);

            if ($user === null) {
                continue;
            }

            $students++;

            // The cap is asked through the same helper the apply and approve
            // paths use, so a slot the panel could not approve is never
            // filled. It also makes a re-run a no-op: an occupied race is
            // skipped rather than duplicated.
            if (SeatCap::isTaken($position, $slot['party'], $user)) {
                $skipped++;

                continue;
            }

            $this->fileCandidate($user, $position, $slot['party']);
            $created++;
        }

        $voters = $this->topUpVoters($colleges, count($plan));

        $this->report($created, $skipped, $students, $voters, $openCollege);
    }

    /**
     * The races to fill, in a fixed order so a re-run produces the same
     * accounts for the same slots.
     *
     * @return list<array{position: string, party: string, college: string, year: string}>
     */
    private function plan(array $colleges, string $openCollege): array
    {
        $plan = [];
        $collegeCount = count($colleges);
        $yearCount = count(self::YEAR_LEVELS);
        $i = 0;

        // National single-seat posts: one nominee per party, spread over the
        // colleges so no college monopolises the national slate.
        foreach (self::NATIONAL_SINGLE_SEATS as $slug) {
            foreach (self::PARTIES as $party) {
                $plan[] = [
                    'position' => $slug,
                    'party' => $party,
                    'college' => $colleges[$i % $collegeCount],
                    'year' => self::YEAR_LEVELS[$i % $yearCount],
                ];
                $i++;
            }
        }

        // Senators: a full multi-nominee slate per party.
        for ($n = 0; $n < self::SENATOR_NOMINEES_PER_PARTY; $n++) {
            foreach (self::PARTIES as $party) {
                $plan[] = [
                    'position' => 'senator',
                    'party' => $party,
                    'college' => $colleges[$n % $collegeCount],
                    'year' => self::YEAR_LEVELS[$n % $yearCount],
                ];
            }
        }

        // Year Level Representative: one race per (college, year level), one
        // nominee per party. The nominee must sit in that exact college AND
        // year, or the race would be empty for its own electorate.
        foreach ($colleges as $college) {
            foreach (self::YEAR_LEVELS as $year) {
                foreach (self::PARTIES as $party) {
                    $plan[] = [
                        'position' => 'year_level_representative',
                        'party' => $party,
                        'college' => $college,
                        'year' => $year,
                    ];
                }
            }
        }

        // Provincial posts: one race per college, one nominee per party, and
        // the nominee must be in that college.
        foreach (self::PROVINCIAL_SEATS as $slug) {
            foreach ($colleges as $college) {
                foreach (self::PARTIES as $party) {
                    $plan[] = [
                        'position' => $slug,
                        'party' => $party,
                        'college' => $college,
                        'year' => self::YEAR_LEVELS[$i % $yearCount],
                    ];
                    $i++;
                }
            }
        }

        // Governor: every college except the one whose race stays open.
        foreach ($colleges as $college) {
            if ($college === $openCollege) {
                continue;
            }

            foreach (self::PARTIES as $party) {
                $plan[] = [
                    'position' => self::OPEN_PROVINCIAL_POSITION,
                    'party' => $party,
                    'college' => $college,
                    'year' => self::YEAR_LEVELS[$i % $yearCount],
                ];
                $i++;
            }
        }

        return $plan;
    }

    /**
     * The college whose Governor race is left open. Falls back to the first
     * college when the catalog does not carry the expected name, so a renamed
     * college still produces a usable demo slate.
     */
    private function openCollege(array $colleges): string
    {
        foreach ($colleges as $college) {
            if (mb_strtolower(trim($college)) === mb_strtolower(self::OPEN_PROVINCIAL_COLLEGE)) {
                return $college;
            }
        }

        $this->command?->warn(
            'College "'.self::OPEN_PROVINCIAL_COLLEGE.'" is not in the catalog; '
            .'the open Governor race is placed in "'.($colleges[0] ?? 'none').'".'
        );

        return $colleges[0] ?? self::OPEN_PROVINCIAL_COLLEGE;
    }

    /**
     * The demo student for a slot, created on first use.
     *
     * The student ID is derived from the slot index, so a re-run finds the
     * same account instead of creating a second one.
     */
    private function ensureStudent(int $index, array $slot, array $colleges): ?User
    {
        $studentId = $this->studentId($index);

        $existing = User::query()->where('student_id', $studentId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $name = $this->name($index);
        $email = $this->usernameFromName($name);

        if (User::query()->where('email', $email)->exists()) {
            $email = $email.'.'.strtolower($studentId);
        }

        $course = $this->pickCourse($slot['college'], $index);

        $user = User::create([
            'student_id' => $studentId,
            'name' => $name,
            'email' => $email,
            'password' => Hash::make($studentId),
            'role' => 'student',
            'year_level' => $slot['year'],
            'block_number' => (string) ((((int) $slot['year'] - 1) % 2) + 1),
            'department' => $slot['college'],
            'course' => $course,
            'has_voted' => false,
            // The demo password IS the student ID, so there is no temporary
            // credential to rotate — the flag would only gate the ballot.
            'must_change_password' => false,
            'is_active' => true,
        ]);

        // The eligibility feed is what self-registration and password recovery
        // validate against, so a demo account gets the same registrar row a
        // CSV import or the console's "Add single student" would produce.
        RegistrarImport::updateOrCreate(
            ['student_id' => $studentId],
            [
                'full_name' => $name,
                'email' => $email,
                'role' => 'student',
                'year_level' => $slot['year'],
                'block_number' => $user->block_number,
                'department' => $slot['college'],
                'course' => $course,
                'needs_review' => false,
                'review_reason' => null,
            ],
        );

        return $user;
    }

    /**
     * Top every (college, year) electorate up to the voter floor with plain
     * students, so no seat is contested by nobody and the turnout figures
     * look like a real roster.
     *
     * @return int the number of plain students created
     */
    private function topUpVoters(array $colleges, int $indexOffset): int
    {
        $index = $indexOffset;
        $created = 0;

        foreach ($colleges as $college) {
            foreach (self::YEAR_LEVELS as $year) {
                $have = User::query()
                    ->whereIn('role', User::VOTER_ROLES)
                    ->where('department', $college)
                    ->where('year_level', $year)
                    ->count();

                for ($n = $have; $n < self::MIN_VOTERS_PER_GROUP; $n++) {
                    $studentId = $this->studentId($index);

                    if (! User::query()->where('student_id', $studentId)->exists()) {
                        $name = $this->name($index);
                        $email = $this->usernameFromName($name);

                        if (User::query()->where('email', $email)->exists()) {
                            $email = $email.'.'.strtolower($studentId);
                        }

                        User::create([
                            'student_id' => $studentId,
                            'name' => $name,
                            'email' => $email,
                            'password' => Hash::make($studentId),
                            'role' => 'student',
                            'year_level' => $year,
                            'block_number' => (string) ((((int) $year - 1) % 2) + 1),
                            'department' => $college,
                            'course' => $this->pickCourse($college, $index),
                            'has_voted' => false,
                            'must_change_password' => false,
                            'is_active' => true,
                        ]);

                        RegistrarImport::updateOrCreate(
                            ['student_id' => $studentId],
                            [
                                'full_name' => $name,
                                'email' => $email,
                                'role' => 'student',
                                'year_level' => $year,
                                'block_number' => (string) ((((int) $year - 1) % 2) + 1),
                                'department' => $college,
                                'course' => $this->pickCourse($college, $index),
                                'needs_review' => false,
                                'review_reason' => null,
                            ],
                        );

                        $created++;
                    }

                    $index++;
                }
            }
        }

        return $created;
    }

    /**
     * File one candidate, already approved.
     */
    private function fileCandidate(User $user, Position $position, string $party): void
    {
        Candidate::create([
            'user_id' => $user->id,
            'position_id' => $position->id,
            'candidate_ref' => (string) Str::uuid(),
            'party_name' => $party,
            'slogan' => $user->name.' para sa '.$position->label.', sama na sa '.$party.'!',
            'platform_statement' => 'I am running for '.$position->label.' under '.$party.' representing '
                .($user->department ?? 'my college').'. Focus on student services, transparent student '
                .'funds, and active listening to every campus voice.',
            'platform_points' => [
                'Student-focused programs and services',
                'Transparent handling of student funds',
                'Open dialogue with every student organization',
            ],
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);

        // The candidate role is derived from a live candidacy, never assigned
        // by hand.
        CandidateRoleSync::sync($user);
    }

    /**
     * The demo student ID for a slot index.
     */
    private function studentId(int $index): string
    {
        return '2024-01'.str_pad((string) (self::STUDENT_ID_BASE + $index), 3, '0', STR_PAD_LEFT);
    }

    /**
     * The name for a slot index: first names cycle through the list while the
     * last name advances every full cycle, so no pair repeats until all
     * 1,600 combinations are used.
     */
    private function name(int $index): string
    {
        $firsts = count(self::FIRST_NAMES);
        $lasts = count(self::LAST_NAMES);

        $first = self::FIRST_NAMES[$index % $firsts];
        $last = self::LAST_NAMES[intdiv($index, $firsts) % $lasts];

        return $first.' '.$last;
    }

    /**
     * "Jasmine Santos" -> "jasmine.santos", the handle convention the
     * registrar import uses.
     */
    private function usernameFromName(string $fullName): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '.', strtolower($fullName)), '.');

        return $slug === '' ? 'student.'.Str::uuid()->toString() : $slug;
    }

    /** A program the college actually offers. */
    private function pickCourse(string $college, int $seed): ?string
    {
        $courses = DepartmentCatalog::coursesOf($college);

        if ($courses === []) {
            return null;
        }

        return $courses[$seed % count($courses)];
    }

    /**
     * Print what the slate now looks like, and which seats are still open.
     */
    private function report(int $created, int $skipped, int $students, int $voters, string $openCollege): void
    {
        $this->command?->info('');
        $this->command?->info('Demo slate:');
        $this->command?->info('  candidates created: '.$created.'   skipped: '.$skipped);
        $this->command?->info('  demo accounts: '.$students.' candidates + '.$voters.' extra voters');

        $rows = Candidate::query()
            ->join('positions', 'positions.id', '=', 'candidates.position_id')
            ->join('users', 'users.id', '=', 'candidates.user_id')
            // Archived winners live in Past Terms, not on the live ballot.
            ->when(
                Schema::hasColumn('candidates', 'archived_at'),
                fn ($q) => $q->whereNull('candidates.archived_at')
            )
            ->get([
                'positions.label as position',
                'positions.slug as slug',
                'candidates.party_name as party',
                'users.name as name',
                'users.department as department',
            ]);

        $byPosition = $rows->groupBy('position');

        foreach (Position::query()->orderBy('tier')->orderBy('sort_order')->get() as $position) {
            $entries = $byPosition->get($position->label, collect());

            if ($entries->isEmpty()) {
                $this->command?->info(sprintf(
                    '  %-32s %s',
                    $position->label,
                    in_array($position->slug, self::OPEN_NATIONAL_POSITIONS, true)
                        ? 'OPEN — left empty for a live applicant'
                        : '— no candidate'
                ));

                continue;
            }

            $open = $entries->filter(
                fn ($e) => $e->slug === self::OPEN_PROVINCIAL_POSITION
                    && $e->department === $openCollege
            );

            $this->command?->info(sprintf(
                '  %-32s %s%s',
                $position->label,
                $entries->map(fn ($e) => $e->name.' ('.$e->party.', '.$e->department.')')->implode(' · '),
                $open->isEmpty() ? '' : '   [OPEN in '.$openCollege.']'
            ));
        }
    }
}
