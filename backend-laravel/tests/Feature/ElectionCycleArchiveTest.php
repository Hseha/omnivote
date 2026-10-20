<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Phase;
use App\Models\User;
use App\Support\ElectionCycleArchive;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * The per-cycle election archive.
 *
 * A cycle is captured the moment the phase transitions to `voting_closed`:
 * the candidate roster, the ballot as it was, and the turnout. The tally only
 * exists once an administrator declares the winners, so the finalization
 * folds it back into the same cycle.
 *
 * A clean-slate reset before the polls close records the cycle as ABORTED
 * instead, and clears the voting schedule along with the cycle id.
 */
class ElectionCycleArchiveTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

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
        $this->disableTwoFactorRequirement();
    }

    public function test_the_voting_closed_transition_captures_the_cycle(): void
    {
        $this->closeTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');
        $this->makeCandidate('ref-ben', 'Ben', 'SVEA', 'president');

        // Reading the phase performs the reconciliation — and the transition
        // into voting_closed is what captures the cycle.
        $this->assertSame('voting_closed', Phase::current()?->name);

        $this->assertSame(1, DB::table('election_cycles')->count());

        $cycle = DB::table('election_cycles')->first();
        $this->assertSame('voting_closed', $cycle->status);
        $this->assertSame(2, (int) $cycle->candidates_total);

        // The schedule the cycle actually ran under.
        $this->assertNotNull($cycle->voting_opens_at);
        $this->assertNotNull($cycle->voting_closes_at);

        // The roster, one row per candidate.
        $this->assertSame(2, DB::table('election_candidate_records')->count());
        $this->assertSame(2, DB::table('election_candidate_records')
            ->whereIn('party_name', ['ASLE', 'SVEA'])
            ->count());

        // The ballot, including the seats nobody contested.
        $this->assertSame(2, DB::table('election_cycle_positions')->count());
    }

    public function test_the_capture_is_idempotent(): void
    {
        $this->closeTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        Phase::current();
        $cycleId = DB::table('election_cycles')->value('cycle_id');

        // The phase is already correct, so a second read is a no-op; and the
        // archive is idempotent per cycle even when it runs again directly.
        Phase::current();
        ElectionCycleArchive::snapshotClosedCycle();

        $this->assertSame(1, DB::table('election_cycles')->count());
        $this->assertSame(1, DB::table('election_candidate_records')->count());
        $this->assertSame($cycleId, DB::table('election_cycles')->value('cycle_id'));
    }

    public function test_finalizing_folds_the_tally_into_the_captured_cycle(): void
    {
        $this->closeTheWindow();
        $candidate = $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        Phase::current();

        // The tally only exists once the results are declared.
        Candidate::query()->where('id', $candidate->id)->update([
            'election_status' => 'elected',
            'winner_rank' => 1,
            'vote_total' => 1234,
            'certified_winner' => true,
        ]);

        ElectionCycleArchive::finalizeCycle();

        $record = DB::table('election_candidate_records')->first();
        $this->assertSame(1234, (int) $record->vote_total);
        $this->assertSame(1, (int) $record->winner_rank);
        $this->assertSame('elected', $record->election_status);
        $this->assertTrue((bool) $record->certified_winner);

        $cycle = DB::table('election_cycles')->first();
        $this->assertSame('finalized', $cycle->status);
        $this->assertSame(1, (int) $cycle->seats_filled);
        $this->assertNotNull($cycle->finalized_at);
    }

    public function test_finalizing_without_a_capture_still_records_the_cycle(): void
    {
        $this->closeTheWindow();
        $candidate = $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        Candidate::query()->where('id', $candidate->id)->update([
            'election_status' => 'elected',
            'winner_rank' => 1,
            'vote_total' => 99,
            'certified_winner' => true,
        ]);

        // No close transition happened — the finalization must still leave a
        // record of the outcome.
        ElectionCycleArchive::finalizeCycle();

        $this->assertSame(1, DB::table('election_cycles')->count());
        $this->assertSame('finalized', DB::table('election_cycles')->value('status'));
        $this->assertSame(1, DB::table('election_candidate_records')->count());
        $this->assertSame(99, (int) DB::table('election_candidate_records')->value('vote_total'));
    }

    public function test_the_reset_clears_the_whole_voting_schedule(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->closeTheWindow();

        // The form's own copy of the schedule, which the reset used to leave
        // behind — so the Voting Windows screen still showed the old dates.
        foreach ([
            'admin.settings.voting.registrationStart',
            'admin.settings.voting.registrationEnd',
            'admin.settings.voting.votingStart',
            'admin.settings.voting.votingEnd',
            'admin.settings.voting.termEndsAt',
        ] as $key) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => now()->addDay()->toIso8601String(), 'updated_at' => now()],
            );
        }

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        // The canonical timeline.
        foreach (['registration_opens_at', 'registration_closes_at', 'voting_opens_at', 'voting_closes_at'] as $key) {
            $this->assertNull(DB::table('election_settings')->where('key', $key)->value('value'));
        }

        // The form's copy of the schedule.
        foreach ([
            'admin.settings.voting.registrationStart',
            'admin.settings.voting.registrationEnd',
            'admin.settings.voting.votingStart',
            'admin.settings.voting.votingEnd',
            'admin.settings.voting.termEndsAt',
        ] as $key) {
            $this->assertNull(DB::table('election_settings')->where('key', $key)->value('value'));
        }

        // The cycle id, so the next election starts a fresh cycle.
        $this->assertNull(ElectionCycleArchive::currentCycleId());
    }

    public function test_the_reset_records_the_cycle_it_aborted(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->openTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        $cycle = DB::table('election_cycles')->first();
        $this->assertSame('aborted', $cycle->status);
        $this->assertNotNull($cycle->aborted_at);

        // The schedule the aborted cycle ran under is kept on the record.
        $this->assertNotNull($cycle->voting_opens_at);
        $this->assertNotNull($cycle->voting_closes_at);

        // And the roster is on record, even though the candidates are gone.
        $this->assertSame(1, DB::table('election_candidate_records')->count());
        $this->assertSame(0, Candidate::query()->count());
    }

    public function test_a_reset_before_the_polls_close_freezes_the_ballot_too(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->openTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        // The roster is on record, even though the candidates are gone.
        $this->assertSame(1, DB::table('election_candidate_records')->count());

        // And so is the ballot as it was — the positions, their seat counts
        // and their scopes. A cycle reset mid-registration used to keep the
        // roster but lose this, so the review was half a record.
        $this->assertSame(2, DB::table('election_cycle_positions')->count());
    }

    public function test_a_reset_that_cannot_archive_the_roster_is_refused(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->openTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        // The archive cannot run at all — the table it writes to is gone.
        Schema::rename('election_candidate_records', 'election_candidate_records_broken');

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertStatus(409);

        // The slate survives: the reset is about to delete the very rows the
        // archive failed to record, so it refuses instead of losing them.
        $this->assertSame(1, Candidate::query()->count());
    }

    public function test_a_reset_that_cannot_take_the_capture_lock_is_refused(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->openTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        // A lock that cannot be taken means the capture did not happen —
        // exactly as fatal as a failed write, and just as capable of losing
        // the history the reset is about to delete.
        Cache::shouldReceive('lock')->andThrow(new \RuntimeException('no cache'));

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertStatus(409);

        $this->assertSame(1, Candidate::query()->count());
    }

    public function test_a_cycle_that_had_already_closed_keeps_that_status_on_reset(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->closeTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/election/reset', ['confirm' => 'reset_election'])
            ->assertOk();

        // The polls had closed: the cycle is recorded as closed-but-never-
        // finalized, not as aborted. The reset does not rewrite that.
        $this->assertSame('voting_closed', DB::table('election_cycles')->value('status'));
    }

    public function test_clearing_the_window_leaves_the_candidates_and_votes_alone(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->closeTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        $this->actingAs($admin)
            ->stateful()
            ->patchJson('/api/admin/settings/voting', [
                'registrationStart' => '',
                'registrationEnd' => '',
                'votingStart' => '',
                'votingEnd' => '',
            ])
            ->assertOk();

        // The schedule is gone...
        $this->assertNull(DB::table('election_settings')->where('key', 'voting_opens_at')->value('value'));
        $this->assertNull(DB::table('election_settings')->where('key', 'voting_closes_at')->value('value'));

        // ...and so is the cycle id.
        $this->assertNull(ElectionCycleArchive::currentCycleId());

        // But clearing a window is a scheduling action, not a data action:
        // the candidates and the votes are untouched.
        $this->assertSame(1, Candidate::query()->count());
        $this->assertSame(1, DB::table('vote_ledger')->count());
    }

    public function test_the_capture_records_turnout_as_aggregate_counts(): void
    {
        $this->closeTheWindow();
        $this->makeCandidate('ref-ana', 'Ana', 'ASLE', 'president');

        // Two more eligible voters, one of whom voted.
        $this->makeUser(['role' => 'student', 'has_voted' => true]);
        $this->makeUser(['role' => 'student', 'has_voted' => false]);

        Phase::current();

        $cycle = DB::table('election_cycles')->first();
        $this->assertSame(3, (int) $cycle->registered_students);
        $this->assertSame(1, (int) $cycle->voters_who_voted);
        $this->assertSame(1, (int) $cycle->votes_cast);

        // The archive holds one row per candidate and nothing per voter: the
        // vote ledger has no user foreign key on purpose, and a per-voter
        // turnout list would rebuild exactly the link that removes.
        $this->assertSame(1, DB::table('election_candidate_records')->count());
    }

    /* ------------------------------------------------------------------ */

    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }

    /**
     * A voting window whose deadline has already passed, so the phase
     * derives to `voting_closed`.
     */
    private function closeTheWindow(): void
    {
        foreach ([
            'registration_opens_at' => now()->subDays(10),
            'registration_closes_at' => now()->subDays(5),
            'voting_opens_at' => now()->subDays(2),
            'voting_closes_at' => now()->subHour(),
        ] as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value->toIso8601String(), 'updated_at' => now()],
            );
        }
    }

    /**
     * A voting window that is still open — the cycle never got to close.
     */
    private function openTheWindow(): void
    {
        foreach ([
            'registration_opens_at' => now()->subDays(2),
            'registration_closes_at' => now()->addDays(2),
            'voting_opens_at' => now()->addDay(),
            'voting_closes_at' => now()->addDays(3),
        ] as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value->toIso8601String(), 'updated_at' => now()],
            );
        }
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

    private function makeCandidate(string $ref, string $name, string $party, string $positionSlug): Candidate
    {
        return Candidate::create([
            'user_id' => $this->makeUser(['name' => $name, 'role' => 'candidate'])->id,
            'position_id' => (int) DB::table('positions')->where('slug', $positionSlug)->value('id'),
            'candidate_ref' => $ref,
            'party_name' => $party,
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
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
            $table->string('course')->nullable();
            $table->unsignedInteger('failed_login_attempts')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
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

        DB::table('positions')->insert([
            ['slug' => 'president', 'label' => 'President', 'tier' => 'national', 'seat_count' => 1, 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'senator', 'label' => 'Senator', 'tier' => 'national', 'seat_count' => 12, 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);

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
            $table->string('election_status', 16)->default('pending');
            $table->integer('winner_rank')->nullable();
            $table->integer('vote_total')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->string('term_label')->nullable();
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

        DB::table('vote_ledger')->insert([
            'position_key' => 'president',
            'candidate_ref' => 'ref-ana',
            'receipt_hmac' => 'hash-1',
            'recorded_at' => now(),
        ]);

        // Election Records snapshot table (mirrors the real migration).
        Schema::create('election_candidate_records', function (Blueprint $table) {
            $table->id();
            $table->string('term_label');
            $table->string('cycle_id');
            $table->string('candidate_ref')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('student_id')->nullable();
            $table->string('department')->nullable();
            $table->string('year_level')->nullable();
            $table->string('position_slug')->nullable();
            $table->string('position_label')->nullable();
            $table->string('position_tier')->nullable();
            $table->unsignedTinyInteger('position_sort_order')->nullable();
            $table->string('party_name')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('election_status')->nullable();
            $table->integer('vote_total')->default(0);
            $table->integer('winner_rank')->nullable();
            $table->boolean('certified_winner')->default(false);
            $table->text('slogan')->nullable();
            $table->timestamp('snapshot_at')->nullable();
        });

        // The cycle header and the ballot as it was.
        Schema::create('election_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_id')->unique();
            $table->string('term_label')->nullable();
            $table->string('status', 32)->default('voting_closed');
            $table->string('election_title')->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->timestamp('voting_opens_at')->nullable();
            $table->timestamp('voting_closes_at')->nullable();
            $table->timestamp('term_ends_at')->nullable();
            $table->boolean('results_published')->default(false);
            $table->unsignedInteger('registered_students')->nullable();
            $table->unsignedInteger('voters_who_voted')->nullable();
            $table->unsignedInteger('votes_cast')->nullable();
            $table->unsignedInteger('candidates_total')->nullable();
            $table->unsignedInteger('seats_filled')->nullable();
            $table->timestamp('snapshot_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('election_cycle_positions', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_id');
            $table->string('position_slug')->nullable();
            $table->string('position_label')->nullable();
            $table->string('position_tier')->nullable();
            $table->unsignedTinyInteger('seat_count')->nullable();
            $table->unsignedTinyInteger('sort_order')->nullable();
            $table->string('scope_type', 32)->nullable();
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2', 32)->nullable();
            $table->string('scope_value_2')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('snapshot_at')->useCurrent();
        });
    }
}
