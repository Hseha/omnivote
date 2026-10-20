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
 * The seat cap is counted in the RACE a seat is contested in, not school-wide.
 *
 * A scoped position is one race per electorate: the provincial posts are
 * contested separately in every college (`scope_type = department`), and the
 * year-level seat is contested separately in every (college, year) pair
 * (`scope_type = department` + `scope_type_2 = year_level`). Counting the cap
 * per (position, party) school-wide made a filled race in one college read as
 * a taken seat in every other college: the student apply returned 409
 * `seat_taken` and the admin could not approve a candidate for a race that
 * was still open.
 *
 * Global seats (the national posts, Senator) keep the school-wide count
 * exactly as before — one race for everybody.
 *
 * Docs: docs/HANDOFF_CANDIDACY_SEAT_CAP.md §1.1.
 */
class CandidacySeatCapElectorateTest extends TestCase
{
    private const CCS = 'College of Computer Studies';

    private const EDUC = 'College of Teacher Education';

    private const CAS = 'College of Arts and Sciences';

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

    public function test_a_scoped_seat_is_counted_per_electorate_not_school_wide(): void
    {
        $governorId = $this->positionId('governor');

        // Another college's Governor race is already filled.
        $this->makeCandidate('ref-educ-gov', 'E', 'governor', 'approved', 'ASLE', [
            'department' => self::EDUC,
            'year_level' => '1',
        ]);

        $ccsStudent = $this->makeUser([
            'name' => 'Ccs Student',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($ccsStudent, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $governorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201)
            ->assertJsonPath('code', null);
    }

    public function test_the_applicants_own_electorate_is_still_capped(): void
    {
        $governorId = $this->positionId('governor');

        $this->makeCandidate('ref-ccs-gov', 'C', 'governor', 'approved', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $ccsStudent = $this->makeUser([
            'name' => 'Ccs Student',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($ccsStudent, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $governorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken');
    }

    public function test_the_other_party_in_the_same_electorate_is_a_separate_seat(): void
    {
        $governorId = $this->positionId('governor');

        $this->makeCandidate('ref-ccs-gov', 'C', 'governor', 'approved', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $ccsStudent = $this->makeUser([
            'name' => 'Ccs Student',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($ccsStudent, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $governorId,
                'party_name' => 'SVEA',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);
    }

    public function test_admin_can_approve_a_candidate_in_an_open_electorate(): void
    {
        $this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']);

        $this->makeCandidate('ref-educ-gov', 'E', 'governor', 'approved', 'ASLE', [
            'department' => self::EDUC,
            'year_level' => '1',
        ]);

        $ccsCandidate = $this->makeCandidate('ref-ccs-gov', 'C', 'governor', 'pending', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs(User::where('email', 'admin@example.test')->first(), 'web')
            ->withHeaders(['Origin' => 'http://localhost'])
            ->patchJson('/api/admin/candidates/'.$ccsCandidate->id, ['status' => 'approved'])
            ->assertOk();

        $this->assertSame('approved', $ccsCandidate->fresh()->approval_status);
    }

    public function test_admin_approval_is_still_refused_inside_a_full_electorate(): void
    {
        $this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']);

        $this->makeCandidate('ref-ccs-gov', 'C', 'governor', 'approved', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $second = $this->makeCandidate('ref-ccs-gov-2', 'C2', 'governor', 'pending', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs(User::where('email', 'admin@example.test')->first(), 'web')
            ->withHeaders(['Origin' => 'http://localhost'])
            ->patchJson('/api/admin/candidates/'.$second->id, ['status' => 'approved'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'seat_taken');

        $this->assertSame('pending', $second->fresh()->approval_status);
    }

    public function test_availability_counts_are_reported_per_electorate(): void
    {
        $this->makeCandidate('ref-educ-gov', 'E', 'governor', 'approved', 'ASLE', [
            'department' => self::EDUC,
            'year_level' => '1',
        ]);

        $ccsStudent = $this->makeUser([
            'name' => 'Ccs Student',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $educStudent = $this->makeUser([
            'name' => 'Educ Student',
            'department' => self::EDUC,
            'year_level' => '1',
        ]);

        $ccsCounts = $this->partyCounts($ccsStudent, 'governor');
        $this->assertSame(0, $ccsCounts['ASLE'] ?? 0, 'The CCS Governor race is open.');
        $this->assertSame(0, $ccsCounts['SVEA'] ?? 0, 'The CCS Governor race is open.');

        $educCounts = $this->partyCounts($educStudent, 'governor');
        $this->assertSame(1, $educCounts['ASLE'] ?? 0, 'The EDUC Governor race is filled.');
    }

    public function test_a_two_dimension_seat_is_counted_per_college_and_year(): void
    {
        $ylrId = $this->positionId('year_level_representative');

        $this->makeCandidate('ref-ccs-y2', 'Y2', 'year_level_representative', 'approved', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '2',
        ]);

        // Same college, different year: a different race, so the seat is open.
        $yearThree = $this->makeUser([
            'name' => 'Ccs Year 3',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($yearThree, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $ylrId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);

        // Same college, same year: the same race, so the seat is taken.
        $otherYearThree = $this->makeUser([
            'name' => 'Ccs Year 3 Two',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($otherYearThree, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $ylrId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken');
    }

    public function test_a_global_seat_still_counts_school_wide(): void
    {
        $presidentId = $this->positionId('president');

        $this->makeCandidate('ref-educ-pres', 'E', 'president', 'approved', 'ASLE', [
            'department' => self::EDUC,
            'year_level' => '1',
        ]);

        $ccsStudent = $this->makeUser([
            'name' => 'Ccs Student',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($ccsStudent, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $presidentId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'seat_taken');
    }

    public function test_a_candidate_without_a_department_does_not_consume_a_scoped_seat(): void
    {
        $governorId = $this->positionId('governor');

        // No department: the candidate stands in no race at all.
        $this->makeCandidate('ref-nodep', 'N', 'governor', 'approved', 'ASLE', [
            'department' => null,
            'year_level' => '1',
        ]);

        $ccsStudent = $this->makeUser([
            'name' => 'Ccs Student',
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        $this->actingAs($ccsStudent, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $governorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(201);
    }

    public function test_an_applicant_without_a_department_cannot_claim_a_scoped_seat(): void
    {
        $governorId = $this->positionId('governor');

        $this->makeCandidate('ref-ccs-gov', 'C', 'governor', 'approved', 'ASLE', [
            'department' => self::CCS,
            'year_level' => '3',
        ]);

        // The applicant has no college, so they are not in the race that the
        // CCS candidate holds — the scope check refuses the application.
        $noCollege = $this->makeUser([
            'name' => 'No College',
            'department' => null,
            'year_level' => '3',
        ]);

        $this->actingAs($noCollege, 'sanctum')
            ->postJson('/api/candidate/apply', [
                'position_id' => $governorId,
                'party_name' => 'ASLE',
                'platform_statement' => "P1\nP2\nP3",
                'certify' => true,
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'out_of_scope');
    }

    /* ------------------------------------------------------------------ */

    /**
     * The per-party counts the availability endpoint reports for a position,
     * as seen by the given account.
     *
     * @return array<string, int>
     */
    private function partyCounts(User $voter, string $positionSlug): array
    {
        $response = $this->actingAs($voter, 'sanctum')
            ->getJson('/api/candidacy/availability')
            ->assertOk();

        $position = collect($response->json('positions'))
            ->firstWhere('slug', $positionSlug);

        $this->assertNotNull($position, "The availability response must carry the {$positionSlug} position.");

        return $position['party_counts'] ?? [];
    }

    private function makeCandidate(
        string $ref,
        string $name,
        string $positionSlug,
        string $status = 'pending',
        ?string $party = null,
        array $ownerAttributes = [],
    ): Candidate {
        $user = $this->makeUser(array_merge(['name' => $name], $ownerAttributes));

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
            $table->string('scope_type')->default('global');
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2')->nullable();
            $table->string('scope_value_2')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // [slug, label, tier, seats, scope_type, scope_type_2]
        $positions = [
            ['president', 'President', 'national', 1, 'global', null],
            ['governor', 'Governor', 'provincial', 1, 'department', null],
            ['year_level_representative', 'Year Level Representative', 'national', 1, 'department', 'year_level'],
        ];

        foreach ($positions as $i => [$slug, $label, $tier, $seats, $scope, $scope2]) {
            DB::table('positions')->insert([
                'slug' => $slug,
                'label' => $label,
                'tier' => $tier,
                'seat_count' => $seats,
                'sort_order' => $i * 10,
                'scope_type' => $scope,
                'scope_value' => null,
                'scope_type_2' => $scope2,
                'scope_value_2' => null,
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
