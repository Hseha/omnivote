<?php

namespace Tests\Feature;

use App\Models\Phase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Time-driven election phase derivation.
 *
 * The phase is a pure function of the configured window (Settings → Voting
 * Windows) and wall-clock time:
 *
 *   - no window                    → null (nothing open; CheckPhase blocks)
 *   - before Registration Opens    → registration_closed
 *   - Registration window          → registration
 *   - gap Reg Close → Voting Open  → registration_closed
 *   - Voting window                → voting_open
 *   - after Voting Closes          → voting_closed
 *
 * Uses the same isolated in-memory SQLite schema as the other admin feature
 * tests (skipped locally where pdo_sqlite is missing — the CI runner has it).
 */
class PhaseTimelineTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_no_window_reports_null(): void
    {
        $this->assertNull(Phase::current());
    }

    public function test_before_registration_opens_is_registration_closed(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->addDays(2),
            'voting_opens_at' => now()->addDays(10),
            'voting_closes_at' => now()->addDays(12),
        ]);

        $this->assertSame('registration_closed', Phase::current()?->name);
    }

    public function test_registration_window_is_registration(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDay(),
            'registration_closes_at' => now()->addDay(),
            'voting_opens_at' => now()->addDays(2),
            'voting_closes_at' => now()->addDays(3),
        ]);

        $this->assertSame('registration', Phase::current()?->name);
    }

    public function test_gap_between_registration_close_and_voting_open_is_closed(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(2),
            'registration_closes_at' => now()->subHour(),
            'voting_opens_at' => now()->addHours(2),
            'voting_closes_at' => now()->addDay(),
        ]);

        $this->assertSame('registration_closed', Phase::current()?->name);
    }

    public function test_voting_window_is_voting_open(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(2),
            'registration_closes_at' => now()->subDay(),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ]);

        $this->assertSame('voting_open', Phase::current()?->name);
    }

    public function test_after_voting_closes_is_voting_closed(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->subDays(2),
            'voting_opens_at' => now()->subDay(),
            'voting_closes_at' => now()->subHour(),
        ]);

        $this->assertSame('voting_closed', Phase::current()?->name);
    }

    public function test_legacy_voting_only_window_maps_onto_the_same_shape(): void
    {
        $this->setWindow([
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ]);

        $this->assertSame('voting_open', Phase::current()?->name);
    }

    public function test_registration_only_window_is_closed_once_registration_ends(): void
    {
        // A window that schedules registration but never sets voting dates must
        // NOT jump to a fake "voting_open" once registration is over.
        $this->setWindow([
            'registration_opens_at' => now()->subDays(2),
            'registration_closes_at' => now()->subHour(),
        ]);

        $this->assertSame('registration_closed', Phase::current()?->name);
    }

    public function test_registration_window_without_a_close_stays_registration(): void
    {
        // Mirrors the "no close configured → phase stays live" convention used
        // for the voting window: an open registration with no end never leaks
        // into a fake voting phase.
        $this->setWindow([
            'registration_opens_at' => now()->subHour(),
        ]);

        $this->assertSame('registration', Phase::current()?->name);
    }

    public function test_registration_only_window_is_closed_before_it_opens(): void
    {
        $this->setWindow([
            'registration_opens_at' => now()->addDay(),
        ]);

        $this->assertSame('registration_closed', Phase::current()?->name);
    }

    private function setWindow(array $dates): void
    {
        foreach ($dates as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }

    private function createSchema(): void
    {
        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::table('phases')->insert([
            ['name' => 'registration', 'description' => 'Registration phase', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_open', 'description' => 'Voting is open', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_closed', 'description' => 'Voting closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'registration_closed', 'description' => 'Registration is closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
