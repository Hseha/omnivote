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
 * Candidate rejection + 3-strike lockout (POST /api/candidate/apply reapply).
 *
 *   - Reject increments `rejection_count`.
 *   - 3rd rejection sets `reapply_blocked_until = now + 24h` + notifies student.
 *   - Blocked student gets 429 on reapply with exact time.
 *   - 24h expiry resets counter to 0.
 *   - Both admin and SSG Adviser rejections count.
 *
 * Uses isolated in-memory SQLite.
 */
class AdminCandidateRejectTest extends TestCase
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

    public function test_reject_increments_rejection_count(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $candidate = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-1',
            'approval_status' => 'pending',
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->patchJson('/api/admin/candidates/'.$candidate->id, [
                'status' => 'rejected',
                'rejection_reason' => 'Missing documents.',
            ])
            ->assertOk();

        $candidate->refresh();
        $this->assertSame(1, $candidate->rejection_count);
        $this->assertNull($candidate->reapply_blocked_until);
    }

    public function test_third_rejection_locks_reapply_for_24h(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeStudent();
        $candidate = Candidate::create([
            'user_id' => $student->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-1',
            'approval_status' => 'pending',
            'rejection_count' => 2,
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->patchJson('/api/admin/candidates/'.$candidate->id, [
                'status' => 'rejected',
                'rejection_reason' => 'Final strike.',
            ])
            ->assertOk();

        $candidate->refresh();
        $this->assertSame(3, $candidate->rejection_count);
        
        // The datetime cast should convert the string to Carbon
        $fresh = Candidate::find($candidate->id);
        $until = $fresh->reapply_blocked_until;
        $this->assertNotNull($until, 'until is null');
        $this->assertTrue($until->gte(now()->addHours(23)));
    }

    public function test_blocked_cannot_reapply_until_24h_expires(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeStudent();
        $candidate = Candidate::create([
            'user_id' => $student->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-1',
            'approval_status' => 'rejected',
            'rejection_count' => 3,
            'reapply_blocked_until' => now()->addHours(24),
        ]);

        // Set timeline so phase resolves to 'registration'
        DB::table('election_settings')->insert([
            ['key' => 'registration_opens_at', 'value' => now()->subDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'registration_closes_at', 'value' => now()->addDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_opens_at', 'value' => now()->addDays(2)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_closes_at', 'value' => now()->addDays(3)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Student tries to reapply while blocked.
        $this->actingAs($student);

        $this->stateful()
            ->postJson('/api/candidate/apply', [
                'position_id' => 1,
                'platform_statement' => 'I want to run.',
                'certify' => true,
            ])
            ->assertStatus(429)
            ->assertJsonPath('code', 'reapply_blocked');

        $candidate->refresh();
        $this->assertSame('rejected', $candidate->approval_status);
    }

    public function test_lockout_expires_after_24h_resets_counter(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeStudent();
        $candidate = Candidate::create([
            'user_id' => $student->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-1',
            'approval_status' => 'rejected',
            'rejection_count' => 3,
            'reapply_blocked_until' => now()->subHour(), // expired
        ]);

        // Set timeline so phase resolves to 'registration'
        DB::table('election_settings')->insert([
            ['key' => 'registration_opens_at', 'value' => now()->subDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'registration_closes_at', 'value' => now()->addDay()->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_opens_at', 'value' => now()->addDays(2)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'voting_closes_at', 'value' => now()->addDays(3)->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Student re-applies after expiry -> accepted, counter reset to 0.
        $this->actingAs($student);

        $this->stateful()
            ->postJson('/api/candidate/apply', [
                'position_id' => 1,
                'platform_statement' => 'New platform.',
                'certify' => true,
            ])
            ->assertCreated();

        $candidate->refresh();
        $this->assertSame('pending', $candidate->approval_status);
        $this->assertSame(0, $candidate->rejection_count);
        $this->assertNull($candidate->reapply_blocked_until);
    }

    public function test_teacher_can_reject_and_counts_toward_lockout(): void
    {
        $teacher = $this->makeUser(['role' => 'teacher']);
        $candidate = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-1',
            'approval_status' => 'pending',
            'rejection_count' => 2,
        ]);

        $this->actingAs($teacher);

        $this->stateful()
            ->patchJson('/api/admin/candidates/'.$candidate->id, [
                'status' => 'rejected',
                'rejection_reason' => 'Teacher reject.',
            ])
            ->assertOk();

        $candidate->refresh();
        $this->assertSame(3, $candidate->rejection_count);
        $this->assertNotNull($candidate->reapply_blocked_until);
    }

    public function test_approving_rejected_clears_rejection_tracking(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $candidate = Candidate::create([
            'user_id' => $this->makeStudent()->id,
            'position_id' => 1,
            'candidate_ref' => 'ref-1',
            'approval_status' => 'rejected',
            'rejection_count' => 3,
            'reapply_blocked_until' => now()->addHours(24),
            'rejection_reason' => 'Old note.',
        ]);

        $this->actingAs($admin);

        $this->stateful()
            ->patchJson('/api/admin/candidates/'.$candidate->id, [
                'status' => 'approved',
            ])
            ->assertOk();

        $candidate->refresh();
        $this->assertSame('approved', $candidate->approval_status);
        $this->assertSame(0, $candidate->rejection_count);
        $this->assertNull($candidate->reapply_blocked_until);
        $this->assertNull($candidate->rejection_reason);
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret',
            'role' => 'student',
            'is_active' => true,
        ], $attributes));
    }

    private function makeStudent(): User
    {
        return $this->makeUser(['role' => 'student']);
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

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier');
            $table->integer('seat_count')->default(1);
            $table->integer('sort_order')->default(10);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        DB::table('positions')->insert([
            ['id' => 1, 'slug' => 'president', 'label' => 'President', 'tier' => 'national', 'seat_count' => 1, 'sort_order' => 10],
        ]);

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
