<?php

namespace Tests\Feature;

use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\RegistrarCode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * M-5 — self-registration must require an out-of-band secret, not just a
 * student ID that is printed on a public class list.
 *
 * The endpoint used to accept any `student_id` present in the eligibility feed
 * plus any unused email address, provision an account, and return a bearer
 * token. Anyone who knew a classmate's ID could therefore claim that identity
 * and vote as them. Two independent gates now apply: a registrar-issued
 * activation code, and a Settings toggle that is OFF by default.
 */
class SelfRegistrationActivationCodeTest extends TestCase
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
        RateLimiter::clear('register-ip:127.0.0.1');

        $this->createSchema();
        $this->openPhase('registration');
    }

    /* ------------------------------------------------------------------ */
    /* Gate 2: the default-off toggle                                      */
    /* ------------------------------------------------------------------ */

    public function test_self_registration_is_disabled_by_default(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1000', 'full_name' => 'Ana Reyes']);
        $code = RegistrarCode::issue($import);

        // No `selfRegistrationEnabled` setting at all.
        $this->setting('admin.settings.security.selfRegistrationEnabled', null);

        $this->postJson('/api/auth/register', $this->validPayload($import, $code))
            ->assertStatus(403)
            ->assertJsonPath('message', 'Self-registration is disabled. Please ask the registrar to issue your account.');

        $this->assertSame(0, User::count(), 'a disabled endpoint must not create anything');
    }

    public function test_self_registration_works_once_an_admin_enables_it(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1001', 'full_name' => 'Ben Cruz']);
        $code = RegistrarCode::issue($import);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        $response = $this->postJson('/api/auth/register', $this->validPayload($import, $code))
            ->assertStatus(201);

        $this->assertNotEmpty($response->json('token'));

        $user = User::where('student_id', '2026-1001')->firstOrFail();
        $this->assertTrue(Hash::check('Str0ngPassphrase', $user->password));
        $this->assertSame('student', $user->role);
    }

    /* ------------------------------------------------------------------ */
    /* Gate 1: the activation code                                        */
    /* ------------------------------------------------------------------ */

    public function test_a_known_student_id_alone_no_longer_claims_the_identity(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1002', 'full_name' => 'Cara Diaz']);
        $code = RegistrarCode::issue($import);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        // The attacker knows the student ID (public class-list data) and supplies
        // their own unused email — but no code.
        $this->postJson('/api/auth/register', [
            'student_id' => '2026-1002',
            'activation_code' => 'GUESS001',
            'name' => 'Mallory Attacker',
            'email' => 'mallory@attacker.test',
            'password' => 'Str0ngPassphrase',
            'password_confirmation' => 'Str0ngPassphrase',
        ])->assertStatus(422);

        $this->assertSame(0, User::count(), 'no account may be created without the code');
    }

    public function test_a_wrong_code_is_refused_even_with_a_valid_student_id(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1003', 'full_name' => 'Dan Eco']);
        RegistrarCode::issue($import);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        $this->postJson('/api/auth/register', $this->validPayload($import, 'WRONG999'))
            ->assertStatus(422);

        $this->assertSame(0, User::count());
    }

    public function test_the_activation_code_is_required_by_validation(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1004', 'full_name' => 'Eve Faye']);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        $this->postJson('/api/auth/register', [
            'student_id' => '2026-1004',
            'name' => 'Eve Faye',
            'email' => 'eve@example.test',
            'password' => 'Str0ngPassphrase',
            'password_confirmation' => 'Str0ngPassphrase',
        ])->assertStatus(422)
            ->assertJsonValidationErrors('activation_code');
    }

    public function test_a_code_cannot_be_replayed_to_claim_the_same_identity_twice(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1005', 'full_name' => 'Fox Gray']);
        $code = RegistrarCode::issue($import);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        $this->postJson('/api/auth/register', $this->validPayload($import, $code))->assertStatus(201);

        // Same slip, different email: the code was burned.
        $this->postJson('/api/auth/register', array_merge(
            $this->validPayload($import, $code),
            ['email' => 'another@attacker.test', 'name' => 'Mallory Attacker'],
        ))->assertStatus(422);

        $this->assertSame(1, User::count());
    }

    public function test_a_code_is_accepted_case_insensitively_and_tolerates_whitespace(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1006', 'full_name' => 'Hal Isla']);
        $code = RegistrarCode::issue($import);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        // A student reading a code off a printed slip: lowercase, with a stray
        // space, must still work.
        $this->postJson('/api/auth/register', $this->validPayload($import, ' '.strtolower($code).' '))
            ->assertStatus(201);
    }

    /* ------------------------------------------------------------------ */
    /* The code is a secret, not stored plaintext                           */
    /* ------------------------------------------------------------------ */

    public function test_the_plaintext_code_is_never_persisted(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1007', 'full_name' => 'Ivy Jones']);
        $code = RegistrarCode::issue($import);

        $fresh = $import->fresh();
        $this->assertNotNull($fresh->activation_code_hash);
        $this->assertNotSame($code, $fresh->activation_code_hash);
        $this->assertStringNotContainsString($code, $fresh->activation_code_hash);
        $this->assertNotNull($fresh->activation_code_issued_at);
        $this->assertTrue(Hash::check($code, $fresh->activation_code_hash));
    }

    public function test_a_row_with_no_issued_code_never_matches(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1008', 'full_name' => 'Jon Kent']);
        // Deliberately no RegistrarCode::issue() call.
        $this->assertFalse(RegistrarCode::matches($import, 'ANYTHING'));
        $this->assertFalse(RegistrarCode::matches($import, null));
    }

    public function test_issued_codes_are_unambiguous(): void
    {
        // The alphabet deliberately excludes I/O/0/1 so a code read aloud or
        // copied from a printed slip cannot be mistranscribed.
        for ($i = 0; $i < 50; $i++) {
            $this->assertMatchesRegularExpression(
                '/^[ABCDEFGHJKLMNPQRSTUVWXYZ23456789]{8}$/',
                RegistrarCode::generate()
            );
        }
    }

    /* ------------------------------------------------------------------ */
    /* Brute-force resistance                                              */
    /* ------------------------------------------------------------------ */

    public function test_failed_registrations_count_against_the_ip_budget(): void
    {
        $import = RegistrarImport::create(['student_id' => '2026-1009', 'full_name' => 'Kim Lake']);
        RegistrarCode::issue($import);

        $this->setting('admin.settings.security.selfRegistrationEnabled', 'true');

        // Guessing codes must consume the same per-IP budget as registering, or
        // the activation code becomes brute-forceable for free.
        for ($i = 0; $i < 10; $i++) {
            $this->postJson('/api/auth/register', $this->validPayload($import, 'WRONG'.$i))
                ->assertStatus(422);
        }

        // Budget exhausted: further guessing is refused before the comparison.
        $this->postJson('/api/auth/register', $this->validPayload($import, 'WRONG999'))
            ->assertStatus(429)
            ->assertHeader('Retry-After');

        $this->assertSame(0, User::count());
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */

    private function validPayload(RegistrarImport $import, string $code): array
    {
        return [
            'student_id' => $import->student_id,
            'activation_code' => $code,
            'name' => $import->full_name,
            'email' => Str::lower(Str::random(6)).'@example.test',
            'password' => 'Str0ngPassphrase',
            'password_confirmation' => 'Str0ngPassphrase',
        ];
    }

    private function setting(string $key, ?string $value): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'created_at' => now(), 'updated_at' => now()],
        );

        app()->forgetInstance(\App\Support\AppSettings::class);
    }

    /**
     * `Phase::current()` is a pure function of the configured timeline, not of
     * the `is_active` column, so the phase is set by writing the window that
     * implies it: registration open now, voting not yet.
     */
    private function openPhase(string $name): void
    {
        $window = match ($name) {
            'registration' => [
                'registration_opens_at' => now()->subDay(),
                'registration_closes_at' => now()->addDay(),
                'voting_opens_at' => now()->addDay(),
                'voting_closes_at' => now()->addDays(2),
            ],
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
                'is_active' => $name === 'registration',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('type');
            $table->string('severity')->nullable();
            $table->string('subject')->nullable();
            $table->text('body')->nullable();
            $table->string('action_url')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }
}
