<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enriched student candidacy endpoint + the committee rejection reason:
 *
 *   GET   /api/candidacy/me — status plus the full application payload
 *         (photo URL, position object, rejection reason, timestamps, seat
 *         competition, and — once polls close — the applicant's own
 *         finalized outcome).
 *   PATCH /api/admin/candidates/{id} — optional `rejection_reason` on
 *         rejection (persisted, surfaced in the applicant notification),
 *         cleared on approval.
 */
class CandidacyMeEnrichedTest extends TestCase
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
        $this->allowNoTwoFactor();
    }

    public function test_me_returns_none_without_an_application(): void
    {
        $this->asStudent()
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('status', 'none');
    }

    public function test_me_returns_the_enriched_application_payload(): void
    {
        $student = $this->makeUser(['name' => 'Ria Cortez']);
        Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'slogan' => 'Serve first',
            'party_name' => 'ASLE',
            'platform_statement' => 'Clean halls, loud voices.',
            'photo_path' => 'candidates/ria.png',
            'approval_status' => 'pending',
        ]);

        $response = $this->actingAs($student, 'sanctum')->getJson('/api/candidacy/me');

        $response->assertOk()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('applicants_count', 1)
            ->assertJsonPath('election_outcome', null)
            ->assertJsonPath('candidate.slogan', 'Serve first')
            ->assertJsonPath('candidate.party_name', 'ASLE')
            ->assertJsonPath('candidate.position.label', 'President')
            ->assertJsonPath('candidate.position.slug', 'president')
            ->assertJsonPath('candidate.position.seat_count', 1)
            ->assertJsonPath('candidate.rejection_reason', null);
    }

    public function test_applicants_count_covers_active_race_only(): void
    {
        $student = $this->makeUser(['name' => 'Runner One']);
        Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('senator'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'pending',
        ]);

        // A second approved applicant for the same office; a rejected one
        // must not inflate the race size.
        Candidate::create([
            'user_id' => $this->makeUser(['name' => 'Runner Two'])->id,
            'position_id' => $this->positionId('senator'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Also running.',
            'approval_status' => 'approved',
        ]);
        Candidate::create([
            'user_id' => $this->makeUser(['name' => 'Runner Out'])->id,
            'position_id' => $this->positionId('senator'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Not running.',
            'approval_status' => 'rejected',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('applicants_count', 2)
            ->assertJsonPath('candidate.position.label', 'Senator');
    }

    public function test_election_outcome_is_null_while_polls_are_open(): void
    {
        $this->openVoting();

        $student = $this->makeUser(['name' => 'Votes Candidate']);
        Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'approved',
            'election_status' => 'elected',
            'winner_rank' => 1,
            'vote_total' => 43,
            'certified_winner' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('election_outcome', null);
    }

    public function test_election_outcome_is_revealed_once_polls_close(): void
    {
        $this->closeVoting();
        $this->publishResults();

        $student = $this->makeUser(['name' => 'Votes Candidate']);
        Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'approved',
            'election_status' => 'elected',
            'winner_rank' => 1,
            'vote_total' => 43,
            'certified_winner' => true,
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('election_outcome.status', 'elected')
            ->assertJsonPath('election_outcome.rank', 1)
            ->assertJsonPath('election_outcome.votes', 43)
            ->assertJsonPath('election_outcome.certified', true);
    }

    public function test_election_outcome_stays_null_between_close_and_finalize(): void
    {
        // Polls closed, results not yet finalized: the candidate row still
        // carries pre-tally defaults, so the app must show the "awaiting
        // official results" state instead of a fake "not elected" verdict.
        $this->closeVoting();

        $student = $this->makeUser(['name' => 'Waiting Candidate']);
        Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'approved',
            'election_status' => 'pending',
        ]);

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('election_outcome', null);
    }

    public function test_rejection_persists_the_reason_and_notifies_the_applicant(): void
    {
        $student = $this->makeUser(['name' => 'Rejected Runner']);
        $candidate = Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'pending',
        ]);

        $this->asAdmin()
            ->patchJson("/api/admin/candidates/{$candidate->id}", [
                'status' => 'rejected',
                'rejection_reason' => 'Missing required eligibility documents.',
            ])
            ->assertOk()
            ->assertJsonPath('candidate.status', 'rejected')
            ->assertJsonPath('candidate.rejection_reason', 'Missing required eligibility documents.');

        $this->assertSame(
            'Missing required eligibility documents.',
            $candidate->fresh()->rejection_reason
        );

        // The applicant can read the reason on their own candidacy screen.
        // (A fresh client: the admin request above left the Origin header on
        // this test instance, which would make the stateful-Sanctum guard
        // treat the bearer request as a session one.)
        $this->actingAs($student, 'sanctum')
            ->withoutHeader('Origin')
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('rejection_reason', 'Missing required eligibility documents.');

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $student->id,
            'type' => 'warning',
            'title' => 'Your candidacy was rejected',
        ]);
        $notification = DB::table('user_notifications')
            ->where('user_id', $student->id)
            ->latest('id')
            ->first();
        $this->assertStringContainsString(
            'Missing required eligibility documents.',
            (string) $notification->body
        );
        $this->assertSame('/candidacy/status', $notification->link);
    }

    public function test_approval_clears_a_stale_rejection_reason(): void
    {
        $student = $this->makeUser(['name' => 'Second Chance']);
        $candidate = Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'rejected',
            'rejection_reason' => 'Old note.',
        ]);

        $this->asAdmin()
            ->patchJson("/api/admin/candidates/{$candidate->id}", [
                'status' => 'approved',
            ])
            ->assertOk()
            ->assertJsonPath('candidate.rejection_reason', null);

        $this->assertNull($candidate->fresh()->rejection_reason);
    }

    public function test_rejection_without_a_reason_still_works(): void
    {
        $student = $this->makeUser(['name' => 'Plain Reject']);
        $candidate = Candidate::create([
            'user_id' => $student->id,
            'position_id' => $this->positionId('president'),
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform.',
            'approval_status' => 'pending',
        ]);

        $this->asAdmin()
            ->patchJson("/api/admin/candidates/{$candidate->id}", [
                'status' => 'rejected',
            ])
            ->assertOk()
            ->assertJsonPath('candidate.status', 'rejected');

        $this->assertNull($candidate->fresh()->rejection_reason);
    }

    private function asStudent(array $attributes = []): static
    {
        return $this->actingAs($this->makeUser($attributes), 'sanctum')
            ->withHeaders(['Origin' => 'http://localhost']);
    }

    private function asAdmin(): static
    {
        return $this->actingAs(
            $this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']),
            'web'
        )->withHeaders(['Origin' => 'http://localhost']);
    }

    private function allowNoTwoFactor(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => 'false', 'updated_at' => now()],
        );
    }

    private function setWindow(array $dates): void
    {
        foreach ($dates as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }

    /** Raises the results_published flag the way Finalize Winners does. */
    private function publishResults(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'results_published'],
            ['value' => '1', 'updated_at' => now()],
        );
    }

    /** Configures a live voting window → derived phase voting_open. */
    private function openVoting(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->subDays(2),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ]);
    }

    /** Configures a finished election window → derived phase voting_closed. */
    private function closeVoting(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(5),
            'registration_closes_at' => now()->subDays(4),
            'voting_opens_at' => now()->subDays(3),
            'voting_closes_at' => now()->subDay(),
        ]);
    }

    private function positionId(string $slug): int
    {
        return (int) DB::table('positions')->where('slug', $slug)->value('id');
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

    /** Minimal schema for the student candidacy + admin review flows. */
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
            $table->string('slogan')->nullable();
            $table->string('party_name')->nullable();
            $table->text('platform_statement')->nullable();
            $table->text('platform_points')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('approval_status')->default('pending');
            $table->string('rejection_reason', 1000)->nullable();
            $table->unsignedInteger('rejection_count')->default(0);
            $table->timestamp('reapply_blocked_until')->nullable();
            $table->boolean('certified_winner')->default(false);
            $table->timestamp('certified_at')->nullable();
            $table->string('election_status', 16)->default('pending');
            $table->unsignedInteger('winner_rank')->nullable();
            $table->unsignedInteger('vote_total')->default(0);
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
            ['name' => 'registration', 'description' => 'Registration phase', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_open', 'description' => 'Voting is open', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_closed', 'description' => 'Voting closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'registration_closed', 'description' => 'Registration is closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type')->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}
