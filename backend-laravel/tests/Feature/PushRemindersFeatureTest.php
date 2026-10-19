<?php

namespace Tests\Feature;

use App\Jobs\SendPushToUsers;
use App\Models\DeviceToken;
use App\Models\User;
use App\Support\FcmPush;
use Illuminate\Console\Command;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Verifies the push-notifications-and-reminders feature left as a work-in-progress
 * checkpoint by earlier agent runs:
 *
 *   1. SendVoteReminders - timed reminder command that DMs non-voting students
 *      in the final hour before polls close (in-app + FCM push).
 *   2. DeviceTokenController - POST/DELETE endpoints for registering phone push
 *      tokens on the Flutter client.
 *   3. FcmPush - dependency-free FCM (HTTP v1, RS256 JWT grant) delivery.
 *   4. SendPushToUsers - queued fan-out job.
 */
class PushRemindersFeatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->detectEnvironment(fn () => 'testing');
        $this->configureTestDatabase();
        $this->createSchema();
        $this->seedDefaultElections();
        $this->seedSampleVoters();
    }

    private function configureTestDatabase(): void
    {
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
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamp('voted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('label');
            $table->string('tier');
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });

        DB::table('phases')->insert([
            ['name' => 'registration', 'description' => 'Registration', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_open', 'description' => 'Voting is open', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'voting_closed', 'description' => 'Voting closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'registration_closed', 'description' => 'Registration closed', 'is_active' => false, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Schema::create('election_settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type')->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tokenable_id');
            $table->string('tokenable_type');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('action');
            $table->text('details')->nullable();
            $table->string('ip')->nullable();
            $table->timestamps();
        });

        Schema::create('vote_ledger', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('candidate_id')->nullable();
            $table->string('election_type')->nullable();
            $table->unsignedInteger('vote_count')->default(0);
            $table->timestamp('recorded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 512);
            $table->string('platform', 16)->default('android');
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique('token');
        });

        Schema::create('vote_reminders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('stage', 64);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'stage']);
        });
    }

    private function seedDefaultElections(): void
    {
        DB::table('positions')->insert([
            ['slug' => 'president', 'label' => 'President', 'tier' => 'national', 'seat_count' => 1, 'sort_order' => 10, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['slug' => 'senator', 'label' => 'Senator', 'tier' => 'national', 'seat_count' => 12, 'sort_order' => 20, 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        DB::table('election_settings')->insert([
            ['key' => 'voting_closes_at', 'value' => '2026-10-20 18:00:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'admin.settings.notifications.pushEnabled', 'value' => 'true', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    private function seedSampleVoters(): void
    {
        $user = User::factory()->create(['is_active' => true, 'has_voted' => false, 'must_change_password' => false]);
        $this->actingAs($user, 'sanctum');
    }

    // --- SendVoteReminders command ---

    public function test_command_skips_when_no_poll_close_time(): void
    {
        DB::table('election_settings')->where('key', 'voting_closes_at')->delete();

        $this->artisan('omnivote:send-vote-reminders')
            ->expectsOutputToContain('No voting close time configured')
            ->assertSuccessful();
    }

    public function test_command_skips_outside_final_hour_window(): void
    {
        $this->artisan('omnivote:send-vote-reminders')
            ->expectsOutputToContain('Outside the closing-reminder window')
            ->assertSuccessful();
    }

    public function test_command_reports_recipient_count_in_dry_run(): void
    {
        DB::table('election_settings')->where('key', 'voting_closes_at')->delete();
        DB::table('election_settings')->insert([
            ['key' => 'voting_closes_at', 'value' => now()->addMinutes(30)->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->artisan('omnivote:send-vote-reminders', ['--dry-run' => true])
            ->expectsOutputToContain('voter(s) would be reminded')
            ->assertSuccessful();
    }

    // --- DeviceTokenController ---

    public function test_store_creates_device_token_for_the_caller(): void
    {
        $token = 'test-fcm-token-'.uniqid();

        $this->actingAs(User::first(), 'sanctum')->withHeaders(['Origin' => 'http://localhost'])->postJson('/api/device-tokens', ['token' => $token, 'platform' => 'android'])
            ->assertOk()->assertJsonPath('status', 'registered');

        $this->assertDatabaseHas('device_tokens', [
            'token' => $token,
            'user_id' => User::first()->id,
        ]);
    }

    public function test_destroy_removes_the_caller_s_own_token(): void
    {
        $token = 'destroy-test-'.uniqid();
        $user = User::first();
        DeviceToken::create([
            'user_id' => $user->id,
            'token' => $token,
            'platform' => 'android',
        ]);

        $this->actingAs(User::first(), 'sanctum')->withHeaders(['Origin' => 'http://localhost'])->deleteJson('/api/device-tokens', ['token' => $token])
            ->assertOk()->assertJsonPath('status', 'removed');

        $this->assertDatabaseMissing('device_tokens', ['token' => $token]);
    }

    // --- FcmPush service ---

    public function test_enabled_returns_false_when_firebase_is_unconfigured(): void
    {
        $this->assertFalse(FcmPush::enabled());
    }

    public function test_enabled_returns_true_when_push_is_configured_and_on(): void
    {
        config(['services.firebase.project_id' => 'test-proj', 'services.firebase.credentials' => '{"client_email":"a@b.c","private_key":"key"}']);
        $this->assertTrue(FcmPush::enabled());
    }

    public function test_send_to_users_returns_zero_when_no_tokens(): void
    {
        $this->assertSame(0, FcmPush::sendToUsers([1, 2, 3], 'Test', 'Body', '/link'));
    }

    // --- SendPushToUsers job ---

    public function test_job_dispatches_the_fcm_push_via_queue(): void
    {
        Queue::fake();

        $userId = User::first()->id;
        SendPushToUsers::dispatch([$userId], 'Title', 'Body', '/vote-now');

        Queue::assertPushed(SendPushToUsers::class, 1);
    }
}
