<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\SsgPresidentInvite;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Publishing results and the automatic SSG President handover.
 *
 *   POST /api/admin/results/finalize
 *
 * Finalizing raises the `results_published` flag (which unblocks the SSG seat)
 * and automatically seats the elected winner of the configured Presidency
 * seat. A recount is idempotent, a tie leaves the seat for a manual grant, and
 * candidates the tally decided against revert to plain students.
 */
class ResultsPublicationTest extends TestCase
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
        Mail::fake();
    }

    public function test_finalize_publishes_results_and_seats_the_elected_president(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $candidate = $this->makeCandidate($winner, 'ref-president', 'approved');
        $this->castVotes('president', 'ref-president', 3);
        $this->closeVoting();

        $this->actingAs($admin)
            ->postJson('/api/admin/results/finalize')
            ->assertOk();

        // Results are now published.
        $this->assertSame('1', (string) DB::table('election_settings')->where('key', 'results_published')->value('value'));

        // The elected candidate is certified...
        $candidate->refresh();
        $this->assertTrue((bool) $candidate->certified_winner);
        $this->assertSame('elected', $candidate->election_status);

        // ...and automatically handed the single SSG seat with a setup invite.
        $winner->refresh();
        $this->assertSame('ssg_president', $winner->role);
        $this->assertTrue((bool) $winner->ssg_setup_pending);
        $this->assertSame(1, SsgPresidentInvite::query()->where('user_id', $winner->id)->whereNull('used_at')->count());
        $this->assertNotNull(
            UserNotification::query()
                ->where('user_id', $winner->id)
                ->where('link', 'like', '/ssg/setup?token=%')
                ->first()
        );
    }

    public function test_finalize_leaves_the_seat_empty_when_the_race_is_tied(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $alice = $this->makeUser(['role' => 'student']);
        $bob = $this->makeUser(['role' => 'student']);
        $this->makeCandidate($alice, 'ref-alice', 'approved');
        $this->makeCandidate($bob, 'ref-bob', 'approved');
        $this->castVotes('president', 'ref-alice', 2);
        $this->castVotes('president', 'ref-bob', 2);
        $this->closeVoting();

        $this->actingAs($admin)->postJson('/api/admin/results/finalize')->assertOk();

        // Published — but a tie is not a winner, so nobody is seated.
        $this->assertSame('1', (string) DB::table('election_settings')->where('key', 'results_published')->value('value'));
        $this->assertSame(0, User::query()->where('role', 'ssg_president')->count());
        $this->assertSame(0, SsgPresidentInvite::query()->count());

        // Tied candidates keep the `candidate` role (their race is undecided).
        $this->assertSame('candidate', $alice->fresh()->role);
        $this->assertSame('candidate', $bob->fresh()->role);
    }

    public function test_refinalize_does_not_reissue_the_presidents_invite(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);
        $this->makeCandidate($winner, 'ref-president', 'approved');
        $this->castVotes('president', 'ref-president', 5);
        $this->closeVoting();

        $this->actingAs($admin)->postJson('/api/admin/results/finalize')->assertOk();
        $this->assertSame(1, SsgPresidentInvite::query()->where('user_id', $winner->id)->count());

        // A recount (re-finalize) must not log the seated President out or mint
        // a second setup link.
        $this->actingAs($admin)->postJson('/api/admin/results/finalize')->assertOk();

        $this->assertSame('ssg_president', $winner->fresh()->role);
        $this->assertSame(1, SsgPresidentInvite::query()->where('user_id', $winner->id)->count());
    }

    public function test_finalize_reverts_non_winner_candidates_to_student(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $winner = $this->makeUser(['role' => 'candidate']);
        $loser = $this->makeUser(['role' => 'candidate']);
        $this->makeCandidate($winner, 'ref-w', 'approved');
        $this->makeCandidate($loser, 'ref-l', 'approved');
        $this->castVotes('president', 'ref-w', 4);
        $this->castVotes('president', 'ref-l', 1);
        $this->closeVoting();

        $this->actingAs($admin)->postJson('/api/admin/results/finalize')->assertOk();

        // The winner is promoted out of the voter roles to the SSG seat; the
        // candidate the tally decided against drops back to student.
        $this->assertSame('ssg_president', $winner->fresh()->role);
        $this->assertSame('student', $loser->fresh()->role);
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
        ], $attributes));
    }

    private function makeCandidate(User $user, string $ref, string $status): Candidate
    {
        return Candidate::create([
            'user_id' => $user->id,
            'position_id' => 1,
            'candidate_ref' => $ref,
            'approval_status' => $status,
        ]);
    }

    private function castVotes(string $positionKey, string $ref, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('vote_ledger')->insert([
                'position_key' => $positionKey,
                'candidate_ref' => $ref,
                'receipt_hmac' => Str::uuid()->toString(),
                'recorded_at' => now(),
            ]);
        }
    }

    private function closeVoting(): void
    {
        $timeline = [
            'registration_opens_at' => now()->subDays(5),
            'registration_closes_at' => now()->subDays(4),
            'voting_opens_at' => now()->subDays(3),
            'voting_closes_at' => now()->subDay(),
        ];

        foreach ($timeline as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value->toIso8601String(), 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->nullable()->unique();
            $table->string('name');
            $table->string('username')->nullable()->unique();
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
            $table->string('party_name')->nullable();
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
            $table->timestamp('recorded_at')->useCurrent();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->string('type', 50)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ssg_president_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
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

        DB::table('election_settings')->insert([
            'key' => 'admin.settings.security.twoFactorRequired',
            'value' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
