<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Unified admin analytics feed backing the two live monitoring panels:
 *
 *   GET /api/admin/dashboard/analytics
 *     - `velocity`: 24 hourly buckets of anonymous distinct-ballot counts
 *     - `security_logs`: latest 6 audit trail events with severity mapping
 *
 * Permission-gated (`permission:dashboard.view`) like the overview endpoint
 * it sits beside. Also pins the H-1 anonymity invariant: the velocity payload
 * is a pure per-hour count — nothing from the users table can leak into it.
 */
class AdminDashboardAnalyticsTest extends TestCase
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

    public function test_endpoint_requires_dashboard_view_permission(): void
    {
        $student = $this->makeUser(['role' => 'student']);

        $this->actingAs($student);

        $this->stateful()
            ->getJson('/api/admin/dashboard/analytics')
            ->assertForbidden();
    }

    public function test_velocity_counts_distinct_ballots_per_hour(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        DB::table('vote_ledger')->insert([
            ['position_key' => 'president', 'candidate_ref' => 'c1', 'receipt_hmac' => 'h1', 'recorded_at' => now()->subHours(2)],
            ['position_key' => 'president', 'candidate_ref' => 'c2', 'receipt_hmac' => 'h2', 'recorded_at' => now()->subHours(2)],
            ['position_key' => 'auditor', 'candidate_ref' => 'c3', 'receipt_hmac' => 'h3', 'recorded_at' => now()->subHours(1)],
            // Outside the 24 h window — must not be counted.
            ['position_key' => 'president', 'candidate_ref' => 'c1', 'receipt_hmac' => 'h4', 'recorded_at' => now()->subHours(48)],
        ]);

        $this->actingAs($admin);

        $body = $this->stateful()
            ->getJson('/api/admin/dashboard/analytics')
            ->assertOk()
            ->json();

        $velocity = $body['velocity'];
        $this->assertCount(24, $velocity);

        foreach ($velocity as $bucket) {
            $this->assertMatchesRegularExpression('/^\d{2}:00$/', $bucket['hour']);
            $this->assertIsInt($bucket['count']);
        }

        $this->assertSame(3, collect($velocity)->sum('count'));

        // The 2- and 1-hour-ago buckets must hold their exact counts (each row
        // here carries a distinct receipt_hmac, i.e. a distinct ballot).
        $twoHoursAgo = now()->startOfHour()->subHours(2)->format('H:00');
        $oneHourAgo = now()->startOfHour()->subHour()->format('H:00');
        $this->assertSame(2, collect($velocity)->firstWhere('hour', $twoHoursAgo)['count']);
        $this->assertSame(1, collect($velocity)->firstWhere('hour', $oneHourAgo)['count']);

        // H-1: the payload shape carries only hour labels and counts — no
        // receipt, candidate, position or user fields may appear.
        foreach ($velocity as $bucket) {
            $this->assertSame(['hour', 'count'], array_keys($bucket));
        }
    }

    public function test_velocity_counts_one_ballot_with_many_positions_once(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // One ballot filling two positions = two rows sharing one receipt_hmac.
        // A second, separate ballot in the same hour shares nothing.
        DB::table('vote_ledger')->insert([
            ['position_key' => 'president', 'candidate_ref' => 'c1', 'receipt_hmac' => 'ballot-a', 'recorded_at' => now()->subHours(1)],
            ['position_key' => 'auditor', 'candidate_ref' => 'c2', 'receipt_hmac' => 'ballot-a', 'recorded_at' => now()->subHours(1)],
            ['position_key' => 'president', 'candidate_ref' => 'c3', 'receipt_hmac' => 'ballot-b', 'recorded_at' => now()->subHours(1)],
        ]);

        $velocity = $this->actingAs($admin)
            ->stateful()
            ->getJson('/api/admin/dashboard/analytics')
            ->assertOk()
            ->json('velocity');

        // 3 rows but only 2 ballots.
        $this->assertSame(2, collect($velocity)->sum('count'));

        $oneHourAgo = now()->startOfHour()->subHour()->format('H:00');
        $this->assertSame(2, collect($velocity)->firstWhere('hour', $oneHourAgo)['count']);
    }

    public function test_velocity_excludes_the_same_clock_hour_from_a_day_ago(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // Same clock-hour as now but ~24 h ago: outside the 24-slot window and
        // must NOT fold into today's current-hour bucket.
        DB::table('vote_ledger')->insert([
            ['position_key' => 'president', 'candidate_ref' => 'c1', 'receipt_hmac' => 'stale', 'recorded_at' => now()->subHours(24)],
            ['position_key' => 'president', 'candidate_ref' => 'c2', 'receipt_hmac' => 'fresh', 'recorded_at' => now()],
        ]);

        $velocity = $this->actingAs($admin)
            ->stateful()
            ->getJson('/api/admin/dashboard/analytics')
            ->assertOk()
            ->json('velocity');

        $this->assertSame(1, collect($velocity)->sum('count'));

        $currentHour = now()->startOfHour()->format('H:00');
        $this->assertSame(1, collect($velocity)->firstWhere('hour', $currentHour)['count']);
    }

    public function test_security_logs_surface_latest_six_audit_events_with_severity(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // Insertion order == recency: row 0 is oldest (falls outside the
        // latest-6 window), row 6 is newest.
        $actions = [
            ['settings_updated', 'warning'], // 7th-newest — must be dropped.
            ['user_role_changed', 'warning'],
            ['backup_created', 'success'],
            ['student_credentials_rotated', 'success'],
            ['login_success', 'success'],
            ['account_locked', 'critical'],
            ['user_disabled', 'critical'],
        ];

        foreach ($actions as $index => [$action, $severity]) {
            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $admin->id,
                'details' => "Details for {$action}",
                'ip_address' => '192.0.2.14',
                'created_at' => now()->subMinutes(30 - $index),
                'updated_at' => now()->subMinutes(30 - $index),
            ]);
        }

        $this->actingAs($admin);

        $body = $this->stateful()
            ->getJson('/api/admin/dashboard/analytics')
            ->assertOk()
            ->json();

        $logs = $body['security_logs'];
        $this->assertCount(6, $logs);

        // Newest first.
        $this->assertSame('user_disabled', $logs[0]['action']);
        $this->assertSame('critical', $logs[0]['severity']);
        $this->assertSame('User Disabled', $logs[0]['label']);

        $this->assertSame('student_credentials_rotated', collect($logs)->firstWhere('action', 'student_credentials_rotated')['action']);
        $this->assertSame('success', collect($logs)->firstWhere('action', 'student_credentials_rotated')['severity']);

        $byAction = collect($logs)->keyBy('action');
        $this->assertSame('warning', $byAction['user_role_changed']['severity']);
        $this->assertSame('success', $byAction['backup_created']['severity']);
        $this->assertSame('critical', $byAction['account_locked']['severity']);

        // IP + actor mapped into a readable snippet.
        $this->assertStringContainsString('IP 192.0.2.14', $byAction['backup_created']['detail']);
        $this->assertStringContainsString($admin->name, $byAction['backup_created']['detail']);
        $this->assertNotNull($byAction['backup_created']['at']);
    }

    public function test_security_logs_fall_back_to_user_notifications_without_audit_table(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        // Simulate an install that predates the audit trail.
        Schema::drop('audit_logs');

        DB::table('user_notifications')->insert([
            'user_id' => $admin->id,
            'type' => 'danger',
            'title' => 'Brute-force backoff triggered',
            'body' => 'Account locked after 5 failed attempts.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($admin);

        $body = $this->stateful()
            ->getJson('/api/admin/dashboard/analytics')
            ->assertOk()
            ->json();

        $this->assertCount(1, $body['security_logs']);
        $this->assertSame('critical', $body['security_logs'][0]['severity']);
        $this->assertSame('Brute-force backoff triggered', $body['security_logs'][0]['label']);
        $this->assertStringContainsString('locked', $body['security_logs'][0]['detail']);
    }

    /** Send the request as the first-party SPA so Sanctum starts a session. */
    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
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
            $table->timestamp('voted_at')->nullable();
            $table->text('two_factor_secret')->nullable();
            $table->boolean('two_factor_enabled')->default(false);
            $table->text('two_factor_recovery_codes')->nullable();
            $table->text('avatar_url')->nullable();
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

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('action', 100);
            $table->string('entity_type', 50)->nullable();
            $table->unsignedBigInteger('entity_id')->nullable();
            $table->text('details')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->timestamps();
        });

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('type', 20)->default('info');
            $table->string('title');
            $table->text('body')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('read_at')->nullable();
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

        // Permission-gated routes check the 2FA-required toggle; turn it off so
        // these tests exercise authorization, not enrollment.
        DB::table('election_settings')->insert([
            'key' => 'admin.settings.security.twoFactorRequired',
            'value' => 'false',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
