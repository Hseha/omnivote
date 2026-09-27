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
 * L-4 — unbounded request payloads.
 *
 * These endpoints are all authenticated, so the blast radius is limited to the
 * actor's own data. The finding is still real: the panel and the mobile app
 * share these routes, a compromised or buggy client can write arbitrarily large
 * JSON into a column that is expected to hold a handful of short strings, and
 * there is no bound anywhere between the request and the database.
 *
 * L-3 is covered here too because it is the same class of "the response says
 * more than it should": turnout aggregate counts were disclosed to every
 * student while polls were still open, which is a live turnout-exposure signal.
 */
class InputBoundsAndTurnoutDisclosureTest extends TestCase
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
        // The ballot-draft route is gated to the voting phase; results and
        // turnout each move the window themselves where they need it.
        $this->openWindow('voting_open');
    }

    /* ------------------------------------------------------------------ */
    /* L-4a  announcement body                                             */
    /* ------------------------------------------------------------------ */

    public function test_an_oversized_announcement_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->postJson('/api/admin/ssg/announcements', [
                'title' => 'Polls are open',
                'body' => str_repeat('a', 20_001),
            ])
            ->assertStatus(422);

        $this->assertSame(0, DB::table('announcements')->count());

        // The boundary itself is accepted, so the cap is not off by one.
        $this->actingAs($admin)
            ->postJson('/api/admin/ssg/announcements', [
                'title' => 'Polls are open',
                'body' => str_repeat('a', 20_000),
            ])
            ->assertStatus(201);

        $this->assertSame(1, DB::table('announcements')->count());
    }

    /* ------------------------------------------------------------------ */
    /* L-4b  ballot draft selections                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_draft_with_too_many_positions_is_rejected(): void
    {
        $student = $this->makeUser();
        $selections = [];

        for ($i = 0; $i < 41; $i++) {
            $selections['position_'.$i] = ['ref-'.$i];
        }

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/ballot/me', ['selections' => $selections])
            ->assertStatus(422);

        $this->assertNull($this->draftSelections($student), 'a rejected draft must not be persisted');
    }

    public function test_a_draft_with_too_many_refs_in_one_position_is_rejected(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/ballot/me', [
                'selections' => ['president' => array_map(fn ($i) => 'ref-'.$i, range(1, 21))],
            ])
            ->assertStatus(422);

        $this->assertNull($this->draftSelections($student));
    }

    public function test_a_draft_with_a_malformed_position_key_is_rejected(): void
    {
        $student = $this->makeUser();

        // Uppercase, spaces and SQL-ish characters never match a real slug.
        $this->actingAs($student, 'sanctum')
            ->putJson('/api/ballot/me', [
                'selections' => ['President' => ['ref-1']],
            ])
            ->assertStatus(422);

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/ballot/me', [
                'selections' => ["president' OR 1=1--" => ['ref-1']],
            ])
            ->assertStatus(422);

        $this->assertNull($this->draftSelections($student));
    }

    public function test_a_normal_draft_still_round_trips(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student, 'sanctum')
            ->putJson('/api/ballot/me', [
                'selections' => ['president' => ['ref-ana'], 'senator' => ['ref-ben', 'ref-cai']],
            ])
            ->assertStatus(200);

        $this->assertSame(
            ['president' => ['ref-ana'], 'senator' => ['ref-ben', 'ref-cai']],
            $this->draftSelections($student)
        );
    }

    /* ------------------------------------------------------------------ */
    /* L-4c  settings key allow-list                                       */
    /* ------------------------------------------------------------------ */

    public function test_a_known_setting_key_is_still_writable(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson('/api/admin/settings/security', ['passwordMinLength' => '10'])
            ->assertStatus(200);

        $this->assertSame('10', $this->settingValue('admin.settings.security.passwordMinLength'));
    }

    public function test_an_unknown_setting_key_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson('/api/admin/settings/security', [
                'definitelyNotASetting' => 'x',
            ])
            ->assertStatus(422);

        $this->assertNull($this->settingValue('admin.settings.security.definitelyNotASetting'));
    }

    public function test_prototype_pollution_keys_are_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        foreach (['__proto__', 'constructor', 'prototype'] as $key) {
            $this->actingAs($admin)
                ->patchJson('/api/admin/settings/security', [$key => '{"polluted":true}'])
                ->assertStatus(422);
        }
    }

    public function test_an_oversized_setting_value_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->actingAs($admin)
            ->patchJson('/api/admin/settings/security', [
                'passwordMinLength' => str_repeat('9', 4097),
            ])
            ->assertStatus(422);

        $this->assertNull($this->settingValue('admin.settings.security.passwordMinLength'));
    }

    public function test_too_many_setting_keys_in_one_request_are_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $payload = [];
        for ($i = 0; $i < 65; $i++) {
            $payload['key'.$i] = 'value';
        }

        $this->actingAs($admin)
            ->patchJson('/api/admin/settings/security', $payload)
            ->assertStatus(422);

        $this->assertSame(0, DB::table('election_settings')->where('key', 'like', 'key%')->count());
    }

    /* ------------------------------------------------------------------ */
    /* L-4d  receipt token                                                 */
    /* ------------------------------------------------------------------ */

    public function test_an_oversized_receipt_token_is_rejected(): void
    {
        $student = $this->makeUser();
        $this->openWindow('voting_closed');

        // The token arrives in the body, so an oversized one has to be refused
        // before it is hashed and compared against the ledger.
        $this->actingAs($student, 'sanctum')
            ->postJson('/api/results/verify', [
                'receipt_token' => str_repeat('a', 129),
            ])
            ->assertStatus(422);
    }

    public function test_a_reasonable_receipt_token_is_accepted(): void
    {
        $student = $this->makeUser();
        $this->openWindow('voting_closed');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/results/verify', [
                'receipt_token' => Str::random(32),
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['counted']);
    }

    /* ------------------------------------------------------------------ */
    /* L-3  turnout disclosure                                             */
    /* ------------------------------------------------------------------ */

    public function test_turnout_aggregates_are_withheld_while_polls_are_open(): void
    {
        $student = $this->makeUser();
        $this->openWindow('voting_open');

        // Two hundred registered students, one of whom has already voted.
        $this->seedVoters(200);
        DB::table('users')->where('id', $student->id)->update(['has_voted' => true, 'voted_at' => now()]);

        // `actingAs()` hands the container this exact instance, and it was
        // loaded *before* the raw UPDATE above, so it would still report the
        // pre-update attributes. Reload it.
        $turnout = $this->actingAs($student->fresh(), 'sanctum')
            ->getJson('/api/registration/me')
            ->assertStatus(200)
            ->json('turnout');

        // The student may always see their OWN participation.
        $this->assertTrue($turnout['has_voted']);
        $this->assertNotNull($turnout['voted_at']);

        // But nothing that reveals how the rest of the school is doing. The
        // keys stay present but null, because the Flutter Turnout model reads a
        // count and tolerates null; the security property is the absence of a
        // number, not of a key.
        $this->assertNull($turnout['registered_students']);
        $this->assertNull($turnout['total_students']);
        $this->assertNull($turnout['actual_ballots_cast']);
    }

    public function test_turnout_aggregates_are_published_once_polls_close(): void
    {
        $student = $this->makeUser();
        $this->openWindow('voting_closed');

        $this->seedVoters(200);
        DB::table('users')->where('id', $student->id)->update(['has_voted' => true, 'voted_at' => now()]);

        // `actingAs()` hands the container this exact instance, and it was
        // loaded *before* the raw UPDATE above, so it would still report the
        // pre-update attributes. Reload it.
        $turnout = $this->actingAs($student->fresh(), 'sanctum')
            ->getJson('/api/registration/me')
            ->assertStatus(200)
            ->json('turnout');

        $this->assertTrue($turnout['has_voted']);
        $this->assertSame(201, $turnout['total_students']);
        $this->assertSame(201, $turnout['registered_students']);
        $this->assertSame(1, $turnout['actual_ballots_cast']);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    /** Decoded selections, or null when no draft row was ever created. */
    private function draftSelections(User $user): ?array
    {
        $raw = DB::table('ballot_drafts')->where('user_id', $user->id)->value('selections');

        return $raw === null ? null : json_decode($raw, true);
    }

    private function settingValue(string $key): ?string
    {
        return DB::table('election_settings')->where('key', $key)->value('value');
    }

    private function seedVoters(int $count): void
    {
        $rows = [];

        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'name' => 'Voter '.$i,
                'email' => 'voter'.$i.'@example.test',
                'password' => 'secret',
                'role' => 'student',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        DB::table('users')->insert($rows);
    }

    private function openWindow(string $name): void
    {
        $window = match ($name) {
            'voting_open' => [
                'registration_opens_at' => now()->subDays(3),
                'registration_closes_at' => now()->subDays(2),
                'voting_opens_at' => now()->subHour(),
                'voting_closes_at' => now()->addDay(),
            ],
            'voting_closed' => [
                'registration_opens_at' => now()->subDays(3),
                'registration_closes_at' => now()->subDays(2),
                'voting_opens_at' => now()->subDays(2),
                'voting_closes_at' => now()->subDay(),
            ],
        };

        foreach ($window as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
            );
        }
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

        Schema::create('vote_ledger', function (Blueprint $table) {
            $table->id();
            $table->string('position_key');
            $table->string('candidate_ref');
            $table->string('receipt_hmac', 64);
            $table->unsignedBigInteger('ledger_sequence')->nullable();
            $table->timestamps();
        });

        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->id();
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->string('course')->nullable();
            $table->string('year_level')->nullable();
            $table->string('activation_code_hash')->nullable();
            $table->timestamp('activation_code_issued_at')->nullable();
            $table->timestamp('activation_code_used_at')->nullable();
            $table->timestamps();
        });

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body');
            $table->boolean('is_published')->default(false);
            $table->timestamp('published_at')->nullable();
            $table->foreignId('user_id')->nullable();
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

        foreach (['registration', 'registration_closed', 'voting_open', 'voting_closed'] as $name) {
            DB::table('phases')->insert([
                'name' => $name,
                'description' => $name,
                'is_active' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
