<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Public results + SSG officer roster projections.
 *
 *   GET /api/results                 — per-position tallies (voting_closed only)
 *   GET /api/admin/ssg/officers      — finalized roster for the SSG President view
 *   GET /api/admin/candidates/export — 501 stub (real export = /admin/results)
 *
 * These endpoints read the anonymous vote_ledger and hydrate candidate names,
 * which is exactly where an accidental N+1 hides: the projections now eager
 * load the candidate's user (and position for the roster), so the assertions
 * below pin the payload that eager loading must keep producing.
 */
class PublicResultsTest extends TestCase
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
    }

    public function test_public_results_tally_votes_and_resolve_candidate_names(): void
    {
        $this->closeVoting();

        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3);
        $this->makeCandidate('ref-ben', 'Ben', 'president', votes: 2);

        $tally = $this->results()->json('results.0');

        $this->assertSame('president', $tally['position_key']);
        $this->assertSame('President', $tally['position_label']);
        $this->assertCount(2, $tally['candidates']);

        // Highest votes first, and the name comes from the linked user (eager
        // loaded), never the opaque ledger ref.
        $this->assertSame('Ana', $tally['candidates'][0]['name']);
        $this->assertSame(3, $tally['candidates'][0]['votes']);
        $this->assertSame('ref-ana', $tally['candidates'][0]['candidate_ref']);
        $this->assertSame('Ben', $tally['candidates'][1]['name']);
        $this->assertSame(2, $tally['candidates'][1]['votes']);
    }

    public function test_results_survive_ledger_rows_whose_candidate_or_position_is_gone(): void
    {
        $this->closeVoting();

        // Ledger rows outlive the rows they point at: a withdrawn candidate and
        // a retired position slug must degrade gracefully, not 500 the endpoint.
        $this->ledgerRow('ghost-ref', 'president', 1);
        $this->ledgerRow('ref-retired', 'retired_position', 2);

        $tallies = $this->results()->json('results');

        $president = collect($tallies)->firstWhere('position_key', 'president');
        $this->assertSame('ghost-ref', $president['candidates'][0]['name']);
        $this->assertNull($president['candidates'][0]['id']);
        $this->assertSame('pending', $president['candidates'][0]['election_status']);

        $retired = collect($tallies)->firstWhere('position_key', 'retired_position');
        $this->assertSame('Retired position', $retired['position_label']);
        $this->assertSame('ref-retired', $retired['candidates'][0]['name']);
    }

    public function test_public_results_are_refused_until_voting_closes(): void
    {
        // No election window configured at all: nothing is published yet.
        $response = $this->asStudent()->getJson('/api/results');

        $response->assertStatus(403)->assertJsonPath('phase', null);
    }

    public function test_ssg_officer_roster_returns_elected_officers_with_position_and_name(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);

        $this->asAdmin()
            ->getJson('/api/admin/ssg/officers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.position_key', 'president')
            ->assertJsonPath('data.0.position_label', 'President')
            ->assertJsonPath('data.0.candidate_ref', 'ref-ana')
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.votes', 3)
            ->assertJsonPath('data.0.seat_count', 1);
    }

    public function test_ssg_officer_roster_falls_back_to_top_votes_when_never_finalized(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        // All candidates still pending → the roster shows the raw vote leaders.
        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 2);

        $this->asAdmin()
            ->getJson('/api/admin/ssg/officers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.votes', 2);
    }

    public function test_ssg_officer_roster_waits_until_voting_closes(): void
    {
        $this->twoFactorOff();

        // Window still open → the roster is not published yet.
        $this->window([
            'registration_opens_at' => now()->subDays(2),
            'registration_closes_at' => now()->subDay(),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ]);

        $this->asAdmin()
            ->getJson('/api/admin/ssg/officers')
            ->assertStatus(403)
            ->assertJsonPath('message', 'The officer roster is available after voting is closed.');
    }

    public function test_archive_term_archives_winners_under_a_school_year_label(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        // Certified winner with ledger votes, as a real finalized term would.
        $winner = $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);
        $winner->forceFill(['certified_winner' => true, 'certified_at' => now()])->save();

        // Stateful admin routes are cookie sessions: authenticate once and run
        // the whole admin scenario on that single session.
        $this->actingAs($this->makeUser(['role' => 'admin']))
            ->withHeaders(['Origin' => 'http://localhost']);

        $this->postJson('/api/admin/results/archive-term')
            ->assertOk()
            ->assertJsonPath('archived', 1)
            ->assertJsonPath('term_label', $this->expectedTermLabel());

        $this->getJson('/api/admin/ssg/officers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_archive_term_hides_last_terms_winner_from_live_results(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        $winner = $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);
        $winner->forceFill(['certified_winner' => true, 'certified_at' => now()])->save();

        $this->actingAs($this->makeUser(['role' => 'admin']))
            ->withHeaders(['Origin' => 'http://localhost']);

        $this->postJson('/api/admin/results/archive-term')->assertOk();

        // The Origin above is stateful-only: drop it so the student bearer
        // request below uses its own token and is not folded into the admin session.
        $this->defaultHeaders = [];

        $live = collect($this->results()->json('results'))
            ->firstWhere('position_key', 'president');
        $this->assertSame([], $live['candidates']);
    }

    public function test_archived_winners_are_browsable_in_the_past_terms_archive(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        $winner = $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);
        $winner->forceFill(['certified_winner' => true, 'certified_at' => now()])->save();

        $this->actingAs($this->makeUser(['role' => 'admin']))
            ->withHeaders(['Origin' => 'http://localhost']);

        // Empty archive before the term is ended.
        $this->getJson('/api/admin/results/archive')
            ->assertOk()
            ->assertJsonCount(0, 'terms');

        $this->postJson('/api/admin/results/archive-term')->assertOk();

        $this->getJson('/api/admin/results/archive')
            ->assertOk()
            ->assertJsonCount(1, 'terms')
            ->assertJsonPath('terms.0.term_label', $this->expectedTermLabel())
            ->assertJsonPath('terms.0.winners.0.name', 'Ana')
            ->assertJsonPath('terms.0.winners.0.position', 'President');
    }

    public function test_archive_term_is_idempotent_when_there_is_nothing_to_archive(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        $this->asAdmin()
            ->postJson('/api/admin/results/archive-term')
            ->assertOk()
            ->assertJsonPath('archived', 0);
    }

    /* ------------------------------------------------------------------ */

    /** GET /api/results the way the mobile client does: student bearer token. */
    private function results(): TestResponse
    {
        return $this->asStudent()->getJson('/api/results')->assertOk();
    }

    /** A request authenticated as a student via the sanctum guard. */
    private function asStudent(): static
    {
        return $this->actingAs($this->makeUser(['role' => 'student']), 'sanctum');
    }

    /** A first-party SPA request authenticated as a panel administrator. */
    private function asAdmin(): static
    {
        return $this->actingAs($this->makeUser(['role' => 'admin']))
            ->withHeaders(['Origin' => 'http://localhost']);
    }

    /**
     * Permission-gated panel routes are secure-by-default: an unenrolled admin
     * is refused with "Two-factor authentication is required…" (EnsurePermission)
     * unless Settings → Security explicitly turns the requirement off. These
     * projections are not about that gate, so it is switched off here.
     */
    private function twoFactorOff(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => 'false', 'updated_at' => now()],
        );
    }

    /** Configures a fully elapsed election window → phase voting_closed. */
    private function closeVoting(): void
    {
        $this->window([
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->subDays(2),
            'voting_opens_at' => now()->subDay(),
            'voting_closes_at' => now()->subHour(),
        ]);
    }

    private function window(array $dates): void
    {
        foreach ($dates as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
    }

    /**
     * An approved candidate on the national-tier position of the given slug,
     * optionally finalised as its elected winner, plus its ledger votes.
     */
    private function makeCandidate(
        string $ref,
        string $name,
        string $positionSlug,
        int $votes,
        bool $elected = false,
    ): Candidate {
        $candidate = Candidate::create([
            'user_id' => $this->makeUser(['name' => $name, 'role' => 'student'])->id,
            'position_id' => DB::table('positions')->where('slug', $positionSlug)->value('id'),
            'candidate_ref' => $ref,
            'approval_status' => 'approved',
            'election_status' => $elected ? 'elected' : 'pending',
            'winner_rank' => $elected ? 1 : null,
            'vote_total' => $votes,
        ]);

        for ($i = 0; $i < $votes; $i++) {
            $this->ledgerRow($ref, $positionSlug, $i);
        }

        return $candidate;
    }

    private function ledgerRow(string $ref, string $positionSlug, int $sequence): void
    {
        DB::table('vote_ledger')->insert([
            'position_key' => $positionSlug,
            'candidate_ref' => $ref,
            'receipt_hmac' => hash('sha256', $ref.'-'.$sequence),
            'ledger_sequence' => $sequence,
        ]);
    }

    /** The school-year label TermArchive would derive for the current date. */
    private function expectedTermLabel(): string
    {
        $now = now();
        $start = $now->month <= 5 ? $now->year - 1 : $now->year;

        return "SY {$start}-".($start + 1);
    }

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

    /** Minimal schema for the projections exercised here. */
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
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('voted_at')->nullable();
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
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('positions')->insert([
            'slug' => 'president',
            'label' => 'President',
            'tier' => 'national',
            'seat_count' => 1,
            'sort_order' => 10,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('party_list')->nullable();
            $table->string('position_key')->nullable();
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
            $table->unsignedBigInteger('ledger_sequence')->nullable();
            $table->timestamp('recorded_at')->useCurrent();
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
