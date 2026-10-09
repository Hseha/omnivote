<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Admin user archiving (soft-archive):
 *
 *   POST /api/admin/users/{user}/archive    — hide from counts/listing, block
 *        sign-in via is_active, revoke tokens
 *   POST /api/admin/users/{user}/unarchive  — restore the account
 *
 * Guardrails: cannot archive yourself, the final active administrator, or a
 * user with a pending/approved candidacy / certified-winner record.
 */
class AdminUserArchiveTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

    private const PASSWORD = 'secret-password';

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

    public function test_archived_user_is_hidden_from_counts_and_listing(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$student->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.is_active', false)
            ->assertJsonPath('data.archived', true);

        $response = $this->actingAs($admin)->getJson('/api/admin/users');

        // Total counts drop the archived account; a dedicated counter reports it.
        $response->assertJsonPath('stats.total', 1)
            ->assertJsonPath('stats.archived', 1)
            ->assertJsonPath('stats.active', 1)
            ->assertJsonPath('stats.inactive', 0)
            // Not in the default listing…
            ->assertJsonMissing(['id' => $student->id]);

        // …but reachable through the explicit archived filter.
        $this->actingAs($admin)
            ->getJson('/api/admin/users?archived=true')
            ->assertOk()
            ->assertJsonPath('data.0.id', $student->id)
            ->assertJsonPath('data.0.archived', true);
    }

    public function test_archived_account_is_blocked_from_sign_in_and_sessions(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);
        $token = $student->createToken('mobile')->plainTextToken;

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$student->id}/archive")
            ->assertOk();

        // Revoked token can no longer reach /me…
        $this->app['auth']->forgetGuards();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        // …and a fresh login attempt is refused by the is_active gate.
        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertStatus(403)
            ->assertExactJson(['message' => 'Your account has been disabled.']);
    }

    public function test_unarchive_restores_the_account(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$student->id}/archive")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$student->id}/unarchive")
            ->assertOk()
            ->assertJsonPath('data.archived', false)
            ->assertJsonPath('data.is_active', true);

        $this->actingAs($admin)
            ->getJson('/api/admin/users')
            ->assertJsonPath('stats.total', 2)
            ->assertJsonPath('stats.archived', 0);

        // The restored account can sign in again.
        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => self::PASSWORD])
            ->assertOk()
            ->assertJsonPath('student.id', $student->id);
    }

    public function test_cannot_archive_your_own_account(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$admin->id}/archive")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot archive your own account.');
    }

    public function test_cannot_archive_the_final_active_administrator(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $disabledAdmin = $this->makeUser(['role' => 'admin', 'is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$disabledAdmin->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Cannot archive the final active administrator.');
    }

    public function test_cannot_archive_a_user_with_an_active_candidacy(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $candidate = $this->makeUser(['role' => 'student']);

        $this->makeCandidate($candidate->id, 'pending');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$candidate->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This user has a pending/approved candidacy or a certified-winner record. Resolve it before archiving the account.');

        // Once the candidacy is withdrawn the account can be archived.
        DB::table('candidates')->where('user_id', $candidate->id)->update(['approval_status' => 'withdrawn']);
        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$candidate->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived', true);
    }

    public function test_cannot_archive_a_certified_winner(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);

        $this->makeCandidate($winner->id, 'approved', true);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$winner->id}/archive")
            ->assertStatus(409);
    }

    public function test_archive_is_idempotent_and_rejects_already_archived(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($admin)->postJson("/api/admin/users/{$student->id}/archive")->assertOk();
        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$student->id}/archive")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This account is already archived.');
    }

    // --- helpers ----------------------------------------------------------

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => self::PASSWORD,
            'role' => 'student',
            'is_active' => true,
        ], $attributes));
    }

    private function makeCandidate(int $userId, string $status, bool $certified = false): void
    {
        DB::table('candidates')->insert([
            'user_id' => $userId,
            'position_id' => 1,
            'candidate_ref' => (string) Str::uuid(),
            'platform_statement' => 'Platform',
            'approval_status' => $status,
            'certified_winner' => $certified,
            'election_status' => 'pending',
            'vote_total' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Minimal schema for the models exercised by these flows. */
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
            $table->timestamp('archived_at')->nullable();
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

        // AdminUserController::index() aggregates departments from the registrar
        // import plus the College → Course cascade, so the listing needs these
        // tables present.
        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->string('grade_level');
            $table->string('department')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }
}