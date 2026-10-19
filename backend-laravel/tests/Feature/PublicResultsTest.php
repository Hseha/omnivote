<?php

namespace Tests\Feature;

use App\Http\Controllers\ResultsController;
use App\Models\Announcement;
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
 *   GET /api/results                 — per-position tallies (voting_closed
 *                                      AND results_published only)
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
        $this->publishResults();

        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, party: 'ASLE');
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

        // Party + tier ride along for the admin filter dropdowns and the
        // student app's National/Provincial grouping.
        $this->assertSame('ASLE', $tally['candidates'][0]['party_name']);
        $this->assertSame('—', $tally['candidates'][1]['party_name']);
        $this->assertSame('national', $tally['candidates'][0]['tier']);

        $this->assertSame('Ben', $tally['candidates'][1]['name']);
        $this->assertSame(2, $tally['candidates'][1]['votes']);
    }

    public function test_results_survive_ledger_rows_whose_candidate_or_position_is_gone(): void
    {
        $this->closeVoting();
        $this->publishResults();

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

    public function test_results_are_split_into_one_group_per_college_and_year(): void
    {
        $this->closeVoting();
        $this->publishResults();

        // Two nominees in the same contested seat (CCS, year 1) and one in
        // another (COE, year 1): the tallies must not bleed across groups.
        $this->makeCandidate('ref-ccs1-a', 'Ana', 'year_level_representative', votes: 3, department: 'CCS', yearLevel: '1');
        $this->makeCandidate('ref-ccs1-b', 'Ben', 'year_level_representative', votes: 1, department: 'CCS', yearLevel: '1');
        $this->makeCandidate('ref-coe1-a', 'Cara', 'year_level_representative', votes: 2, department: 'COE', yearLevel: '1');

        $groups = collect($this->results()->json('results'))
            ->where('position_key', 'year_level_representative')
            ->values();

        $this->assertCount(2, $groups);

        // Each group is labelled with its college and year, and carries only its
        // own candidates, highest first.
        $byLabel = $groups->keyBy('position_label');

        $this->assertTrue($byLabel->has('Year Level Representative — CCS · Year 1'));
        $this->assertSame(
            ['Ana', 'Ben'],
            collect($byLabel['Year Level Representative — CCS · Year 1']['candidates'])->pluck('name')->all(),
        );

        $this->assertTrue($byLabel->has('Year Level Representative — COE · Year 1'));
        $this->assertSame(
            ['Cara'],
            collect($byLabel['Year Level Representative — COE · Year 1']['candidates'])->pluck('name')->all(),
        );
    }

    public function test_public_results_are_refused_until_voting_closes(): void
    {
        // No election window configured at all: nothing is published yet.
        $response = $this->asStudent()->getJson('/api/results');

        $response->assertStatus(403)->assertJsonPath('phase', null);
    }

    public function test_public_results_are_refused_until_results_are_published(): void
    {
        // Polls closed, tallies exist, but nobody has finalized yet: the
        // student endpoint must not leak provisional numbers.
        $this->closeVoting();
        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3);

        $this->asStudent()
            ->getJson('/api/results')
            ->assertStatus(403)
            ->assertJsonPath('code', 'results_not_published');

        // Finalize Winners raises the flag (ResultsPublisher::publish) and the
        // same endpoint opens up.
        $this->publishResults();

        $this->results()->assertOk();
    }

    public function test_result_groups_are_returned_in_ballot_order_not_alphabetical(): void
    {
        $this->closeVoting();
        $this->publishResults();

        // Ballot order is president (national, 10) → year_level_representative
        // (national, 20) → governor (provincial, 5). The old alphabetical
        // position_key order rendered governor FIRST and buried the
        // presidency — both the student app and the admin panel render the
        // list as-is, so this pins the shared projection.
        DB::table('positions')->insert([
            'slug' => 'governor',
            'label' => 'Governor',
            'tier' => 'provincial',
            'scope_type' => 'global',
            'scope_value' => null,
            'scope_type_2' => null,
            'scope_value_2' => null,
            'seat_count' => 1,
            'sort_order' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeCandidate('ref-gov', 'Gov', 'governor', 1);
        $this->makeCandidate('ref-pre', 'Pre', 'president', 2);
        $this->makeCandidate('ref-ccs', 'Ccs', 'year_level_representative', 3, department: 'CCS', yearLevel: '1');
        $this->makeCandidate('ref-abe', 'Abe', 'year_level_representative', 4, department: 'ABE', yearLevel: '1');

        $groups = collect($this->results()->json('results'));

        $this->assertSame(
            ['president', 'year_level_representative', 'year_level_representative', 'governor'],
            $groups->pluck('position_key')->all(),
        );

        // Within the scoped position, electorate groups are stable and sorted
        // by scope label (ABE before CCS) — not ledger-scan order.
        $this->assertSame(
            [null, 'ABE · Year 1', 'CCS · Year 1', null],
            $groups->pluck('scope_label')->all(),
        );

        // The sort keys must never leak into the payload.
        $this->assertArrayNotHasKey('_tier', $groups->first());
        $this->assertArrayNotHasKey('_sort_order', $groups->first());
    }

    public function test_equal_vote_candidates_are_ordered_deterministically(): void
    {
        $this->closeVoting();
        $this->publishResults();

        // Equal tallies must not flip between refreshes: the ledger tiebreak
        // orders by candidate_ref, which lands Amy before Zoe here.
        $this->makeCandidate('ref-zoe', 'Zoe', 'president', 2);
        $this->makeCandidate('ref-amy', 'Amy', 'president', 2);

        $this->assertSame(
            ['Amy', 'Zoe'],
            collect($this->results()->json('results.0.candidates'))->pluck('name')->all(),
        );
    }

    public function test_the_results_announcement_is_a_summary_and_keeps_the_tied_seats(): void
    {
        $this->closeVoting();

        // One certified winner...
        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);
        // ...and a seat that is still tied between two candidates.
        $this->makeCandidate('ref-bob', 'Bob', 'governor', votes: 2, status: 'tied');
        $this->makeCandidate('ref-cara', 'Cara', 'governor', votes: 2, status: 'tied');

        $announcement = app(ResultsController::class)
            ->refreshResultsAnnouncement();

        $this->assertNotNull($announcement);
        $this->assertSame('Official Election Results', $announcement->title);

        $body = $announcement->body;

        // It says the results are out, and where to read them.
        $this->assertStringContainsString('The election results are in.', $body);
        $this->assertStringContainsString('Open the Results tab', $body);

        // The seats that are NOT decided are the one thing the results screen
        // cannot imply, so they are the only thing enumerated.
        $this->assertStringContainsString('Still awaiting a decision:', $body);
        $this->assertStringContainsString('Bob, Cara', $body);
        $this->assertStringContainsString('awaiting decision', $body);

        // The winners are NOT listed here — the results screen does that, per
        // position. A real election ran to sixty-three bullets and five
        // thousand characters, which read as a wall of text everywhere.
        $this->assertStringNotContainsString('Ana', $body);
        $this->assertStringNotContainsString('President:', $body);

        // A notice, not a data dump.
        $this->assertLessThan(800, strlen($body));
    }

    public function test_the_results_announcement_is_rebuilt_in_place_not_duplicated(): void
    {
        $this->closeVoting();

        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);

        $controller = app(ResultsController::class);
        $first = $controller->refreshResultsAnnouncement();
        $second = $controller->refreshResultsAnnouncement();

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, Announcement::count());
    }

    public function test_the_results_announcement_does_not_claim_results_are_in_when_nothing_is_decided(): void
    {
        $this->closeVoting();

        // A tie with no certified winner anywhere: nothing has been decided.
        $this->makeCandidate('ref-bob', 'Bob', 'president', votes: 2, status: 'tied');

        $announcement = app(ResultsController::class)
            ->refreshResultsAnnouncement();

        $this->assertNotNull($announcement);
        $this->assertStringNotContainsString('The election results are in.', $announcement->body);
        $this->assertStringContainsString('still being decided', $announcement->body);
        $this->assertStringContainsString('Bob', $announcement->body);
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

    public function test_ssg_officer_roster_seats_national_winners_before_provincial(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        // A provincial seat whose sort_order is LOWER than a national one: the
        // tier partition — not the raw sort_order — decides seating order, so
        // President (sort 10) must still precede Governor (sort 5).
        DB::table('positions')->insert([
            'slug' => 'governor',
            'label' => 'Governor',
            'tier' => 'provincial',
            'scope_type' => 'global',
            'scope_value' => null,
            'scope_type_2' => null,
            'scope_value_2' => null,
            'seat_count' => 1,
            'sort_order' => 5,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->makeCandidate('ref-roi', 'ROI', 'governor', votes: 5, elected: true);
        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);

        $this->asAdmin()
            ->getJson('/api/admin/ssg/officers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.position_key', 'president')
            ->assertJsonPath('data.0.tier', 'national')
            ->assertJsonPath('data.1.position_key', 'governor')
            ->assertJsonPath('data.1.tier', 'provincial')
            ->assertJsonPath('data.1.position_label', 'Governor');
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

    public function test_ssg_officer_roster_exposes_the_position_electorate_hierarchy(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        // A global seat: one position, no electorate.
        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);

        // A scoped seat: one position contested per (department, year level).
        $this->makeCandidate(
            'ref-bob',
            'Bob',
            'year_level_representative',
            votes: 2,
            elected: true,
            department: 'Computer Studies',
            yearLevel: '3',
        );

        $this->asAdmin()
            ->getJson('/api/admin/ssg/officers')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // The global seat carries the base label alone, with no electorate.
            ->assertJsonPath('data.0.position_key', 'president')
            ->assertJsonPath('data.0.position_base_label', 'President')
            ->assertJsonPath('data.0.position_scope', null)
            ->assertJsonPath('data.0.position_scoped', false)
            ->assertJsonPath('data.0.is_elected', true)
            // The scoped seat returns the base position and the electorate
            // separately, so the console nests the winner under the position
            // instead of repeating the position name on every row.
            ->assertJsonPath('data.1.position_key', 'year_level_representative')
            ->assertJsonPath('data.1.position_base_label', 'Year Level Representative')
            ->assertJsonPath('data.1.position_scope', 'Computer Studies · Year 3')
            ->assertJsonPath('data.1.position_scoped', true)
            ->assertJsonPath('data.1.is_elected', true);
    }

    public function test_ssg_officer_roster_marks_unfinalized_rows_as_provisional(): void
    {
        $this->closeVoting();
        $this->twoFactorOff();

        // No finalize has run, so the roster falls back to the raw vote
        // leaders. Those rows are NOT confirmed winners, and the console
        // labels them as provisional rather than "Elected".
        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 2);

        $this->asAdmin()
            ->getJson('/api/admin/ssg/officers')
            ->assertOk()
            ->assertJsonPath('data.0.name', 'Ana')
            ->assertJsonPath('data.0.is_elected', false);
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

    public function test_ssg_president_mobile_roster_matches_the_panel_projection(): void
    {
        $this->closeVoting();

        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);

        // The Flutter President dashboard reads the same projection off a
        // Sanctum bearer token, where the panel's session-guarded
        // `/api/admin/ssg/officers` is unreachable.
        $this->asPresident()
            ->getJson('/api/ssg/officers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.position_key', 'president')
            ->assertJsonPath('data.0.name', 'Ana');
    }

    public function test_mobile_officer_roster_is_refused_for_plain_students(): void
    {
        $this->closeVoting();

        $this->makeCandidate('ref-ana', 'Ana', 'president', votes: 3, elected: true);

        // The mobile governance surface is scoped to the President role; a
        // student token must not read it.
        $this->asStudent()
            ->getJson('/api/ssg/officers')
            ->assertStatus(403);
    }

    public function test_mobile_officer_roster_waits_until_voting_closes(): void
    {
        // Window still open → the roster is not published yet.
        $this->window([
            'registration_opens_at' => now()->subDays(2),
            'registration_closes_at' => now()->subDay(),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ]);

        $this->asPresident()
            ->getJson('/api/ssg/officers')
            ->assertStatus(403)
            ->assertJsonPath('message', 'The officer roster is available after voting is closed.');
    }

    public function test_ssg_president_can_restore_a_mobile_session(): void
    {
        // Cold-start restore hits /auth/me; the President role must resolve
        // there too, or the app discards the token and appears signed out.
        $this->asPresident()
            ->getJson('/api/auth/me')
            ->assertOk();
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
        $this->publishResults();
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

    /** A request authenticated as an SSG President via the sanctum guard. */
    private function asPresident(): static
    {
        return $this->actingAs($this->makeUser(['role' => 'ssg_president']), 'sanctum');
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

    /** Raises the results_published flag the way Finalize Winners does. */
    private function publishResults(): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => 'results_published'],
            ['value' => '1', 'updated_at' => now(), 'created_at' => now()],
        );
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
     * An approved candidate on the position of the given slug (any tier),
     * optionally finalised as its elected winner, plus its ledger votes.
     */
    private function makeCandidate(
        string $ref,
        string $name,
        string $positionSlug,
        int $votes,
        bool $elected = false,
        ?string $department = null,
        ?string $yearLevel = null,
        ?string $party = null,
        ?string $status = null,
    ): Candidate {
        $candidate = Candidate::create([
            'user_id' => $this->makeUser([
                'name' => $name,
                'role' => 'student',
                'department' => $department,
                'year_level' => $yearLevel,
            ])->id,
            'position_id' => DB::table('positions')->where('slug', $positionSlug)->value('id'),
            'candidate_ref' => $ref,
            'party_name' => $party,
            'approval_status' => 'approved',
            'election_status' => $status ?? ($elected ? 'elected' : 'pending'),
            'winner_rank' => $status === 'elected' || ($status === null && $elected) ? 1 : null,
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

        // The auto-generated results announcement lives here. Only the
        // columns the generator and the projections read are needed.
        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->index(['published_at', 'created_at']);
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier')->default('national');
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->string('scope_type_2', 32)->nullable();
            $table->string('scope_value_2')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('positions')->insert([
            [
                'slug' => 'president',
                'label' => 'President',
                'tier' => 'national',
                'scope_type' => 'global',
                'scope_value' => null,
                'scope_type_2' => null,
                'scope_value_2' => null,
                'seat_count' => 1,
                'sort_order' => 10,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'slug' => 'year_level_representative',
                'label' => 'Year Level Representative',
                'tier' => 'national',
                'scope_type' => 'department',
                'scope_value' => null,
                'scope_type_2' => 'year_level',
                'scope_value_2' => null,
                'seat_count' => 1,
                'sort_order' => 20,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('party_list')->nullable();
            $table->string('party_name')->nullable();
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
