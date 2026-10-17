<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use App\Support\CandidateRoleSync;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Automatic `candidate` role assignment (App\Support\CandidateRoleSync).
 *
 * A `candidate` is a student holding an approved, live candidacy. The role is
 * derived, never assigned by hand, and is kept in step on approval, rejection
 * and withdrawal. Voter-role scoping (User::VOTER_ROLES) must count candidates
 * as voters, and the student route gate must let a candidate through.
 */
class RoleAutoAssignmentTest extends TestCase
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

    public function test_approving_a_candidate_promotes_the_student(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);
        $candidate = $this->makeCandidate($student, 'pending');

        $this->actingAs($admin);

        $this->stateful()
            ->patchJson('/api/admin/candidates/'.$candidate->id, ['status' => 'approved'])
            ->assertOk();

        $this->assertSame('candidate', $student->fresh()->role);
    }

    public function test_rejecting_an_approved_candidate_reverts_to_student(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'candidate']);
        $candidate = $this->makeCandidate($student, 'approved');

        $this->actingAs($admin);

        $this->stateful()
            ->patchJson('/api/admin/candidates/'.$candidate->id, [
                'status' => 'rejected',
                'rejection_reason' => 'Incomplete requirements.',
            ])
            ->assertOk();

        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_withdrawing_a_candidacy_reverts_the_candidate_to_student(): void
    {
        $student = $this->makeUser(['role' => 'candidate', 'must_change_password' => false]);
        $candidate = $this->makeCandidate($student, 'approved');

        // Registration window open so a candidacy may still be forfeited.
        $this->setTimeline(registrationOpen: true);

        // A candidate (not just a student) must clear the student route gate.
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/candidate/withdraw')
            ->assertOk();

        $this->assertSame('withdrawn', $candidate->fresh()->approval_status);
        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_scope_voters_counts_candidates_but_not_staff(): void
    {
        $this->makeUser(['role' => 'student']);
        $this->makeUser(['role' => 'candidate']);
        $this->makeUser(['role' => 'teacher']);
        $this->makeUser(['role' => 'admin']);

        $this->assertSame(2, User::query()->voters()->count());
    }

    public function test_sync_is_idempotent(): void
    {
        $student = $this->makeUser(['role' => 'student']);
        $this->makeCandidate($student, 'approved');

        CandidateRoleSync::sync($student);
        CandidateRoleSync::sync($student);

        $this->assertSame('candidate', $student->fresh()->role);

        // Revoking the candidacy reverts on the next sync.
        $student->candidate()->update(['approval_status' => 'withdrawn']);
        CandidateRoleSync::sync($student->fresh());
        CandidateRoleSync::sync($student->fresh());

        $this->assertSame('student', $student->fresh()->role);
    }

    public function test_sync_never_touches_staff_or_the_ssg_president(): void
    {
        foreach (['admin', 'teacher', 'ssg_president'] as $role) {
            $user = $this->makeUser(['role' => $role]);
            $this->makeCandidate($user, 'approved');

            CandidateRoleSync::sync($user);

            $this->assertSame($role, $user->fresh()->role, "Role {$role} must be preserved.");
        }
    }

    // --- helpers ----------------------------------------------------------

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret',
            'role' => 'student',
            'is_active' => true,
            'must_change_password' => false,
        ], $attributes));
    }

    private function makeCandidate(User $user, string $status): Candidate
    {
        return Candidate::create([
            'user_id' => $user->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-'.Str::uuid(),
            'approval_status' => $status,
        ]);
    }

    private function setTimeline(bool $registrationOpen): void
    {
        $rows = $registrationOpen
            ? [
                'registration_opens_at' => now()->subDay(),
                'registration_closes_at' => now()->addDay(),
                'voting_opens_at' => now()->addDays(2),
                'voting_closes_at' => now()->addDays(3),
            ]
            : [
                'registration_opens_at' => now()->subDays(5),
                'registration_closes_at' => now()->subDays(4),
                'voting_opens_at' => now()->subDays(3),
                'voting_closes_at' => now()->subDay(),
            ];

        foreach ($rows as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
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
            $table->boolean('ssg_setup_pending')->default(false);
            $table->timestamp('voted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
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

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier')->default('school');
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2', 32)->nullable();
            $table->string('scope_value_2')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('positions')->insert([
            'id' => 1,
            'slug' => 'president',
            'label' => 'President',
            'tier' => 'school',
            'scope_type' => 'global',
            'seat_count' => 1,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
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
            $table->string('election_status')->nullable();
            $table->integer('winner_rank')->nullable();
            $table->integer('vote_total')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->string('term_label')->nullable();
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

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type', 20)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        DB::table('election_settings')->insert([
            'key' => 'admin.settings.security.twoFactorRequired',
            'value' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
