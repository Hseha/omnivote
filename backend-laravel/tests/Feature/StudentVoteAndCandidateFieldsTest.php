<?php

namespace Tests\Feature;

use App\Models\BallotDraft;
use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Student-facing fields the mobile client depends on, now driven by real
 * backend state instead of inert placeholders:
 *
 *   GET /api/candidates       — grade_level mirrors the registrant's year_level
 *   GET /api/candidates?grade — matches year_level (not a needle in the email)
 *   GET /api/registration/me  — eligibility reflects the account's is_active
 *   POST /api/vote            — honors Settings → Voting → maxVotesPerVoter
 */
class StudentVoteAndCandidateFieldsTest extends TestCase
{
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
    }

    public function test_candidates_return_grade_level_from_the_users_year_level(): void
    {
        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president', year: '11');

        $this->getJson('/api/candidates')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.grade_level', '11');
    }

    public function test_grade_filter_matches_year_level_not_email(): void
    {
        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president', year: '11');
        // Candidate whose email happens to contain the lookup grade, but whose
        // actual year_level differs — the old email-LIKE filter would match it.
        $this->makeApprovedCandidate('ref-ben', 'BB', 'president', year: '12', email: '11@example.test');

        $this->getJson('/api/candidates?grade=11')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.candidate_ref', 'ref-ana');
    }

    public function test_grade_level_is_null_when_the_user_has_no_year_level(): void
    {
        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president', year: null);

        $this->getJson('/api/candidates')
            ->assertOk()
            ->assertJsonPath('data.0.grade_level', null);
    }

    public function test_registration_me_reports_active_eligibility(): void
    {
        $response = $this->asStudent()->getJson('/api/registration/me');

        $response->assertOk()
            ->assertJsonPath('eligibility_status', 'Eligible Voter');
    }

    public function test_registration_me_reflects_a_disabled_account(): void
    {
        $response = $this->asStudent(['is_active' => false])->getJson('/api/registration/me');

        $response->assertOk()
            ->assertJsonPath('eligibility_status', 'Ineligible - Account Disabled');
    }

    public function test_vote_submission_enforces_the_stored_max_positions_cap(): void
    {
        $this->openVoting();

        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.voting.maxVotesPerVoter'],
            ['value' => '1', 'updated_at' => now()],
        );

        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president');
        $this->makeApprovedCandidate('ref-s1', 'S1', 'senator');

        // One position only → allowed.
        $this->asStudent()
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201)
            ->assertJsonStructure(['receipt']);

        // President + senator covers two positions → cap of 1 refuses it.
        $this->asStudent()
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana', 'senator' => ['ref-s1']]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ballot allows voting in at most 1 position(s).');
    }

    public function test_vote_submission_is_unconstrained_when_the_setting_was_never_saved(): void
    {
        $this->openVoting();

        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president');
        $this->makeApprovedCandidate('ref-s1', 'S1', 'senator');

        $this->asStudent()
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana', 'senator' => ['ref-s1']]])
            ->assertStatus(201)
            ->assertJsonStructure(['receipt']);
    }

    public function test_a_candidate_cannot_vote_for_themselves(): void
    {
        $this->openVoting();

        $runner = $this->makeUser(['name' => 'Ana Santos']);
        $this->makeCandidateFor($runner, 'ref-ana', 'president');

        $this->actingAs($runner, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(422)
            ->assertJsonPath('message', "You cannot vote for yourself in position 'president'.");

        $this->assertFalse($runner->fresh()->has_voted);
    }

    /* On a 12-seat race a candidate would otherwise take a seat of their own
       party, and could take every one of them. The self-vote check has to
       reject the whole batch, not just the offending reference. */
    public function test_a_candidate_cannot_vote_for_themselves_on_a_multi_seat_race(): void
    {
        $this->openVoting();

        $runner = $this->makeUser(['name' => 'Ana Santos']);
        $rival = $this->makeUser(['name' => 'Bea Cruz']);
        $this->makeCandidateFor($runner, 'ref-mine', 'senator');
        $this->makeCandidateFor($rival, 'ref-theirs', 'senator');

        $this->actingAs($runner, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['senator' => ['ref-mine', 'ref-theirs']]])
            ->assertStatus(422)
            ->assertJsonPath('message', "You cannot vote for yourself in position 'senator'.");

        $this->assertFalse($runner->fresh()->has_voted);
    }

    public function test_voting_for_another_candidate_is_still_allowed(): void
    {
        $this->openVoting();

        $voter = $this->makeUser(['name' => 'Voter']);
        $other = $this->makeUser(['name' => 'Ana Santos']);
        $this->makeCandidateFor($other, 'ref-ana', 'president');

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201)
            ->assertJsonStructure(['receipt']);
    }

    public function test_vote_submission_writes_an_anonymous_ledger_and_marks_the_ballot(): void
    {
        $this->openVoting();

        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president');

        $voter = $this->makeUser();
        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('vote_ledger')->count());
        $this->assertSame('submitted', BallotDraft::where('user_id', $voter->id)->value('status'));
        $this->assertTrue($voter->fresh()->has_voted);
    }

    /**
     * A second submit is a 409, but it must read back as confirmation, not
     * error: the mobile client commonly lost the first response on school
     * Wi-Fi and retries. The body carries the recording time and nothing else —
     * the receipt is returned exactly once, and echoing it (or any choice
     * reference) in the 409 would re-attach the ballot to the voter (H-1).
     */
    public function test_repeat_submit_returns_already_voted_with_time_but_no_choices_or_receipt(): void
    {
        $this->openVoting();

        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president');

        $voter = $this->makeUser();
        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201);

        // Exactly one ledger row exists — the repeat must not cast again.
        $this->assertSame(1, DB::table('vote_ledger')->count());

        $response = $this->actingAs($voter->fresh(), 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']]);

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Already voted')
            ->assertJsonPath('voted_at', $voter->fresh()->voted_at?->toIso8601String());

        // The 409 reveals the recording time only — no receipt, no selections,
        // no candidate reference, so a dropped-response retry still confirms
        // the vote without ever linking the voter to their choices.
        $this->assertSame(['message', 'voted_at'], array_keys($response->json()));

        $this->assertSame(1, DB::table('vote_ledger')->count());
        $this->assertSame(1, DB::table('ballot_drafts')->where('user_id', $voter->id)->count());
    }

    /**
     * H-1: the submitted ballot must not be joinable back to the voter.
     *
     * The original implementation wrote the plaintext `selections` *and* the
     * plaintext `receipt_token` onto the `user_id`-keyed ballot_drafts row.
     * Because the ledger stores HMAC(receipt, APP_KEY), that pair let anyone
     * holding the database de-anonymise every ballot. On submit the draft must
     * therefore retain nothing but the `submitted` status.
     */
    public function test_submitted_ballot_draft_holds_no_selections_or_receipt(): void
    {
        $this->openVoting();
        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president');

        $voter = $this->makeUser();
        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201);

        $draft = BallotDraft::where('user_id', $voter->id)->firstOrFail();

        $this->assertNull($draft->selections, 'submitted draft must not retain the plaintext choices');
        $this->assertNull($draft->receipt_token, 'submitted draft must not retain the plaintext receipt');

        // The choices survive only in the decoupled ledger, which carries no
        // user foreign key to join back on.
        $ledgerColumns = Schema::getColumnListing('vote_ledger');
        $this->assertNotContains('user_id', $ledgerColumns, 'the ledger must not be keyed by voter');
        $this->assertNotContains('voter_id', $ledgerColumns, 'the ledger must not be keyed by voter');

        $this->assertSame(
            ['ref-ana'],
            DB::table('vote_ledger')->pluck('candidate_ref')->all(),
        );

        // And no value on the user-keyed row can reproduce the ledger HMAC.
        foreach (['2024-0001', $voter->student_id, $voter->email] as $publicValue) {
            $this->assertNotSame(
                DB::table('vote_ledger')->value('receipt_hmac'),
                hash_hmac('sha256', (string) $publicValue, (string) config('app.key')),
                'ledger HMAC must not be derivable from data stored beside user_id',
            );
        }
    }

    /**
     * C-1: `must_change_password` is a *server-side* gate, not a client hint.
     *
     * The Flutter client routes flagged accounts to the change-password screen,
     * but any HTTP client skips that, so a token minted at login must not be
     * able to vote, read a ballot or touch candidacy until the password is
     * actually rotated.
     */
    public function test_flagged_account_cannot_vote_before_rotating_its_password(): void
    {
        $this->openVoting();
        $this->makeApprovedCandidate('ref-ana', 'Ana', 'president');

        $voter = $this->makeUser(['must_change_password' => true]);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(403)
            ->assertJsonPath('must_change_password', true);

        $this->assertSame(0, DB::table('vote_ledger')->count());
        $this->assertFalse($voter->fresh()->has_voted, 'a blocked voter must not be marked as having voted');

        // Once the flag is cleared the very same route succeeds.
        $voter->forceFill(['must_change_password' => false])->save();

        $this->actingAs($voter->fresh(), 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201);
    }

    /* ------------------------------------------------------------------ */

    /** A request authenticated as a student via the sanctum guard. */
    private function asStudent(array $attributes = []): static
    {
        return $this->actingAs($this->makeUser($attributes), 'sanctum');
    }

    /** An approved candidate row owned by an existing account. */
    private function makeCandidateFor(User $owner, string $ref, string $positionSlug): Candidate
    {
        return Candidate::create([
            'user_id' => $owner->id,
            'position_id' => DB::table('positions')->where('slug', $positionSlug)->value('id'),
            'candidate_ref' => $ref,
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);
    }

    /** Configures a live voting window → derived phase voting_open. */
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

    private function makeApprovedCandidate(
        string $ref,
        string $name,
        string $positionSlug,
        ?string $year = '11',
        ?string $email = null,
    ): Candidate {
        return Candidate::create([
            'user_id' => $this->makeUser([
                'name' => $name,
                'email' => $email ?? Str::uuid().'@example.test',
                'year_level' => $year,
            ])->id,
            'position_id' => DB::table('positions')->where('slug', $positionSlug)->value('id'),
            'candidate_ref' => $ref,
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret-password',
            'role' => 'student',
            'is_active' => true,
            'year_level' => '11',
        ], $attributes));
    }

    /** Minimal schema for the student candidate/vote flows exercised here. */
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
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier')->default('national');
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach ([['president', 'President', 'national', 1], ['senator', 'Senator', 'national', 12]] as [$slug, $label, $tier, $seats]) {
            DB::table('positions')->insert([
                'slug' => $slug,
                'label' => $label,
                'tier' => $tier,
                'seat_count' => $seats,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('party_list')->nullable();
            $table->string('position_key')->nullable();
            $table->string('approval_status')->default('pending');
            $table->boolean('certified_winner')->default(false);
            $table->timestamp('certified_at')->nullable();
            $table->string('election_status', 16)->default('pending');
            $table->unsignedInteger('winner_rank')->nullable();
            $table->unsignedInteger('vote_total')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->string('term_label', 32)->nullable();
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

        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->id();
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
}
