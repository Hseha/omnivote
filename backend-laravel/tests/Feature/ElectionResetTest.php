<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Phase;
use App\Models\User;
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

        // NON-WINNER RESET: back to pending, vote cleared.
        $loser->refresh();
        $this->assertSame('pending', $loser->approval_status);
        $this->assertFalse($loser->certified_winner);
        $this->assertSame(0, $loser->vote_total);
        $this->assertNull($loser->winner_rank);

        // Vote ledger truncated.
        $this->assertFalse(DB::table('vote_ledger')->exists());

        // Voter turnout reset.
        $this->assertFalse($voter->fresh()->has_voted);
        $this->assertNull($voter->fresh()->voted_at);

        // Audit row recorded.
        $auditRow = DB::table('audit_logs')->where('action', 'election_reset')->first();
        $this->assertNotNull($auditRow);
        $this->assertSame($admin->id, (int) $auditRow->user_id);
        $this->assertStringContainsString('reset', (string) $auditRow->details);
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
