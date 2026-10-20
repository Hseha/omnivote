<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Database\Seeders\DemoSlateSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The demo slate must fill every race except the four it deliberately opens.
 *
 * The open seats are President (both parties) and the Governor race of one
 * college — a real applicant has to be able to file for them, and a demo
 * nominee would consume the seat.
 *
 * The slate is also asserted to be cap-compliant: one nominee per party in
 * every race, so the panel can still approve every seeded candidate.
 *
 * Like the rest of the suite, this builds an isolated in-memory SQLite schema
 * instead of using RefreshDatabase (see SeederProductionGuardTest for the
 * same reasoning).
 */
class DemoSlateSeederTest extends TestCase
{
    private const CCS = 'College of Computer Studies';

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
        ]);

        DB::setDefaultConnection('omnivote_testing');
        DB::purge('omnivote_testing');

        $this->createSchema();
    }

    public function test_the_slate_opens_exactly_four_seats(): void
    {
        $this->seed(DemoSlateSeeder::class);

        // President is empty in both parties.
        $this->assertSame(0, $this->candidatesFor('president'), 'President must stay empty.');

        // Governor is filled in every college except the open one, which is
        // empty in both parties.
        $this->assertSame(8, $this->candidatesFor('governor'), 'Governor is filled in four colleges.');
        $this->assertSame(
            0,
            $this->candidatesFor('governor', self::CCS),
            'The open college must have no Governor candidate.'
        );
    }

    public function test_every_other_race_is_filled(): void
    {
        $this->seed(DemoSlateSeeder::class);

        // National single-seat posts: one per party.
        foreach (['vice_president', 'secretary', 'treasurer', 'auditor', 'press_officer', 'property_custodian'] as $slug) {
            $this->assertSame(2, $this->candidatesFor($slug), "{$slug} must have one nominee per party.");
        }

        // Senator is a 12-seat race: 12 per party.
        $this->assertSame(24, $this->candidatesFor('senator'));

        // Year-level seat: one race per college and year level, one per party.
        $this->assertSame(40, $this->candidatesFor('year_level_representative'));

        // Provincial posts: one race per college, one nominee per party.
        foreach (['vice_governor', 'provincial_secretary', 'provincial_treasurer', 'provincial_auditor', 'provincial_press_officer', 'provincial_custodian'] as $slug) {
            $this->assertSame(10, $this->candidatesFor($slug), "{$slug} must be filled in every college.");
        }
    }

    public function test_every_candidate_is_approved(): void
    {
        $this->seed(DemoSlateSeeder::class);

        $this->assertSame(
            0,
            Candidate::whereIn('approval_status', ['pending', 'rejected', 'withdrawn'])->count(),
            'Every demo candidate must be approved.'
        );

        $this->assertSame(144, Candidate::count());
    }

    public function test_a_provincial_candidate_stands_in_their_own_college(): void
    {
        $this->seed(DemoSlateSeeder::class);

        $rows = DB::table('candidates')
            ->join('positions', 'positions.id', '=', 'candidates.position_id')
            ->join('users', 'users.id', '=', 'candidates.user_id')
            ->where('positions.slug', 'vice_governor')
            ->get(['users.department as department']);

        $this->assertCount(10, $rows);

        foreach ($rows as $row) {
            $this->assertNotEmpty(
                $row->department,
                'A provincial candidate must belong to a college, or their race would be invisible.'
            );
        }
    }

    public function test_a_year_level_candidate_matches_their_own_year_level(): void
    {
        $this->seed(DemoSlateSeeder::class);

        $rows = DB::table('candidates')
            ->join('positions', 'positions.id', '=', 'candidates.position_id')
            ->join('users', 'users.id', '=', 'candidates.user_id')
            ->where('positions.slug', 'year_level_representative')
            ->get(['users.department as department', 'users.year_level as year_level']);

        $this->assertCount(40, $rows);

        $groups = $rows->map(fn ($r) => $r->department.'|'.$r->year_level)->unique();

        // 5 colleges x 4 year levels, two parties each.
        $this->assertCount(20, $groups);
    }

    public function test_the_accounts_have_distinct_names_and_logins(): void
    {
        $this->seed(DemoSlateSeeder::class);

        $students = User::query()->whereIn('role', User::VOTER_ROLES)->get();

        $this->assertGreaterThan(140, $students->count());

        $this->assertSame(
            $students->count(),
            $students->pluck('name')->unique()->count(),
            'Every demo account needs its own name.'
        );

        $this->assertSame(
            $students->count(),
            $students->pluck('email')->unique()->count(),
            'Every demo account needs its own login handle.'
        );
    }

    public function test_every_college_year_electorate_has_voters(): void
    {
        $this->seed(DemoSlateSeeder::class);

        foreach (['College of Teacher Education', 'College of Arts and Sciences', self::CCS, 'College of Criminal Justice Education', 'College of Office Administration'] as $college) {
            foreach (['1', '2', '3', '4'] as $year) {
                $voters = User::query()
                    ->whereIn('role', User::VOTER_ROLES)
                    ->where('department', $college)
                    ->where('year_level', $year)
                    ->count();

                $this->assertGreaterThanOrEqual(
                    8,
                    $voters,
                    "The {$college} year {$year} electorate must have voters, or its seat can never be contested."
                );
            }
        }
    }

    public function test_a_rerun_does_not_duplicate_the_slate(): void
    {
        $this->seed(DemoSlateSeeder::class);
        $first = Candidate::count();
        $firstStudents = User::query()->whereIn('role', User::VOTER_ROLES)->count();

        $this->seed(DemoSlateSeeder::class);

        $this->assertSame($first, Candidate::count(), 'A re-run must not add candidates.');
        $this->assertSame($firstStudents, User::query()->whereIn('role', User::VOTER_ROLES)->count(), 'A re-run must not add accounts.');
    }

    public function test_it_refuses_to_run_in_production_without_the_explicit_opt_in(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        // --force is load-bearing: `db:seed` in production asks for
        // confirmation and aborts before it ever reaches the seeder, so
        // without it this test would pass whether or not the guard existed.
        $this->artisan('db:seed', ['--class' => DemoSlateSeeder::class, '--force' => true])
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        $this->assertSame(0, Candidate::count());
        $this->assertSame(0, User::query()->whereIn('role', User::VOTER_ROLES)->count());
    }

    /* ------------------------------------------------------------------ */

    /** Candidate rows filed against a position slug, optionally per college. */
    private function candidatesFor(string $slug, ?string $college = null): int
    {
        return DB::table('candidates')
            ->join('positions', 'positions.id', '=', 'candidates.position_id')
            ->join('users', 'users.id', '=', 'candidates.user_id')
            ->where('positions.slug', $slug)
            ->when($college !== null, fn ($q) => $q->where('users.department', $college))
            ->count();
    }

    /** Minimal schema for the tables the demo slate touches. */
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
            $table->string('block_number')->nullable();
            $table->string('department')->nullable();
            $table->string('course')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('must_change_password')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('role')->default('student');
            $table->string('year_level')->nullable();
            $table->string('block_number')->nullable();
            $table->string('department')->nullable();
            $table->string('course')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
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
            ['vice_president', 'Vice President', 'national', 1, 'global', null],
            ['secretary', 'Secretary', 'national', 1, 'global', null],
            ['treasurer', 'Treasurer', 'national', 1, 'global', null],
            ['auditor', 'Auditor', 'national', 1, 'global', null],
            ['press_officer', 'Press Officer', 'national', 1, 'global', null],
            ['senator', 'Senator', 'national', 12, 'global', null],
            ['year_level_representative', 'Year Level Representative', 'national', 1, 'department', 'year_level'],
            ['property_custodian', 'Property Custodian', 'national', 1, 'global', null],
            ['governor', 'Governor', 'provincial', 1, 'department', null],
            ['vice_governor', 'Vice Governor', 'provincial', 1, 'department', null],
            ['provincial_secretary', 'Provincial Secretary', 'provincial', 1, 'department', null],
            ['provincial_treasurer', 'Provincial Treasurer', 'provincial', 1, 'department', null],
            ['provincial_auditor', 'Provincial Auditor', 'provincial', 1, 'department', null],
            ['provincial_press_officer', 'Provincial Press Officer', 'provincial', 1, 'department', null],
            ['provincial_custodian', 'Provincial Custodian', 'provincial', 1, 'department', null],
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
            $table->string('election_status', 16)->default('pending');
            $table->unsignedInteger('vote_total')->default(0);
            $table->timestamps();
        });
    }
}
