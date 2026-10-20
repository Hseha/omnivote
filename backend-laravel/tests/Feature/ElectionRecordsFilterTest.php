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
 * The Election Records outcome filter.
 *
 * A record carries TWO status columns: `election_status` (how the race ended
 * — pending / elected / tied) and `approval_status` (what happened to the
 * application — pending / approved / rejected / withdrawn). The filter used to
 * read every option out of `election_status`, so `lost` matched nothing (no
 * such status exists) and `rejected` / `withdrawn` matched nothing either
 * (they are approval statuses). Only `elected` worked, and a tie was not
 * offered at all.
 *
 * Each option now reads the column it actually lives in, and the buckets are
 * mutually exclusive — which is why `lost` (stood and did not win, in a cycle
 * whose outcome was declared) is not the same as `undecided` (stood, but the
 * cycle never concluded).
 */
class ElectionRecordsFilterTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

    private const FINALIZED_CYCLE = 'cycle-finalized';

    private const ABORTED_CYCLE = 'cycle-aborted';

    /** The panel account that reads the records. */
    private User $admin;

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
        $this->seedRecords();

        $this->admin = User::create([
            'name' => 'Records Admin',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    public function test_elected_returns_only_the_winners(): void
    {
        $this->assertSame(['Ana'], $this->namesFor('elected'));
    }

    public function test_tied_returns_only_the_unresolved_ties(): void
    {
        $this->assertSame(['Ben'], $this->namesFor('tied'));
    }

    public function test_lost_returns_the_candidates_who_stood_in_a_finalized_cycle(): void
    {
        // Caro stood in a cycle whose outcome WAS declared, and did not win.
        $this->assertSame(['Caro'], $this->namesFor('lost'));
    }

    public function test_undecided_returns_the_candidates_of_a_cycle_that_never_concluded(): void
    {
        // Fred stood in a cycle that was reset before it could conclude: he
        // did not lose, the race simply never happened.
        $this->assertSame(['Fred'], $this->namesFor('undecided'));
    }

    public function test_rejected_and_withdrawn_read_the_approval_status(): void
    {
        $this->assertSame(['Dara'], $this->namesFor('rejected'));
        $this->assertSame(['Evan'], $this->namesFor('withdrawn'));
    }

    public function test_the_buckets_do_not_overlap(): void
    {
        $seen = [];

        foreach (['elected', 'tied', 'lost', 'undecided', 'rejected', 'withdrawn'] as $result) {
            foreach ($this->namesFor($result) as $name) {
                $this->assertArrayNotHasKey(
                    $name,
                    $seen,
                    "{$name} shows up in more than one outcome bucket."
                );
                $seen[$name] = $result;
            }
        }

        // Every recorded candidate is in exactly one bucket.
        $this->assertCount(6, $seen);
    }

    public function test_a_record_whose_cycle_never_got_a_header_is_still_reachable(): void
    {
        // A record can outlive its cycle header: the header is captured
        // separately from the roster, so a cycle that was never written up
        // leaves its candidates with a cycle id nobody finalised. Their
        // outcome was never declared, so they are undecided.
        DB::table('election_candidate_records')->insert([
            'term_label' => 'SY 2026-2027',
            'cycle_id' => 'cycle-without-a-header',
            'user_name' => 'Gail',
            'user_email' => 'gail@example.test',
            'student_id' => '24-0999',
            'position_slug' => 'president',
            'position_label' => 'President',
            'position_tier' => 'national',
            'party_name' => 'ASLE',
            'approval_status' => 'approved',
            'election_status' => 'pending',
            'vote_total' => 0,
            'certified_winner' => false,
            'snapshot_at' => now(),
        ]);

        $this->assertSame(['Fred', 'Gail'], $this->namesFor('undecided'));
        $this->assertSame(['Ana'], $this->namesFor('elected'));
    }

    public function test_an_unknown_result_is_rejected(): void
    {
        $this->actingAs($this->admin)
            ->stateful()
            ->getJson('/api/admin/election-records?result=nonsense')
            ->assertStatus(422);
    }

    public function test_no_result_filter_returns_every_record(): void
    {
        $this->actingAs($this->admin)
            ->stateful()
            ->getJson('/api/admin/election-records')
            ->assertOk()
            ->assertJsonCount(2, 'cycles');
    }

    public function test_the_filter_vocabulary_is_offered_to_the_screen(): void
    {
        $this->actingAs($this->admin)
            ->stateful()
            ->getJson('/api/admin/election-records')
            ->assertOk()
            ->assertJsonPath('filters.results', [
                'elected', 'tied', 'lost', 'undecided', 'rejected', 'withdrawn',
            ]);
    }

    /* ------------------------------------------------------------------ */

    /**
     * The admin API is stateful (cookie + CSRF), so the request needs the
     * first-party origin the stateful middleware recognises.
     */
    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }

    /**
     * The candidate names the screen shows for one outcome filter.
     *
     * @return list<string>
     */
    private function namesFor(string $result): array
    {
        $response = $this->actingAs($this->admin)
            ->stateful()
            ->getJson('/api/admin/election-records?result='.$result)
            ->assertOk();

        $names = [];

        foreach ($response->json('cycles') ?? [] as $cycle) {
            foreach ($cycle['parties'] ?? [] as $party) {
                foreach ($party['candidates'] ?? [] as $candidate) {
                    $names[] = $candidate['name'];
                }
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Two cycles: one whose outcome was declared, one that was reset before it
     * could conclude. Six candidates between them, one per outcome.
     */
    private function seedRecords(): void
    {
        $this->cycle(self::FINALIZED_CYCLE, 'finalized');
        $this->cycle(self::ABORTED_CYCLE, 'aborted');

        // A finalized cycle.
        $this->record('Ana', self::FINALIZED_CYCLE, 'elected', 'approved', true, 10, 1);
        $this->record('Ben', self::FINALIZED_CYCLE, 'tied', 'approved', false, 8, null);
        $this->record('Caro', self::FINALIZED_CYCLE, 'pending', 'approved', false, 3, null);
        $this->record('Dara', self::FINALIZED_CYCLE, 'pending', 'rejected', false, 0, null);
        $this->record('Evan', self::FINALIZED_CYCLE, 'pending', 'withdrawn', false, 0, null);

        // A cycle that never concluded.
        $this->record('Fred', self::ABORTED_CYCLE, 'pending', 'approved', false, 0, null);
    }

    private function cycle(string $cycleId, string $status): void
    {
        DB::table('election_cycles')->insert([
            'cycle_id' => $cycleId,
            'term_label' => 'SY 2026-2027',
            'status' => $status,
            'election_title' => 'Student Council General Election',
            'snapshot_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function record(
        string $name,
        string $cycleId,
        string $electionStatus,
        string $approvalStatus,
        bool $certified,
        int $votes,
        ?int $rank,
    ): void {
        DB::table('election_candidate_records')->insert([
            'term_label' => 'SY 2026-2027',
            'cycle_id' => $cycleId,
            'candidate_ref' => 'ref-'.strtolower($name),
            'user_name' => $name,
            'user_email' => strtolower($name).'@example.test',
            'student_id' => '24-000'.random_int(1, 9),
            'department' => 'College of Computer Studies',
            'year_level' => '3',
            'position_slug' => 'president',
            'position_label' => 'President',
            'position_tier' => 'national',
            'position_sort_order' => 10,
            'party_name' => 'ASLE',
            'approval_status' => $approvalStatus,
            'election_status' => $electionStatus,
            'vote_total' => $votes,
            'winner_rank' => $rank,
            'certified_winner' => $certified,
            'slogan' => null,
            'snapshot_at' => now(),
        ]);
    }

    /** Minimal schema for the records review screen. */
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
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_recovery_codes')->nullable();
            $table->text('avatar_url')->nullable();
            $table->timestamps();
        });

        Schema::create('election_candidate_records', function (Blueprint $table) {
            $table->id();
            $table->string('term_label');
            $table->string('cycle_id');
            $table->string('candidate_ref')->nullable();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();
            $table->string('student_id')->nullable();
            $table->string('department')->nullable();
            $table->string('year_level')->nullable();
            $table->string('position_slug')->nullable();
            $table->string('position_label')->nullable();
            $table->string('position_tier')->nullable();
            $table->unsignedInteger('position_sort_order')->nullable();
            $table->string('party_name')->nullable();
            $table->string('approval_status')->nullable();
            $table->string('election_status')->nullable();
            $table->integer('vote_total')->default(0);
            $table->unsignedInteger('winner_rank')->nullable();
            $table->boolean('certified_winner')->default(false);
            $table->text('slogan')->nullable();
            $table->timestamp('snapshot_at')->useCurrent();
        });

        Schema::create('election_cycles', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_id')->unique();
            $table->string('term_label')->nullable();
            $table->string('status')->nullable();
            $table->string('election_title')->nullable();
            $table->timestamp('registration_opens_at')->nullable();
            $table->timestamp('registration_closes_at')->nullable();
            $table->timestamp('voting_opens_at')->nullable();
            $table->timestamp('voting_closes_at')->nullable();
            $table->timestamp('term_ends_at')->nullable();
            $table->boolean('results_published')->default(false);
            $table->unsignedInteger('registered_students')->nullable();
            $table->unsignedInteger('voters_who_voted')->nullable();
            $table->unsignedInteger('votes_cast')->nullable();
            $table->unsignedInteger('candidates_total')->nullable();
            $table->unsignedInteger('seats_filled')->nullable();
            $table->timestamp('snapshot_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamp('aborted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('election_cycle_positions', function (Blueprint $table) {
            $table->id();
            $table->string('cycle_id');
            $table->string('position_slug');
            $table->string('position_label');
            $table->string('position_tier')->nullable();
            $table->unsignedTinyInteger('seat_count')->nullable();
            $table->unsignedInteger('sort_order')->nullable();
            $table->string('scope_type')->nullable();
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2')->nullable();
            $table->string('scope_value_2')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('snapshot_at')->useCurrent();
        });
    }
}
