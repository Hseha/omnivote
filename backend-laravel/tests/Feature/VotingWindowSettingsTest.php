<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TermArchive;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Settings → Voting Windows persistence and ordering rules.
 *
 * `termEndsAt` was validated by SettingsController::validateVotingWindow() and
 * read by TermArchive, but was never added to WRITABLE_KEYS['voting']. The
 * allow-list rejects a key that is neither allow-listed nor already stored, and
 * nothing else ever writes the row, so on a fresh install the React panel's
 * "Term Ends" card was refused with
 * `422 Setting 'termEndsAt' is not a recognised option.` — the whole
 * term-archive lifecycle was unreachable. These tests pin the key to the
 * allow-list and the ordering constraints the guided dialog relies on.
 */
class VotingWindowSettingsTest extends TestCase
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
    }

    public function test_the_term_end_date_is_writable(): void
    {
        $this->patchVoting(['termEndsAt' => '2027-05-31T15:59:00.000Z'])
            ->assertOk();

        $this->assertSame(
            '2027-05-31T15:59:00.000Z',
            $this->settingValue('admin.settings.voting.termEndsAt'),
        );
    }

    /* The quick-set chips on the panel hand the backend an ISO instant; the
       read-back is what drives TermArchive, so assert the whole loop. */
    public function test_a_saved_term_end_is_read_back_by_the_archive_lifecycle(): void
    {
        $this->patchVoting(['termEndsAt' => now()->addMonth()->toIso8601String()])
            ->assertOk();

        $endsAt = TermArchive::termEndsAt();
        $this->assertNotNull($endsAt);
        $this->assertFalse(TermArchive::due());
    }

    public function test_an_elapsed_term_end_is_reported_due(): void
    {
        $this->patchVoting(['termEndsAt' => now()->subDay()->toIso8601String()])
            ->assertOk();

        $this->assertTrue(TermArchive::due());
    }

    /* Clearing the chip row must be able to un-set the term, otherwise a
       mis-set date is only escapable by picking another date. */
    public function test_the_term_end_date_can_be_cleared(): void
    {
        $this->patchVoting(['termEndsAt' => '2027-05-31T15:59:00.000Z'])->assertOk();

        $this->patchVoting(['termEndsAt' => ''])->assertOk();

        $this->assertNull($this->settingValue('admin.settings.voting.termEndsAt'));
        $this->assertNull(TermArchive::termEndsAt());
    }

    public function test_a_term_end_before_voting_closes_is_rejected(): void
    {
        $this->patchVoting([
            'votingStart' => now()->addDay()->toIso8601String(),
            'votingEnd' => now()->addDays(3)->toIso8601String(),
            'termEndsAt' => now()->addDays(2)->toIso8601String(),
        ])->assertStatus(422)->assertJson([
            'message' => 'Term Ends must be after Voting Closes.',
        ]);

        $this->assertNull($this->settingValue('admin.settings.voting.termEndsAt'));
    }

    public function test_a_term_end_before_registration_opens_is_rejected(): void
    {
        $this->patchVoting([
            'registrationStart' => now()->addDay()->toIso8601String(),
            'termEndsAt' => now()->toIso8601String(),
        ])->assertStatus(422)->assertJson([
            'message' => 'Term Ends must be after Registration Opens.',
        ]);
    }

    /* An unparsable instant used to persist silently and then read back as
       "Not set", so a typo looked like a value the admin never saved. */
    public function test_a_malformed_term_end_date_is_rejected_rather_than_stored(): void
    {
        $this->patchVoting(['termEndsAt' => 'not-a-date'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Term Ends is not a valid date and time.']);

        $this->assertNull($this->settingValue('admin.settings.voting.termEndsAt'));
    }

    public function test_a_malformed_voting_window_date_is_rejected_rather_than_stored(): void
    {
        $this->patchVoting(['votingEnd' => '31/05/2027 9pm'])
            ->assertStatus(422)
            ->assertJson(['message' => 'Voting Closes is not a valid date and time.']);

        $this->assertNull($this->settingValue('admin.settings.voting.votingEnd'));
    }

    private function patchVoting(array $payload)
    {
        return $this->actingAs($this->makeAdmin())
            ->patchJson('/api/admin/settings/voting', $payload);
    }

    private function makeAdmin(): User
    {
        return User::create([
            'name' => 'Test Admin',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret-password',
            'role' => 'admin',
            'is_active' => true,
        ]);
    }

    private function settingValue(string $key): ?string
    {
        return DB::table('election_settings')->where('key', $key)->value('value');
    }

    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->default('student');
            $table->boolean('is_active')->default(true);
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

        Schema::create('candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->string('name');
            $table->boolean('certified_winner')->default(false);
            $table->timestamp('certified_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('term_label', 32)->nullable();
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

        DB::table('phases')->insert([
            ['name' => 'registration', 'description' => 'Registration', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'registration_closed', 'description' => 'Registration closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_open', 'description' => 'Voting open', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_closed', 'description' => 'Voting closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
