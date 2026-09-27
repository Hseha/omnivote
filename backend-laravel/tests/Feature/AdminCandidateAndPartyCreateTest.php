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
 * Admin-created candidates + the canonical party list the React Candidates
 * screen depends on:
 *
 *   GET  /api/admin/candidates — meta.parties comes from the `parties` table
 *        (so parties without candidate rows, e.g. SVEA, still appear)
 *   POST /api/admin/candidates — create a pending candidacy for a student
 *   POST /api/admin/parties    — add a name-only party (case-insensitively
 *        unique), shown to students immediately
 */
class AdminCandidateAndPartyCreateTest extends TestCase
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

    public function test_admin_index_lists_parties_without_candidate_rows(): void
    {
        $this->makeCandidate('ref-asle', 'Ana', 'president', 'ASLE');

        $this->asAdmin()
            ->getJson('/api/admin/candidates')
            ->assertOk()
            ->assertJsonPath('meta.parties', ['ASLE', 'SVEA']);
    }

    public function test_admin_can_add_a_party(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/parties', ['name' => 'UNITY'])
            ->assertStatus(201)
            ->assertJsonPath('party.name', 'UNITY');

        $this->assertSame(1, DB::table('parties')->where('name', 'UNITY')->count());
    }

    public function test_party_names_are_case_insensitively_unique(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/parties', ['name' => 'svea'])
            ->assertStatus(409);
    }

    public function test_admin_can_create_a_pending_candidate_for_a_student(): void
    {
        $student = $this->makeUser(['student_id' => 'S-2026-0001', 'name' => 'Bailey Reyes']);

        $this->asAdmin()
            ->postJson('/api/admin/candidates', [
                'student_id' => 'S-2026-0001',
                'position_id' => $this->positionId('president'),
                'party_name' => 'SVEA',
                'slogan' => 'Build the future',
            ])
            ->assertStatus(201)
            ->assertJsonPath('candidate.position', 'President')
            ->assertJsonPath('candidate.party', 'SVEA')
            ->assertJsonPath('candidate.status', 'pending');

        $candidate = Candidate::where('user_id', $student->id)->first();
        $this->assertNotNull($candidate);
        $this->assertSame('pending', $candidate->approval_status);
        $this->assertSame('Build the future', $candidate->slogan);
        $this->assertSame('President', $candidate->position->label);
        $this->assertNull($candidate->platform_statement);
    }

    public function test_admin_cannot_create_duplicate_active_application_for_student(): void
    {
        $student = $this->makeUser(['student_id' => 'S-2026-0002', 'name' => 'Casey Lim']);
        $this->makeCandidate('ref-casey', 'Casey Lim', 'president', 'ASLE', user_id: $student->id);

        $this->asAdmin()
            ->postJson('/api/admin/candidates', [
                'student_id' => 'S-2026-0002',
                'position_id' => $this->positionId('senator'),
            ])
            ->assertStatus(409);
    }

    public function test_admin_creating_candidate_requires_a_registered_student_id(): void
    {
        $this->asAdmin()
            ->postJson('/api/admin/candidates', [
                'student_id' => 'S-0000',
                'position_id' => $this->positionId('president'),
            ])
            ->assertStatus(422);
    }

    public function test_teachers_are_allowed_to_create_candidates_and_parties(): void
    {
        $this->makeUser(['student_id' => 'S-2026-0003', 'name' => 'Dana Ortiz']);

        $this->actingAs($this->makeUser(['email' => 'teacher@example.test', 'role' => 'teacher']), 'web')
            ->postJson('/api/admin/parties', ['name' => 'PEAK'])
            ->assertStatus(201);

        $this->actingAs($this->makeUser(['email' => 'teacher2@example.test', 'role' => 'teacher']), 'web')
            ->postJson('/api/admin/candidates', [
                'student_id' => 'S-2026-0003',
                'position_id' => $this->positionId('president'),
            ])
            ->assertStatus(201);
    }

    /* ------------------------------------------------------------------ */

    private function asAdmin(): static
    {
        return $this->actingAs($this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']), 'web')
            ->withHeaders(['Origin' => 'http://localhost']);
    }

    private function allowNoTwoFactor(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => 'false', 'updated_at' => now()],
        );
    }

    private function positionId(string $slug): int
    {
        return (int) DB::table('positions')->where('slug', $slug)->value('id');
    }

    private function makeCandidate(
        string $ref,
        string $name,
        string $positionSlug,
        ?string $party = null,
        ?int $user_id = null,
    ): Candidate {
        return Candidate::create([
            'user_id' => $user_id ?? $this->makeUser(['name' => $name])->id,
            'position_id' => $this->positionId($positionSlug),
            'candidate_ref' => $ref,
            'party_name' => $party,
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
        ]);
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

        Schema::create('parties', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        DB::table('parties')->insert([
            ['name' => 'ASLE', 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'SVEA', 'sort_order' => 2, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('party_list')->nullable();
            $table->string('position_key')->nullable();
            $table->string('slogan')->nullable();
            $table->string('party_name')->nullable();
            $table->text('platform_statement')->nullable();
            $table->text('platform_points')->nullable();
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

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}