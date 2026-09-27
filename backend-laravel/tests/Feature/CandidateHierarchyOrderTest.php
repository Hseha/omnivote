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
 * The admin Candidates table is a review queue, and the reviewer reads it
 * against the ballot order: President first, down through the council, then the
 * provincial slate from Governor down. `positions.sort_order` already encodes
 * exactly that (10, 20, 30 …, per tier) — the endpoint just wasn't using it.
 *
 * The ordering has to happen in SQL rather than in the React table: the list is
 * paginated, so sorting the 20 rows of the current page client-side would leave
 * every other page in submission order and split a position's candidates across
 * page boundaries.
 */
class CandidateHierarchyOrderTest extends TestCase
{
    private ?User $admin = null;

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

    public function test_national_candidates_follow_ballot_order_not_submission_order(): void
    {
        // Deliberately submitted in reverse: the Secretary applies first, the
        // President last, which is exactly what `created_at DESC` would show.
        $this->makeCandidate('ref-sec', 'Ana Secretary', 'secretary');
        $this->makeCandidate('ref-vp', 'Bea Vice', 'vice_president');
        $this->makeCandidate('ref-pres', 'Cec President', 'president');

        $this->assertSame(
            ['President', 'Vice President', 'Secretary'],
            $this->positionsInResponse(),
        );
    }

    public function test_provincial_candidates_run_governor_down(): void
    {
        $this->makeCandidate('ref-psec', 'Provincial Sec', 'provincial_secretary');
        $this->makeCandidate('ref-gov', 'Gov Governor', 'governor');
        $this->makeCandidate('ref-vgov', 'Vice Gov', 'vice_governor');

        $this->assertSame(
            ['Governor', 'Vice Governor', 'Provincial Secretary'],
            $this->positionsInResponse(),
        );
    }

    public function test_the_national_council_is_listed_before_the_provincial_slate(): void
    {
        $this->makeCandidate('ref-gov', 'Gov Governor', 'governor');
        $this->makeCandidate('ref-sec', 'Ana Secretary', 'secretary');

        $this->assertSame(
            ['Secretary', 'Governor'],
            $this->positionsInResponse(),
        );
    }

    /* sort_order restarts at 10 for each tier, so a bare sort_order would
       interleave President / Governor / Vice President. The tier must lead. */
    public function test_tier_leads_so_sort_order_cannot_interleave_the_slates(): void
    {
        $this->makeCandidate('ref-gov', 'Gov', 'governor');
        $this->makeCandidate('ref-vp', 'VP', 'vice_president');
        $this->makeCandidate('ref-pres', 'Pres', 'president');
        $this->makeCandidate('ref-vgov', 'V-Gov', 'vice_governor');

        $this->assertSame(
            ['President', 'Vice President', 'Governor', 'Vice Governor'],
            $this->positionsInResponse(),
        );
    }

    /* position_id is nullable, and a candidate with no position has no place in
       the hierarchy — it belongs at the end, not sorted to the front. */
    public function test_candidates_without_a_position_are_listed_last(): void
    {
        $this->makeCandidate('ref-pres', 'Cec President', 'president');
        $this->makeCandidate('ref-none', 'No Position', null);
        $this->makeCandidate('ref-sec', 'Ana Secretary', 'secretary');

        $positions = $this->positionsInResponse();

        $this->assertSame(['President', 'Secretary', null], $positions);
    }

    /*
     * The regression that motivates doing this server-side at all: with a page
     * size smaller than the number of candidates, a client-side sort would show
     * page 1 correctly and page 2 scrambled.
     */
    public function test_the_order_holds_across_page_boundaries(): void
    {
        // 3 national + 2 provincial candidates, page size 2 → 3 pages.
        foreach (['president', 'vice_president', 'secretary', 'governor', 'vice_governor'] as $slug) {
            $this->makeCandidate('ref-'.$slug, 'Cand '.$slug, $slug);
        }

        $seen = [];
        $pageSizes = [];
        for ($page = 1; $page <= 3; $page++) {
            $response = $this->asAdmin()
                ->getJson('/api/admin/candidates?per_page=2&page='.$page)
                ->assertOk();

            // 5 candidates at per_page=2 is [2, 2, 1] — only the first two pages
            // are full, so asserting "every page holds per_page rows" would fail
            // on the remainder page rather than on any ordering bug.
            $pageSizes[] = count($response->json('data'));
            foreach ($response->json('data') as $row) {
                $seen[] = $row['position'];
            }
        }

        $this->assertSame([2, 2, 1], $pageSizes, 'paging should follow the total, not a fixed fill');

        $this->assertSame(
            ['President', 'Vice President', 'Secretary', 'Governor', 'Vice Governor'],
            $seen,
        );
    }

    public function test_the_tier_filter_still_narrows_the_hierarchy(): void
    {
        $this->makeCandidate('ref-pres', 'Cec President', 'president');
        $this->makeCandidate('ref-sec', 'Ana Secretary', 'secretary');
        $this->makeCandidate('ref-gov', 'Gov Governor', 'governor');

        $response = $this->asAdmin()
            ->getJson('/api/admin/candidates?tier=provincial')
            ->assertOk();

        $this->assertSame(['Governor'], array_column($response->json('data'), 'position'));
    }

    private function positionsInResponse(): array
    {
        $response = $this->asAdmin()->getJson('/api/admin/candidates')->assertOk();

        return array_column($response->json('data'), 'position');
    }

    private function makeCandidate(string $ref, string $name, ?string $positionSlug): Candidate
    {
        return Candidate::create([
            'user_id' => $this->makeUser(['name' => $name])->id,
            'position_id' => $positionSlug ? $this->positionId($positionSlug) : null,
            'candidate_ref' => $ref,
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
        ], $attributes));
    }

    private function asAdmin(): static
    {
        // Memoised: the pagination test calls this once per page, and
        // users.email is unique.
        $this->admin ??= $this->makeUser(['email' => 'admin@example.test', 'role' => 'admin']);

        return $this->actingAs($this->admin, 'web')
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
            $table->string('course')->nullable();
            $table->boolean('is_active')->default(true);
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

        // Mirrors the real seed: sort_order restarts at 10 for each tier.
        $seed = [
            ['president', 'President', 'national', 10],
            ['vice_president', 'Vice President', 'national', 20],
            ['secretary', 'Secretary', 'national', 30],
            ['governor', 'Governor', 'provincial', 10],
            ['vice_governor', 'Vice Governor', 'provincial', 20],
            ['provincial_secretary', 'Provincial Secretary', 'provincial', 30],
        ];
        foreach ($seed as [$slug, $label, $tier, $order]) {
            DB::table('positions')->insert([
                'slug' => $slug,
                'label' => $label,
                'tier' => $tier,
                'seat_count' => 1,
                'sort_order' => $order,
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

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('position_id')->nullable();
            $table->string('candidate_ref', 36)->nullable()->unique();
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
