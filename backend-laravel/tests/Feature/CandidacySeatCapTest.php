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
 * Seat-cap enforcement: at most `position.seat_count` candidates per party
 * per position (1 everywhere, 12 for Senator) — `pending` and `approved`
 * claims block; `rejected`/`withdrawn` free the seat; null party ("No party")
 * buckets separately; the applicant's guard excludes nobody because a student
 * can only ever hold one active application.
 *
 * Docs: docs/HANDOFF_CANDIDACY_SEAT_CAP.md.
 */
class CandidacySeatCapTest extends TestCase
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
        $this->openRegistration();
    }

    public function test_second_asle_president_application_is_rejected(): void
    {
        $presidentId = $this->positionId('president');
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'approved', 'ASLE');

        $voter = $this->makeUser(['name' => 'Bryan']);

        $response = $this->actingAs($voter, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ]);

        $response->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken')
            ->assertJsonPath('position_id', $presidentId)
            ->assertJsonPath('party_name', 'ASLE');

        $this->assertSame(1, Candidate::where('position_id', $presidentId)->count());
    }

    public function test_rejected_application_frees_the_seat(): void
    {
        $presidentId = $this->positionId('president');
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'rejected', 'ASLE');

        $voter = $this->makeUser(['name' => 'Bryan']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);
    }

    public function test_withdrawn_application_frees_the_seat(): void
    {
        $presidentId = $this->positionId('president');
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'withdrawn', 'ASLE');

        $voter = $this->makeUser(['name' => 'Bryan']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);
    }

    public function test_twelfth_asle_senator_allowed_thirteenth_rejected(): void
    {
        $senatorId = $this->positionId('senator');
        for ($i = 1; $i <= 11; $i++) {
            $this->makeCandidate('ref-sen-'.$i, 'Senator '.$i, 'senator', 'approved', 'ASLE');
        }

        $twelfth = $this->makeUser(['name' => 'Twelfth']);
        $this->actingAs($twelfth, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $senatorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);

        $thirteenth = $this->makeUser(['name' => 'Thirteenth']);
        $this->actingAs($thirteenth, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $senatorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken');

        $this->assertSame(
            12,
            Candidate::where('position_id', $senatorId)->where('party_name', 'ASLE')->count()
        );
    }

    public function test_independent_applications_share_their_own_bucket(): void
    {
        $presidentId = $this->positionId('president');

        $first = $this->makeUser(['name' => 'Indie One']);
        $this->actingAs($first, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => null,
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);

        $second = $this->makeUser(['name' => 'Indie Two']);
        $this->actingAs($second, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => null,
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken');
    }

    public function test_lowercase_party_matches_the_canonical_seat(): void
    {
        $presidentId = $this->positionId('president');
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'approved', 'ASLE');

        $voter = $this->makeUser(['name' => 'Bryan']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'asle',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken')
            ->assertJsonPath('party_name', 'ASLE');
    }

    public function test_unknown_party_is_a_validation_error_not_a_cap_hit(): void
    {
        $presidentId = $this->positionId('president');
        $voter = $this->makeUser(['name' => 'Bryan']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'NOT_A_PARTY',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(422);
    }

    public function test_admin_cannot_approve_past_the_cap(): void
    {
        $this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']);
        $presidentId = $this->positionId('president');
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'approved', 'ASLE');
        $bryan = $this->makeCandidate('ref-bryan', 'Bryan', 'president', 'pending', 'ASLE');

        $this->actingAs(User::where('email', 'admin@example.test')->first(), 'web')
            ->withHeaders(['Origin' => 'http://localhost'])
            ->patchJson('/api/admin/candidates/'.$bryan->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'seat_taken');

        $this->assertSame('pending', $bryan->fresh()->approval_status);
    }

    public function test_admin_can_approve_the_thirteenth_rejected_row(): void
    {
        $this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']);
        $presidentId = $this->positionId('president');
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'rejected', 'ASLE');
        $bryan = $this->makeCandidate('ref-bryan', 'Bryan', 'president', 'pending', 'ASLE');

        $this->actingAs(User::where('email', 'admin@example.test')->first(), 'web')
            ->withHeaders(['Origin' => 'http://localhost'])
            ->patchJson('/api/admin/candidates/'.$bryan->id, ['status' => 'approved'])
            ->assertOk();

        $this->assertSame('approved', $bryan->fresh()->approval_status);
    }

    public function test_per_student_guard_still_comes_first(): void
    {
        $presidentId = $this->positionId('president');
        $senatorId = $this->positionId('senator');
        $voter = $this->makeUser(['name' => 'Bryan']);
        $this->makeCandidate('ref-bryan', 'Bryan', 'president', 'pending', 'SVEA', $voter);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $senatorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'application_exists');
    }

    public function test_availability_endpoint_reports_party_counts(): void
    {
        $this->makeCandidate('ref-ana', 'Ana', 'president', 'approved', 'ASLE');
        $this->makeCandidate('ref-sen-1', 'Sen One', 'senator', 'pending', 'ASLE');
        $this->makeCandidate('ref-sen-2', 'Sen Two', 'senator', 'approved', 'SVEA');

        $voter = $this->makeUser(['name' => 'Voter']);

        $response = $this->actingAs($voter, 'sanctum')
            ->getJson('/api/candidacy/availability')
            ->assertOk();

        $positions = collect($response->json('positions'))->keyBy('slug');

        $this->assertSame(['ASLE' => 1], $positions['president']['party_counts']);
        $this->assertSame(1, $positions['president']['seat_count']);
        $this->assertSame(
            ['ASLE' => 1, 'SVEA' => 1],
            $positions['senator']['party_counts']
        );
        $this->assertSame(12, $positions['senator']['seat_count']);
    }

    /* ------------------------------------------------------------------ */

    private function makeCandidate(
        string $ref,
        string $name,
        string $positionSlug,
        string $status = 'pending',
        ?string $party = null,
        ?User $user = null,
    ): Candidate {
        $user ??= $this->makeUser(['name' => $name]);

        return Candidate::create([
            'user_id' => $user->id,
            'position_id' => $this->positionId($positionSlug),
            'candidate_ref' => $ref,
            'slogan' => null,
            'party_name' => $party,
            'platform_statement' => "Platform\nPoints",
            'approval_status' => $status,
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
            'must_change_password' => false,
        ], $attributes));
    }

    private function positionId(string $slug): int
    {
        return (int) DB::table('positions')->where('slug', $slug)->value('id');
    }

    /** Registration phase so POST /candidate/apply passes its phase gate. */
    private function openRegistration(): void
    {
        $window = [
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->addDay(),
            'voting_opens_at' => now()->addDays(2),
            'voting_closes_at' => now()->addDays(3),
        ];

        foreach ($window as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }

    private function allowNoTwoFactor(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => 'false', 'updated_at' => now()],
        );
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
            $table->string('slogan')->nullable();
            $table->string('party_name')->nullable();
            $table->text('platform_statement')->nullable();
            $table->text('platform_points')->nullable();
            $table->string('photo_path')->nullable();
            $table->string('approval_status')->default('pending');
            $table->string('rejection_reason', 1000)->nullable();
            $table->unsignedInteger('rejection_count')->default(0);
            $table->timestamp('reapply_blocked_until')->nullable();
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
    }
}
