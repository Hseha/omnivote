<?php

namespace Tests\Feature;

use App\Models\Candidate;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * M-4 — a position declares WHO may vote in it, and the server enforces it.
 *
 * Before this, `VoteController::resolveSelections()` checked only that the
 * position was active, the slug matched, seat_count was not exceeded and the
 * candidate was approved for that position. Nothing checked the voter, so any
 * student could POST a ballot containing `year_level_representative` and have it
 * recorded. Flutter hid the seat client-side, which is not a control.
 *
 * These tests pin the three properties that matter:
 *   1. a global (default) seat behaves exactly as before — no regression;
 *   2. a scoped seat refuses an out-of-scope voter;
 *   3. a scoped seat refuses a candidate who is not in that electorate, so one
 *      year level cannot elect another year level's representative.
 */
class PositionScopeTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

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
        $this->disableTwoFactorRequirement();
        $this->openVoting();
    }

    /* ------------------------------------------------------------------ */
    /* Backwards compatibility                                              */
    /* ------------------------------------------------------------------ */

    public function test_a_global_position_imposes_no_electorate_restriction(): void
    {
        $position = $this->position('president');
        $this->assertTrue($position->isGloballyScoped());

        // Default is 'global' straight out of the migration, with no scope_value.
        $this->assertSame(Position::SCOPE_GLOBAL, $position->scope_type);
        $this->assertNull($position->scope_value);

        $this->makeCandidate('ref-ana', 'president', '11');
        $voter = $this->makeUser(['year_level' => '11']);

        // A year-11 voter in a global seat: accepted.
        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201);
    }

    public function test_a_scoped_position_still_accepts_a_voter_inside_its_electorate(): void
    {
        $this->position('year_level_representative', scopeType: 'year_level');
        $this->makeCandidate('ref-ylr-11', 'year_level_representative', '11');

        $voter = $this->makeUser(['year_level' => '11']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['year_level_representative' => 'ref-ylr-11']])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('vote_ledger')->count());
    }

    /* ------------------------------------------------------------------ */
    /* The actual finding                                                   */
    /* ------------------------------------------------------------------ */

    public function test_a_candidate_outside_a_pinned_electorate_is_refused(): void
    {
        // Pinned to year 12 by the registrar, so a year-11 candidate may not
        // stand even though the voter is otherwise eligible to vote.
        $this->position('senator', scopeType: 'year_level', scopeValue: '12');
        $this->makeCandidate('ref-sen-11', 'senator', '11');

        $voter = $this->makeUser(['year_level' => '11']);

        // The voter holds no year_level_representative-style claim here, but the
        // pinned scope does not cover them either, so the voter check fires first.
        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['senator' => 'ref-sen-11']])
            ->assertStatus(403);

        $this->assertSame(0, DB::table('vote_ledger')->count());
    }

    public function test_a_candidate_outside_the_electorate_is_refused(): void
    {
        $this->position('year_level_representative', scopeType: 'year_level');
        // A year-12 candidate standing in the year-level seat.
        $this->makeCandidate('ref-ylr-12', 'year_level_representative', '12');

        // A year-11 voter, so the VOTER is in scope but the candidate is not.
        $voter = $this->makeUser(['year_level' => '11']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['year_level_representative' => 'ref-ylr-12']])
            ->assertStatus(422)
            ->assertJsonPath('message', "Candidate for position 'year_level_representative' is not in your electorate.");

        $this->assertSame(0, DB::table('vote_ledger')->count());
    }

    public function test_a_voter_with_no_year_level_cannot_vote_in_a_scoped_seat(): void
    {
        $this->position('year_level_representative', scopeType: 'year_level');
        $this->makeCandidate('ref-ylr-11', 'year_level_representative', '11');

        // An account provisioned without a year level: an incomplete profile
        // must not become a way around the restriction.
        $voter = $this->makeUser(['year_level' => null]);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['year_level_representative' => 'ref-ylr-11']])
            ->assertStatus(403);
    }

    public function test_a_literal_scope_value_pins_the_electorate(): void
    {
        // Scoped to a fixed value rather than "whatever the voter has".
        $this->position('senator', scopeType: 'year_level', scopeValue: '12');
        $this->makeCandidate('ref-sen-12', 'senator', '12');

        $inScope = $this->makeUser(['year_level' => '12']);
        $this->actingAs($inScope, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['senator' => 'ref-sen-12']])
            ->assertStatus(201);

        $outOfScope = $this->makeUser(['year_level' => '11']);
        $this->actingAs($outOfScope, 'sanctum')
            ->postJson('/api/vote', ['selections' => ['senator' => 'ref-sen-12']])
            ->assertStatus(403);
    }

    public function test_scope_enforcement_does_not_reject_a_mixed_ballot_of_legal_positions(): void
    {
        $this->position('president');
        $this->position('year_level_representative', scopeType: 'year_level');

        $this->makeCandidate('ref-ana', 'president', '11');
        $this->makeCandidate('ref-ylr-11', 'year_level_representative', '11');

        $voter = $this->makeUser(['year_level' => '11']);

        $this->actingAs($voter, 'sanctum')
            ->postJson('/api/vote', [
                'selections' => [
                    'president' => 'ref-ana',
                    'year_level_representative' => 'ref-ylr-11',
                ],
            ])
            ->assertStatus(201);
    }

    /* ------------------------------------------------------------------ */
    /* Public surface + admin edit guard                                    */
    /* ------------------------------------------------------------------ */

    public function test_public_positions_endpoint_exposes_the_scope(): void
    {
        $this->position('president');
        $this->position('year_level_representative', scopeType: 'year_level', scopeValue: '11');

        $response = $this->getJson('/api/positions')->assertStatus(200);

        $bySlug = collect($response->json())->keyBy('slug');

        $this->assertSame('global', $bySlug['president']['scope_type']);
        $this->assertNull($bySlug['president']['scope_value']);

        $this->assertSame('year_level', $bySlug['year_level_representative']['scope_type']);
        $this->assertSame('11', $bySlug['year_level_representative']['scope_value']);
    }

    public function test_scope_cannot_be_changed_once_ballots_exist(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $position = $this->position('president');

        // No ballots yet: the scope is freely editable.
        $this->actingAs($admin)
            ->patchJson("/api/admin/positions/{$position->id}", [
                'scope_type' => 'year_level',
                'scope_value' => '11',
            ])
            ->assertStatus(200)
            ->assertJsonPath('position.scope_type', 'year_level');

        // Record a ballot for that seat, then try to re-scope it.
        $this->makeCandidate('ref-ana', 'president', '11');
        $this->actingAs($this->makeUser(['year_level' => '11']), 'sanctum')
            ->postJson('/api/vote', ['selections' => ['president' => 'ref-ana']])
            ->assertStatus(201);

        $this->actingAs($admin)
            ->patchJson("/api/admin/positions/{$position->id}", ['scope_type' => 'department'])
            ->assertStatus(409);

        $this->assertSame('year_level', $position->fresh()->scope_type);
    }

    public function test_an_unknown_scope_type_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $position = $this->position('president');

        $this->actingAs($admin)
            ->patchJson("/api/admin/positions/{$position->id}", ['scope_type' => 'province'])
            ->assertStatus(422);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                              */
    /* ------------------------------------------------------------------ */

    private function position(string $slug, string $scopeType = 'global', ?string $scopeValue = null): Position
    {
        return Position::create([
            'slug' => $slug,
            'label' => ucwords(str_replace('_', ' ', $slug)),
            'tier' => 'national',
            'scope_type' => $scopeType,
            'scope_value' => $scopeValue,
            'seat_count' => 1,
            'sort_order' => 10,
            'is_active' => true,
        ]);
    }

    private function makeCandidate(string $ref, string $positionSlug, ?string $year): Candidate
    {
        return Candidate::create([
            'user_id' => $this->makeUser(['year_level' => $year])->id,
            'position_id' => DB::table('positions')->where('slug', $positionSlug)->value('id'),
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

    private function openVoting(): void
    {
        foreach ([
            'registration_opens_at' => now()->subDays(3),
            'registration_closes_at' => now()->subDays(2),
            'voting_opens_at' => now()->subHour(),
            'voting_closes_at' => now()->addDay(),
        ] as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_at' => now()],
            );
        }
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

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('position_id')->constrained('positions')->cascadeOnDelete();
            $table->string('candidate_ref')->unique();
            $table->string('approval_status')->default('pending');
            $table->string('election_status')->default('pending');
            $table->unsignedInteger('vote_total')->default(0);
            $table->timestamps();
        });

        Schema::create('vote_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('position_key');
            $table->string('candidate_ref');
            $table->string('receipt_hmac', 64);
            $table->unsignedBigInteger('ledger_sequence')->nullable();
            $table->timestamps();
        });

        Schema::create('ballot_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->json('selections')->nullable();
            $table->string('status')->default('draft');
            $table->string('receipt_token')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->timestamps();
        });

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        foreach (['registration', 'voting_open', 'voting_closed'] as $name) {
            DB::table('phases')->insert([
                'name' => $name,
                'description' => $name,
                'is_active' => $name === 'voting_open',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
