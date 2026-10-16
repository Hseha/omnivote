<?php

namespace Tests\Feature;

use Database\Seeders\TestBallotSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The demo ballot seeder must leave the executive races empty so real users can
 * file for them live (e.g. the instructor demonstrating the apply flow).
 *
 * Those seats have `seat_count = 1` per party, so a seeded test candidate would
 * consume the seat and a real applicant would hit a 409 `seat_taken`. This
 * pins the reservation contract: President/Vice President (national) and
 * Governor/Vice Governor (provincial, every college) get no test candidates,
 * while every other race is still populated.
 *
 * Like the rest of the suite, this builds an isolated in-memory SQLite schema
 * instead of RefreshDatabase: the migration set contains MySQL-only ALTER TABLE
 * statements and the default connection of this checkout points at a live MySQL
 * database (see SeederProductionGuardTest for the same reasoning).
 */
class TestBallotReservedSeatsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Drop any connection resolved during boot before repointing the default
        // connection at the private in-memory database, so the seeder can never
        // reach the developer's MySQL database.
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

    public function test_reserved_executive_seats_are_left_empty(): void
    {
        $this->seed(TestBallotSeeder::class);

        foreach (['president', 'vice_president', 'governor', 'vice_governor'] as $slug) {
            $this->assertSame(
                0,
                $this->candidatesFor($slug),
                "Reserved seat [{$slug}] must have no seeded candidates.",
            );
        }
    }

    public function test_every_other_seat_is_still_populated(): void
    {
        $this->seed(TestBallotSeeder::class);

        // National: one per party (ASLE + SVEA).
        $this->assertSame(2, $this->candidatesFor('secretary'));
        $this->assertSame(2, $this->candidatesFor('property_custodian'));
        // Senator: the 12 seats field a full 12-per-party slate.
        $this->assertSame(24, $this->candidatesFor('senator'));
        // Year-level rep: 5 colleges x 4 years x 2 parties.
        $this->assertSame(40, $this->candidatesFor('year_level_representative'));

        // Provincial: every college runs its own slate (5 colleges x 2 parties),
        // except the reserved Governor / Vice Governor races.
        $this->assertSame(10, $this->candidatesFor('provincial_secretary'));
        $this->assertSame(10, $this->candidatesFor('provincial_custodian'));
    }

    /** Candidate rows filed against a position slug. */
    private function candidatesFor(string $slug): int
    {
        return DB::table('candidates')
            ->join('positions', 'candidates.position_id', '=', 'positions.id')
            ->where('positions.slug', $slug)
            ->count();
    }

    /** Minimal schema for the tables TestBallotSeeder touches. */
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

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier')->default('school');
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // The canonical 16 positions (only slug/label/tier matter to the seeder).
        $positions = [
            ['president', 'President', 'school', 1],
            ['vice_president', 'Vice President', 'school', 1],
            ['secretary', 'Secretary', 'school', 1],
            ['treasurer', 'Treasurer', 'school', 1],
            ['auditor', 'Auditor', 'school', 1],
            ['press_officer', 'Press Officer', 'school', 1],
            ['senator', 'Senator', 'school', 12],
            ['year_level_representative', 'Year Level Representative', 'school', 1],
            ['property_custodian', 'Property Custodian', 'school', 1],
            ['governor', 'Governor', 'provincial', 1],
            ['vice_governor', 'Vice Governor', 'provincial', 1],
            ['provincial_secretary', 'Provincial Secretary', 'provincial', 1],
            ['provincial_treasurer', 'Provincial Treasurer', 'provincial', 1],
            ['provincial_auditor', 'Provincial Auditor', 'provincial', 1],
            ['provincial_press_officer', 'Provincial Press Officer', 'provincial', 1],
            ['provincial_custodian', 'Provincial Custodian', 'provincial', 1],
        ];
        foreach ($positions as $i => [$slug, $label, $tier, $seats]) {
            DB::table('positions')->insert([
                'slug' => $slug,
                'label' => $label,
                'tier' => $tier,
                'seat_count' => $seats,
                'sort_order' => $i * 10,
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
            $table->string('approval_status')->default('pending');
            $table->string('election_status', 16)->default('pending');
            $table->unsignedInteger('vote_total')->default(0);
            $table->timestamps();
        });
    }
}
