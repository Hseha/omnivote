<?php

namespace Tests\Feature;

use App\Http\Controllers\VoteController;
use App\Models\Candidate;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The ballot is elected on three different axes, and a regression here is
 * invisible to any single-tier test:
 *
 *   national    every student votes, whatever their college or year
 *   year_level  a 1st-year student votes only in the 1st-year seat
 *   provincial  a student votes only in their own college's race
 *
 * `PositionScopeTest` exercises the primitives in isolation. These tests pin the
 * model onto real seeded positions, because the actual failure was never in
 * `voterIsInScope()` — it was that no position was ever configured to use it, so
 * every seat silently behaved as 'global'.
 */
class BallotElectorateScopeTest extends TestCase
{
    /**
     * Builds an isolated in-memory SQLite schema instead of using
     * RefreshDatabase: the migration set contains MySQL-only `ALTER TABLE
     * ... MODIFY` statements (2026_09_05_000002_extend_candidates_table), so
     * running the real migrations either fails or touches the live MySQL
     * database. Mirrors DisabledUserLoginTest for the same reason.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn () => 'testing');

        DB::purge();

        config([
            'database.default' => 'omnivote_testing',
            'database.connections.omnivote_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'session.driver' => 'array',
            'cache.default' => 'array',
        ]);

        DB::setDefaultConnection('omnivote_testing');
        DB::purge('omnivote_testing');

        $this->createSchema();
        $this->seedBallotPositions();
        $this->openVoting();
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->default('student');
            $table->string('year_level')->nullable();
            $table->string('block_number')->nullable();
            $table->string('department')->nullable();
            $table->string('course')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier');
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            // resolveSelections() only loads seats with is_active = true, so a
            // position left inactive is invisible to the vote path entirely.
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('party_name')->nullable();
            $table->text('slogan')->nullable();
            $table->text('platform_statement')->nullable();
            $table->string('approval_status')->default('pending');
            $table->boolean('certified_winner')->default(false);
            $table->timestamp('certified_at')->nullable();
            $table->string('election_status', 16)->default('pending');
            $table->unsignedInteger('winner_rank')->nullable();
            $table->unsignedInteger('vote_total')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('vote_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('election_id')->nullable();
            $table->string('position_key');
            $table->string('candidate_ref');
            $table->string('receipt_hmac');
            $table->unsignedBigInteger('ledger_sequence')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
        });

        Schema::create('ballot_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('selections')->nullable();
            $table->string('status')->default('draft');
            $table->string('receipt_token', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        foreach (['registration', 'voting_open', 'voting_closed', 'registration_closed'] as $phase) {
            DB::table('phases')->insert([
                'name' => $phase,
                'description' => $phase,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    /**
     * `checkPhase:voting_open` derives the live phase from the Settings →
     * Voting window, not from the `phases` table, so an open window is what
     * makes POST /api/vote reachable at all.
     */
    private function openVoting(): void
    {
        $window = [
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->subDays(2),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ];

        foreach ($window as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }

    /**
     * Mirrors 2026_09_28_000001_apply_ballot_electorate_scopes: the whole point
     * of these tests is that the seeded configuration, not the guard code, is
     * what makes the ballot correct.
     */
    private function seedBallotPositions(): void
    {
        $national = ['president', 'vice_president', 'secretary', 'treasurer', 'auditor', 'senator'];
        $provincial = [
            'governor', 'vice_governor', 'provincial_secretary',
            'provincial_treasurer', 'provincial_auditor',
            'provincial_press_officer', 'provincial_custodian',
        ];

        foreach ($national as $slug) {
            Position::create([
                'slug' => $slug,
                'label' => ucwords(str_replace('_', ' ', $slug)),
                'tier' => 'national',
                'scope_type' => 'global',
                'seat_count' => $slug === 'senator' ? 12 : 1,
            ]);
        }

        Position::create([
            'slug' => 'year_level_representative',
            'label' => 'Year Level Representative',
            'tier' => 'national',
            'scope_type' => 'year_level',
            'scope_value' => null,
        ]);

        foreach ($provincial as $slug) {
            Position::create([
                'slug' => $slug,
                'label' => ucwords(str_replace('_', ' ', $slug)),
                'tier' => 'provincial',
                'scope_type' => 'department',
                'scope_value' => null,
            ]);
        }
    }

    private function position(string $slug): Position
    {
        return Position::where('slug', $slug)->firstOrFail();
    }

    private function student(string $dept, string $year): User
    {
        static $seq = 0;
        $seq++;

        return User::create([
            'student_id' => '2024-01'.str_pad((string) $seq, 3, '0', STR_PAD_LEFT),
            'name' => "Student {$seq}",
            'email' => "student{$seq}@example.com",
            'password' => bcrypt('secret'),
            'role' => 'student',
            'department' => $dept,
            'year_level' => $year,
            'has_voted' => false,
            'is_active' => true,
        ]);
    }

    private function candidateFor(Position $position, User $user, string $party = 'ASLE'): Candidate
    {
        static $seq = 0;
        $seq++;

        return Candidate::create([
            'user_id' => $user->id,
            'position_id' => $position->id,
            'candidate_ref' => "ref-{$position->slug}-{$seq}",
            'party_name' => $party,
            'approval_status' => 'approved',
        ]);
    }

    /** Exercises the same private guard the HTTP submit path calls. */
    private function mayVoteFor(VoteController $controller, Position $position, Candidate $candidate, User $voter): bool
    {
        $guard = new ReflectionMethod($controller, 'candidateIsInScope');

        return (bool) $guard->invoke($controller, $position, $candidate, $voter);
    }

    public function test_provincial_seats_are_scoped_to_the_voters_own_college(): void
    {
        $governor = $this->position('governor');
        $this->assertSame('department', $governor->scope_type);
        $this->assertNull($governor->scope_value, 'A blank value means "your own college".');

        $voter = $this->student('College of Arts and Sciences', '1');

        $ownCollege = $this->candidateFor($governor, $this->student('College of Arts and Sciences', '2'));
        $otherCollege = $this->candidateFor($governor, $this->student('College of Computer Studies', '3'));

        $this->assertTrue($governor->voterIsInScope($voter));
        $this->assertTrue($governor->voterIsInScope($voter->fresh()));

        $svc = app(VoteController::class);

        $this->assertTrue($this->mayVoteFor($svc, $governor, $ownCollege, $voter));
        $this->assertFalse(
            $this->mayVoteFor($svc, $governor, $otherCollege, $voter),
            'A student must not be able to vote for another college\'s governor.'
        );
    }

    public function test_every_provincial_seat_is_scoped(): void
    {
        $provincial = Position::where('tier', 'provincial')->get();
        $this->assertCount(7, $provincial);

        foreach ($provincial as $position) {
            $this->assertSame('department', $position->scope_type, "{$position->slug} is unscoped.");
        }
    }

    public function test_year_level_representative_is_scoped_to_the_students_own_year(): void
    {
        $ylr = $this->position('year_level_representative');
        $this->assertSame('national', $ylr->tier, 'It stays a national-tier seat.');
        $this->assertSame('year_level', $ylr->scope_type);

        $firstYear = $this->student('College of Arts and Sciences', '1');
        $svc = app(VoteController::class);

        $ownYear = $this->candidateFor($ylr, $this->student('College of Computer Studies', '1'));
        $otherYear = $this->candidateFor($ylr, $this->student('College of Computer Studies', '2'));

        $this->assertTrue($this->mayVoteFor($svc, $ylr, $ownYear, $firstYear));
        $this->assertFalse($this->mayVoteFor($svc, $ylr, $otherYear, $firstYear));

        // Year is the axis, not college: a nominee from another college is fine.
        $this->assertTrue($this->mayVoteFor(
            $svc,
            $ylr,
            $ownYear,
            $this->student('College of Criminal Justice Education', '1')
        ));
    }

    public function test_national_seats_remain_open_to_every_student(): void
    {
        foreach (['president', 'senator', 'auditor'] as $slug) {
            $this->assertSame(
                'global',
                $this->position($slug)->scope_type,
                "{$slug} must stay open to the whole student body."
            );
        }

        $president = $this->position('president');
        $voter = $this->student('College of Arts and Sciences', '1');
        $anywhere = $this->candidateFor($president, $this->student('College of Computer Studies', '4'));

        $this->assertTrue($this->mayVoteFor(app(VoteController::class), $president, $anywhere, $voter));
    }

    public function test_a_student_without_a_year_level_is_refused_the_year_level_seat(): void
    {
        $ylr = $this->position('year_level_representative');
        $voter = $this->student('College of Arts and Sciences', '1');
        $voter->year_level = null;
        $voter->save();

        $this->assertFalse($ylr->voterIsInScope($voter->fresh()));
    }

    /**
     * The end-to-end proof, over real HTTP with a real Sanctum token: a
     * cross-college provincial ballot is refused at submit time, not merely
     * hidden by the client. Everything above tests the guard in isolation; this
     * proves `POST /api/vote` actually consults it and writes nothing.
     */
    public function test_posting_a_cross_college_provincial_ballot_is_refused(): void
    {
        $governor = $this->position('governor');
        $voter = $this->student('College of Arts and Sciences', '1');
        $otherCollege = $this->candidateFor($governor, $this->student('College of Computer Studies', '2'));

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => [$governor->slug => $otherCollege->candidate_ref]])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('vote_ledger')->count(), 'A refused ballot must write nothing.');
    }

    public function test_a_student_can_post_a_ballot_for_their_own_college(): void
    {
        $governor = $this->position('governor');
        $voter = $this->student('College of Arts and Sciences', '1');
        $ownCollege = $this->candidateFor($governor, $this->student('College of Arts and Sciences', '2'));

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => [$governor->slug => $ownCollege->candidate_ref]])
            ->assertCreated();

        $this->assertSame(1, DB::table('vote_ledger')->count());
    }

    public function test_posting_another_years_year_level_representative_is_refused(): void
    {
        $ylr = $this->position('year_level_representative');
        $voter = $this->student('College of Arts and Sciences', '1');
        $secondYear = $this->candidateFor($ylr, $this->student('College of Computer Studies', '2'));

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => [$ylr->slug => $secondYear->candidate_ref]])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('vote_ledger')->count());
    }

    public function test_a_national_ballot_is_accepted_from_any_college_or_year(): void
    {
        $president = $this->position('president');
        $voter = $this->student('College of Arts and Sciences', '1');
        $nominee = $this->candidateFor($president, $this->student('College of Computer Studies', '4'));

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => [$president->slug => $nominee->candidate_ref]])
            ->assertCreated();

        $this->assertSame(1, DB::table('vote_ledger')->count());
    }

    public function test_year_levels_are_compared_as_exact_canonical_values(): void
    {
        $ylr = $this->position('year_level_representative');

        $firstYear = $this->student('College of Arts and Sciences', '1');
        $this->assertTrue($ylr->voterIsInScope($firstYear));

        // '11' is senior-high numbering, not college 1st year. Two layers guard
        // this, and they are deliberately different jobs:
        //
        //  1. The comparison itself is exact, so a legacy '11' that escaped
        //     normalisation can never be handed the 1st-year nominee.
        //  2. 2026_09_28_000002_normalize_year_level_to_1_4 maps '11' -> '1' so
        //     that student is re-homed onto the right seat instead of being
        //     left with none.
        //
        // Layer 1 alone would disenfranchise; layer 2 alone would trust stale
        // data. Asserted here so the exactness cannot be "simplified" away.
        $this->assertFalse($ylr->valuesMatch('11', '1'));
        $this->assertFalse($ylr->valuesMatch('1', '2'));
        $this->assertTrue($ylr->valuesMatch('1', ' 1 '));
    }
}
