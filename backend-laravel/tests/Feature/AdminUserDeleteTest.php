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
 * Permanent deletion of archived admin user accounts:
 *
 *   DELETE /api/admin/users/{user} — irreversibly remove an account (and every
 *        record that cascades from it) that has already been archived.
 *
 * Guardrails mirror archive() as defence in depth: you cannot delete yourself,
 * a non-archived account, the final active administrator, a user with a live
 * candidacy / certified-winner record, or the seated SSG President.
 */
class AdminUserDeleteTest extends TestCase
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

    public function test_an_archived_account_is_permanently_deleted(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);
        $student->createToken('mobile');

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$student->id}/archive")
            ->assertOk();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$student->id}")
            ->assertOk()
            ->assertJsonPath('message', 'Account permanently deleted.');

        $this->assertNull(User::find($student->id));

        // Sanctum tokens are not foreign-keyed to the user, so they must be
        // cleared explicitly or they would outlive the account.
        $this->assertSame(0, DB::table('personal_access_tokens')->where('tokenable_id', $student->id)->count());
    }

    public function test_cannot_delete_your_own_account(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$admin->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'You cannot delete your own account.');
    }

    public function test_cannot_delete_an_account_that_is_not_archived(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$student->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Archive the account before deleting it permanently.');

        $this->assertNotNull(User::find($student->id));
    }

    public function test_archived_administrator_can_be_deleted_while_another_admin_remains(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $other = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson("/api/admin/users/{$other->id}/archive")
            ->assertOk();

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$other->id}")
            ->assertOk();

        $this->assertNull(User::find($other->id));
    }

    public function test_cannot_delete_an_archived_user_with_a_live_candidacy(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $candidate = $this->makeUser(['role' => 'student']);

        $this->makeCandidate($candidate->id, 'pending');
        $this->archiveInDatabase($candidate);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$candidate->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This user has a pending/approved candidacy or a certified-winner record. Resolve it before deleting the account.');

        $this->assertNotNull(User::find($candidate->id));
    }

    public function test_cannot_delete_an_archived_certified_winner(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $winner = $this->makeUser(['role' => 'student']);

        $this->makeCandidate($winner->id, 'approved', true);
        $this->archiveInDatabase($winner);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$winner->id}")
            ->assertStatus(409);
    }

    public function test_cannot_delete_the_seated_ssg_president(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $president = $this->makeUser(['role' => 'ssg_president']);

        // A hand-edited row can be archived without the candidacy guard firing;
        // the delete must still refuse to remove the seat holder.
        $this->archiveInDatabase($president);

        $this->actingAs($admin)
            ->deleteJson("/api/admin/users/{$president->id}")
            ->assertStatus(409)
            ->assertJsonPath('message', 'Unseat the SSG President before deleting this account.');
    }

    public function test_role_change_endpoint_no_longer_exists(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($admin)
            ->patchJson("/api/admin/users/{$student->id}/role", ['role' => 'teacher'])
            ->assertStatus(404);
    }

    public function test_a_teacher_cannot_delete_a_user(): void
    {
        $teacher = $this->makeUser(['role' => 'teacher']);
        $student = $this->makeUser(['role' => 'student']);
        $this->archiveInDatabase($student);

        $this->actingAs($teacher)
            ->deleteJson("/api/admin/users/{$student->id}")
            ->assertStatus(403);

        $this->assertNotNull(User::find($student->id));
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $student = $this->makeUser(['role' => 'student']);
        $this->archiveInDatabase($student);

        $this->deleteJson("/api/admin/users/{$student->id}")
            ->assertStatus(401);
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

    private function archiveInDatabase(User $user): void
    {
        DB::table('users')->where('id', $user->id)->update([
            'is_active' => false,
            'archived_at' => now(),
        ]);
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
