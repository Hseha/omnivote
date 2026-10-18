<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Phase;
use App\Models\User;
use App\Support\TermArchive;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Comprehensive election reset endpoint (POST /api/admin/election/reset).
 *
 *   Requires `permission:election.reset` (admin only) and an explicit
 *   `confirm: "reset_election"` body field.
 *   Clears the timeline, deactivates the phase, un-certifies candidates,
 *   resets voter turnout, truncates the vote ledger, and writes an audit row.
 *
 * Uses the same isolated in-memory SQLite schema as the other admin tests.
 */
class ElectionResetTest extends TestCase
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

    public function test_reset_requires_election_reset_permission(): void
    {
        $teacher = $this->makeUser(['role' => 'teacher']);

        $this->actingAs($teacher);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertForbidden();
    }

    public function test_reset_requires_confirm_field(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', [])
            ->assertUnprocessable();
    }

    public function test_reset_rejects_wrong_confirm_value(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'yes'])
            ->assertUnprocessable();
    }

    public function test_reset_clears_entire_election(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // Seed pre-reset election data.
        DB::table('election_settings')->insert([
            ['key' => 'registration_opens_at', 'value' => now()->subDays(2)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'registration_closes_at', 'value' => now()->subDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_opens_at', 'value' => now()->subHours(5)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_closes_at', 'value' => now()->addDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Activate the voting_open phase row (already exists from schema).
        DB::table('phases')->where('name', 'voting_open')->update(['is_active' => true]);

        // Certified WINNER — must be preserved as an officer after reset.
        $winner = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-winner',
            'slogan' => 'Vote for me',
            'party_name' => 'Unity',
            'approval_status' => 'approved',
            'certified_winner' => true,
            'certified_at' => now(),
            'winner_rank' => 1,
            'vote_total' => 42,
            'election_status' => 'elected',
        ]);

        // Non-winner candidate — should be reset to pending.
        $loser = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-loser',
            'slogan' => 'Also ran',
            'party_name' => 'Opposition',
            'approval_status' => 'approved',
            'certified_winner' => false,
            'vote_total' => 10,
            'election_status' => 'lost',
        ]);

        // Vote ledger rows.
        DB::table('vote_ledger')->insert([
            ['position_key' => 'president', 'candidate_ref' => 'ref-winner', 'receipt_hmac' => 'hmac-1', 'recorded_at' => now()],
            ['position_key' => 'president', 'candidate_ref' => 'ref-loser', 'receipt_hmac' => 'hmac-2', 'recorded_at' => now()],
        ]);

        // Student who has voted.
        $voter = $this->makeStudent();
        $voter->update(['has_voted' => true, 'voted_at' => now()]);

        // Stale pre-submission draft — must not survive the reset.
        DB::table('ballot_drafts')->insert([
            'user_id' => $voter->id,
            'selections' => json_encode(['president' => 'ref-loser']),
            'status' => 'submitted',
            'receipt_token' => null,
            'submitted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk()
            ->assertJsonStructure(['message']);

        // Timeline cleared.
        foreach (['registration_opens_at', 'registration_closes_at', 'voting_opens_at', 'voting_closes_at'] as $key) {
            $this->assertNull(DB::table('election_settings')->where('key', $key)->value('value'));
        }

        // Phase deactivated.
        $this->assertNull(Phase::current());
        $this->assertFalse(DB::table('phases')->where('is_active', true)->exists());

        // WINNER PRESERVED: still certified, still archived as officer.
        $winner->refresh();
        $this->assertTrue($winner->certified_winner, 'Winner must remain certified after reset');
        $this->assertNotNull($winner->certified_at, 'Winner certified_at must be preserved');
        $this->assertSame(1, $winner->winner_rank, 'Winner rank must be preserved');
        $this->assertSame(42, $winner->vote_total, 'Winner vote total must be preserved');
        $this->assertNotNull($winner->archived_at, 'Winner must be archived as officer (Past Terms)');
        $this->assertNotNull($winner->term_label, 'Winner must have a term label');

        // NON-WINNER DELETED: clean slate — the row is gone, not merely
        // flipped back to pending.
        $this->assertFalse(DB::table('candidates')->where('id', $loser->id)->exists());
        $this->assertNull(Candidate::where('id', $loser->id)->first());

        // RECORD ARCHIVED: full cycle snapshot kept for review (both parties).
        $this->assertTrue(DB::table('election_candidate_records')
            ->where('party_name', 'Unity')
            ->where('certified_winner', true)
            ->exists());
        $this->assertTrue(DB::table('election_candidate_records')
            ->where('party_name', 'Opposition')
            ->where('certified_winner', false)
            ->where('election_status', 'lost')
            ->exists());
        $this->assertSame(2, DB::table('election_candidate_records')->count());

        // Vote ledger truncated.
        $this->assertFalse(DB::table('vote_ledger')->exists());

        // Ballot drafts cleared (no stale submitted row into the next cycle).
        $this->assertFalse(DB::table('ballot_drafts')->exists());

        // Voter turnout reset.
        $this->assertFalse($voter->fresh()->has_voted);
        $this->assertNull($voter->fresh()->voted_at);

        // Audit row recorded.
        $auditRow = DB::table('audit_logs')->where('action', 'election_reset')->first();
        $this->assertNotNull($auditRow);
        $this->assertSame($admin->id, (int) $auditRow->user_id);
        $this->assertStringContainsString('reset', (string) $auditRow->details);
    }

    public function test_reset_mid_registration_deletes_applications_and_records_them(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // No voting has happened: registration phase is live, empty ledger.
        // A reset is still a full clean slate — non-winners are deleted — but
        // the roster is preserved first in Election Records.
        DB::table('phases')->where('name', 'registration')->update(['is_active' => true]);
        DB::table('election_settings')->insert([
            ['key' => 'registration_opens_at', 'value' => now()->subDays(2)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'registration_closes_at', 'value' => now()->addDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $applicant = $this->makeStudent();
        $loser = Candidate::create([
            'user_id' => $applicant->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-applicant',
            'slogan' => 'Also ran',
            'party_name' => 'Opposition',
            'approval_status' => 'pending',
            'certified_winner' => false,
            'election_status' => 'pending',
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        // Phase/timeline cleared as before.
        $this->assertNull(Phase::current());

        // Applications DELETED — a reset is always a full clean slate, so the
        // old rows can never resurface on the next configured cycle.
        $this->assertFalse(DB::table('candidates')->where('id', $loser->id)->exists());
        $this->assertFalse(Candidate::where('id', $loser->id)->exists());

        // The roster is preserved for review in Election Records.
        $this->assertSame(1, DB::table('election_candidate_records')->count());
        $this->assertTrue(DB::table('election_candidate_records')
            ->where('party_name', 'Opposition')
            ->where('certified_winner', false)
            ->where('election_status', 'pending')
            ->exists());
    }

    public function test_reset_notifies_cleared_applicants_and_skips_everyone_else(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        DB::table('phases')->where('name', 'registration')->update(['is_active' => true]);

        // One applicant whose application this reset CLEARS, one certified
        // winner who is preserved as an officer, and one plain student who
        // never applied — only the applicant may hear about the reset.
        $applicant = $this->makeStudent();
        Candidate::create([
            'user_id' => $applicant->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-applicant',
            'slogan' => 'Also ran',
            'party_name' => 'Opposition',
            'approval_status' => 'pending',
            'certified_winner' => false,
            'election_status' => 'pending',
        ]);

        $winnerUser = $this->makeStudent();
        Candidate::create([
            'user_id' => $winnerUser->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-winner',
            'slogan' => 'Vote for me',
            'party_name' => 'Unity',
            'approval_status' => 'approved',
            'certified_winner' => true,
            'certified_at' => now(),
            'winner_rank' => 1,
            'vote_total' => 42,
            'election_status' => 'elected',
        ]);

        $bystander = $this->makeStudent();

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $applicant->id,
            'type' => 'warning',
            'title' => 'The election has been reset',
            'link' => '/candidacy/status',
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $winnerUser->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $bystander->id]);
    }

    public function test_reset_after_voting_records_all_parties_and_deletes_losers(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        DB::table('phases')->where('name', 'voting_open')->update(['is_active' => true]);
        DB::table('election_settings')->insert([
            ['key' => 'voting_opens_at', 'value' => now()->subHours(5)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_closes_at', 'value' => now()->addDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('vote_ledger')->insert([
            ['position_key' => 'president', 'candidate_ref' => 'ref-winner', 'receipt_hmac' => 'hmac-1', 'recorded_at' => now()],
        ]);

        // Winner from Unity party.
        $winner = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-winner',
            'slogan' => 'Vote for me',
            'party_name' => 'Unity',
            'approval_status' => 'approved',
            'certified_winner' => true,
            'certified_at' => now(),
            'winner_rank' => 1,
            'vote_total' => 42,
            'election_status' => 'elected',
        ]);
        // Loser from Opposition party.
        $loser = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-loser',
            'slogan' => 'Also ran',
            'party_name' => 'Opposition',
            'approval_status' => 'approved',
            'certified_winner' => false,
            'vote_total' => 10,
            'election_status' => 'lost',
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        // Winner preserved as officer.
        $this->assertSame(1, DB::table('candidates')->where('id', $winner->id)->value('certified_winner'));
        $this->assertNotNull(DB::table('candidates')->where('id', $winner->id)->value('archived_at'));

        // Loser deleted — clean slate.
        $this->assertFalse(DB::table('candidates')->where('id', $loser->id)->exists());

        // Record kept for review: both parties present in the snapshot.
        $this->assertTrue(DB::table('election_candidate_records')
            ->where('party_name', 'Unity')->where('certified_winner', true)->exists());
        $this->assertTrue(DB::table('election_candidate_records')
            ->where('party_name', 'Opposition')->where('certified_winner', false)->exists());
        $this->assertSame(2, DB::table('election_candidate_records')->count());
    }

    public function test_election_records_endpoint_returns_full_roster(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $term = TermArchive::labelFor(now());
        $cycleId = '2026-10-17 12:00:00';

        DB::table('election_candidate_records')->insert([
            [
                'term_label' => $term,
                'cycle_id' => $cycleId,
                'user_name' => 'Alice Voter',
                'student_id' => '2024-01001',
                'department' => 'CCS',
                'year_level' => '3',
                'position_label' => 'President',
                'position_tier' => 'national',
                'party_name' => 'Unity',
                'election_status' => 'elected',
                'vote_total' => 42,
                'winner_rank' => 1,
                'certified_winner' => true,
                'snapshot_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'term_label' => $term,
                'cycle_id' => $cycleId,
                'user_name' => 'Bob Voter',
                'student_id' => '2024-01002',
                'department' => 'CCS',
                'year_level' => '2',
                'position_label' => 'President',
                'position_tier' => 'national',
                'party_name' => 'Opposition',
                'election_status' => 'lost',
                'vote_total' => 10,
                'winner_rank' => null,
                'certified_winner' => false,
                'snapshot_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->get('/api/admin/election-records')
            ->assertOk()
            ->assertJsonStructure([
                'terms' => [
                    '*' => [
                        'term_label', 'snapshot_at', 'cycle_id', 'total', 'winners',
                        'parties' => [
                            '*' => [
                                'party', 'count',
                                'candidates' => [
                                    '*' => [
                                        'name', 'student_id', 'department',
                                        'year_level', 'position', 'tier', 'result',
                                        'winner_rank', 'vote_total',
                                        'certified_winner', 'slogan',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
                'filters' => ['parties', 'positions', 'results'],
            ]);

        $decoded = json_decode($this->stateful()
            ->get('/api/admin/election-records')
            ->getContent(), true);
        $this->assertSame(1, count($decoded['terms']));
        $this->assertSame($term, $decoded['terms'][0]['term_label']);
        $this->assertSame(2, $decoded['terms'][0]['total']);
        $this->assertSame(1, $decoded['terms'][0]['winners']);
    }

    public function test_archived_winner_kept_off_next_ballot(): void
    {
        // Open the voting window so the /vote phase middleware passes.
        // insertOrIgnore keeps this idempotent across tests: the isolated
        // in-memory DB may already carry a valid window from an earlier test.
        DB::table('election_settings')->insertOrIgnore([
            ['key' => 'voting_opens_at', 'value' => now()->subHour()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_closes_at', 'value' => now()->addDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('positions')->insert([
            'id' => 1, 'slug' => 'president', 'label' => 'President', 'tier' => 'national',
            'seat_count' => 1, 'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $winnerUser = $this->makeStudent();
        $activeUser = $this->makeStudent();

        // Archived winner — still certified, but must not appear on a new ballot.
        // archived_at is set via a direct query update (mirrors TermArchive in
        // production); it is not mass-assignable on the Candidate model.
        $winner = Candidate::create([
            'user_id' => $winnerUser->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-archived',
            'slogan' => 'Past winner',
            'party_name' => 'Unity',
            'approval_status' => 'approved',
            'certified_winner' => true,
            'certified_at' => now(),
            'election_status' => 'elected',
            'vote_total' => 42,
            'winner_rank' => 1,
        ]);
        DB::table('candidates')->where('id', $winner->id)->update(['archived_at' => now()]);
        // Active candidate for the next cycle.
        Candidate::create([
            'user_id' => $activeUser->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-active',
            'slogan' => 'Fresh applicant',
            'party_name' => 'Opposition',
            'approval_status' => 'approved',
            'certified_winner' => false,
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);

        $listed = collect(json_decode($this->get('/api/candidates')->getContent(), true)['data'] ?? []);
        $this->assertFalse($listed->contains('candidate_ref', 'ref-archived'));
        $this->assertTrue($listed->contains('candidate_ref', 'ref-active'));

        // Submitting a vote for the archived winner must be rejected.
        $this->actingAs($winnerUser);
        $this->stateful()
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-archived']])
            ->assertUnprocessable()
            ->assertJsonFragment(["message" => "Invalid or unapproved candidate for position 'president'."]);
    }


    public function test_reset_idempotent_when_election_already_cleared(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        // Running again should still succeed (idempotent).
        $this->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        $this->assertNull(Phase::current());

        // No non-winner candidates remain on a cleared slate, so a repeat
        // reset has nobody to notify — no duplicate applicant notices.
        $this->assertDatabaseCount('user_notifications', 0);
    }

    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret',
            'role' => 'student',
            'is_active' => true,
            'has_voted' => false,
        ], $attributes));
    }

    private function makeStudent(): User
    {
        return User::create([
            'name' => 'Test Student',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret',
            'role' => 'student',
            'is_active' => true,
            'has_voted' => false,
        ]);
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
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('voted_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_recovery_codes')->nullable();
            $table->text('avatar_url')->nullable();
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

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::table('phases')->insert([
            ['name' => 'registration', 'description' => 'Registration', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_open', 'description' => 'Voting Open', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_closed', 'description' => 'Voting Closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'registration_closed', 'description' => 'Registration Closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref')->nullable();
            $table->string('slogan')->nullable();
            $table->string('party_name')->nullable();
            $table->text('platform_statement')->nullable();
            $table->json('platform_points')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('approval_status')->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->unsignedInteger('rejection_count')->default(0);
            $table->timestamp('reapply_blocked_until')->nullable();
            $table->boolean('certified_winner')->default(false);
            $table->timestamp('certified_at')->nullable();
            // Production-faithful: NOT NULL DEFAULT 'pending' (mirrors
            // 2026_09_20_000004). Must stay non-nullable here or a `null`
            // reset slips through SQLite while 500ing on MySQL strict mode.
            $table->string('election_status', 16)->default('pending');
            $table->integer('winner_rank')->nullable();
            $table->integer('vote_total')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->string('term_label')->nullable();
            $table->timestamps();
        });

        // Election Records snapshot table (mirrors the real migration). Kept
        // minimal: only the columns the snapshot writes/reads.
        Schema::create('election_candidate_records', function (Blueprint $table) {
            $table->id();
            $table->string('term_label');
            $table->string('cycle_id');
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('student_id')->nullable();
            $table->string('department')->nullable();
            $table->string('year_level')->nullable();
            $table->string('position_slug')->nullable();
            $table->string('position_label')->nullable();
            $table->string('position_tier')->nullable();
            $table->string('party_name')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('election_status')->nullable();
            $table->integer('vote_total')->default(0);
            $table->integer('winner_rank')->nullable();
            $table->boolean('certified_winner')->default(false);
            $table->text('slogan')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier');
            $table->unsignedInteger('seat_count')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('ballot_drafts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('selections')->nullable();
            $table->string('status', 20)->default('draft');
            $table->string('receipt_token', 64)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('action', 100);
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });

        // Mirrors 2026_09_21_000004_create_notifications_table: the reset
        // notice for cleared applicants lands here.
        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('type', 50)->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
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

        // Turn off 2FA requirement so tests exercise authorization, not enrollment.
        DB::table('election_settings')->insert([
            'key' => 'admin.settings.security.twoFactorRequired',
            'value' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
