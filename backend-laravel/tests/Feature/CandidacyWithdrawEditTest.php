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
 * Student self-service candidacy changes behind the new routes:
 *
 *   PUT  /api/candidate/apply     — edit the campaign (pending OR approved;
 *        position locked once approved); allowed until polls close
 *   POST /api/candidate/withdraw  — forfeit (pending OR approved); allowed
 *        until polls close
 *
 * Re-applying after a withdrawal is allowed during the registration phase
 * (the apply guard only blocks pending/approved).
 */
class CandidacyWithdrawEditTest extends TestCase
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

    public function test_pending_application_can_edit_campaign_during_registration(): void
    {
        $this->openRegistration();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'pending');
        $senatorId = DB::table('positions')->where('slug', 'senator')->value('id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/candidate/apply', [
                'position_id' => $senatorId,
                'slogan' => 'Stronger together',
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertOk()
            ->assertJsonPath('candidate.slogan', 'Stronger together')
            ->assertJsonPath('candidate.party_name', 'ASLE')
            ->assertJsonPath('candidate.platform_points', ['P1', 'P2', 'P3'])
            // Pending applications may still change office.
            ->assertJsonPath('candidate.position_id', $senatorId);
    }

    public function test_approved_application_keeps_its_position_when_editing(): void
    {
        $this->openRegistration();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'approved');
        $presidentId = DB::table('positions')->where('slug', 'president')->value('id');
        $senatorId = DB::table('positions')->where('slug', 'senator')->value('id');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/candidate/apply', [
                'position_id' => $senatorId,
                'slogan' => 'Updated slogan',
                'platform_statement' => 'New platform',
                'certify' => true,
            ])
            ->assertOk()
            // Position is locked once approved; the slogan still updates.
            ->assertJsonPath('candidate.position_id', $presidentId)
            ->assertJsonPath('candidate.slogan', 'Updated slogan');
    }

    public function test_candidacy_changes_are_blocked_after_polls_close(): void
    {
        $this->closeVoting();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'approved');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/candidate/apply', [
                'position_id' => DB::table('positions')->where('slug', 'president')->value('id'),
                'platform_statement' => 'Too late',
                'certify' => true,
            ])
            ->assertStatus(403);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/candidate/withdraw')
            ->assertStatus(403);
    }

    public function test_pending_candidate_can_withdraw_during_registration(): void
    {
        $this->openRegistration();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'pending');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/candidate/withdraw')
            ->assertOk()
            ->assertJsonPath('candidate.approval_status', 'withdrawn');

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/candidacy/me')
            ->assertOk()
            ->assertJsonPath('status', 'withdrawn');
    }

    public function test_approved_candidate_can_withdraw_during_voting(): void
    {
        $this->openVoting();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'approved');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/candidate/withdraw')
            ->assertOk()
            ->assertJsonPath('candidate.approval_status', 'withdrawn');
    }

    public function test_withdrawn_or_rejected_application_cannot_be_withdrawn_again(): void
    {
        $this->openRegistration();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'withdrawn');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/candidate/withdraw')
            ->assertStatus(409);
    }

    public function test_withdrawn_applicant_can_reapply_during_registration(): void
    {
        $this->openRegistration();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'withdrawn');
        $senatorId = DB::table('positions')->where('slug', 'senator')->value('id');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $senatorId,
                'slogan' => 'Second attempt',
                'platform_statement' => 'Fresh platform',
                'certify' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('candidate.approval_status', 'pending');

        $this->assertSame(
            'pending',
            Candidate::where('user_id', $user->id)->latest('id')->value('approval_status'),
        );
    }

    public function test_cannot_edit_or_withdraw_someone_elses_application(): void
    {
        $this->openRegistration();

        $owner = $this->makeUser(['name' => 'Owner']);
        $this->makeCandidate($owner, 'president', 'pending');

        $intruder = $this->makeUser(['name' => 'Intruder']);

        $this->actingAs($intruder, 'sanctum')
            ->putJson('/api/candidate/apply', [
                'position_id' => DB::table('positions')->where('slug', 'president')->value('id'),
                'platform_statement' => 'Hijack',
                'certify' => true,
            ])
            ->assertStatus(404);

        $this->actingAs($intruder, 'sanctum')
            ->postJson('/api/candidate/withdraw')
            ->assertStatus(404);

        $this->assertSame(
            'pending',
            Candidate::where('user_id', $owner->id)->value('approval_status'),
        );
    }

    public function test_edit_requires_the_certify_acknowledgement(): void
    {
        $this->openRegistration();

        $user = $this->makeUser();
        $this->makeCandidate($user, 'president', 'pending');

        $this->actingAs($user, 'sanctum')
            ->putJson('/api/candidate/apply', [
                'position_id' => DB::table('positions')->where('slug', 'president')->value('id'),
                'platform_statement' => 'No certify flag',
            ])
            ->assertStatus(422);
    }

    // --- helpers ----------------------------------------------------------

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

    private function makeCandidate(User $user, string $positionSlug, string $status): Candidate
    {
        return Candidate::create([
            'user_id' => $user->id,
            'position_id' => DB::table('positions')->where('slug', $positionSlug)->value('id'),
            'candidate_ref' => (string) Str::uuid(),
            'slogan' => 'Original slogan',
            'party_name' => 'ASLE',
            'platform_statement' => 'Original platform',
            'platform_points' => json_encode(['Original platform']),
            'approval_status' => $status,
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);
    }

    private function setWindow(array $window): void
    {
        foreach ($window as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }

    private function openRegistration(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDays(2),
            'voting_opens_at' => now()->addDays(3),
            'voting_closes_at' => now()->addDays(4),
        ]);
    }

    private function openVoting(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->subDays(2),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ]);
    }

    private function closeVoting(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(5),
            'registration_closes_at' => now()->subDays(4),
            'voting_opens_at' => now()->subDays(3),
            'voting_closes_at' => now()->subHour(),
        ]);
    }

    /** Minimal schema for the candidacy flows exercised here. */
    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->nullable()->unique();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('role')->default('student');
            $table->string('year_level')->nullable();
            $table->string('department')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
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
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        foreach ([['president', 'President', 1], ['senator', 'Senator', 12]] as [$slug, $label, $seats]) {
            DB::table('positions')->insert([
                'slug' => $slug,
                'label' => $label,
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
            $table->string('slogan', 255)->nullable();
            $table->string('party_name', 255)->nullable();
            $table->longText('platform_statement');
            $table->json('platform_points')->nullable();
            $table->string('photo_path')->nullable();
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

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        foreach (['registration', 'voting_open', 'voting_closed', 'registration_closed'] as $phase) {
            DB::table('phases')->insert([
                'name' => $phase,
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

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->json('value')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50)->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}