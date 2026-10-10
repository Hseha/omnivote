<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Notifier;
use App\Support\SystemHealth;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Server health probe: snapshot shape, admin-only alerting, status-transition
 * decisions, and the omnivote:health cache/round-trip.
 */
class SystemHealthTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

    private string $snapshotPath;

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

        $this->snapshotPath = storage_path('app/monitoring-test/'.Str::uuid().'.json');
        config(['omnivote.health_snapshot' => $this->snapshotPath]);

        $this->createSchema();
        $this->disableTwoFactorRequirement();
    }

    protected function tearDown(): void
    {
        File::delete($this->snapshotPath);

        parent::tearDown();
    }

    public function test_run_returns_a_full_snapshot(): void
    {
        $snapshot = SystemHealth::run();

        $this->assertContains($snapshot['overall'], [SystemHealth::OK, SystemHealth::WARN, SystemHealth::FAIL]);
        $this->assertSame($snapshot['overall'], $snapshot['status']);

        $this->assertSame(
            ['database', 'storage', 'disk', 'queue', 'backup', 'phase', 'migrations'],
            array_keys($snapshot['checks']),
        );

        foreach ($snapshot['checks'] as $check) {
            $this->assertContains($check['status'], [SystemHealth::OK, SystemHealth::WARN, SystemHealth::FAIL]);
            $this->assertNotSame('', $check['label']);
            $this->assertNotSame('', $check['message']);
        }
    }

    public function test_persist_and_stored_round_trip(): void
    {
        $this->assertNull(SystemHealth::stored());

        $snapshot = [
            'overall' => SystemHealth::WARN,
            'status' => SystemHealth::WARN,
            'checked_at' => now()->toIso8601String(),
            'checks' => ['disk' => ['status' => SystemHealth::WARN, 'label' => 'Disk', 'message' => '81% used']],
        ];

        SystemHealth::persist($snapshot);

        $this->assertSame($snapshot, SystemHealth::stored());
    }

    public function test_to_admin_users_in_app_reaches_only_active_admins(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $disabledAdmin = $this->makeUser(['role' => 'admin', 'is_active' => false]);
        $teacher = $this->makeUser(['role' => 'teacher']);

        $count = Notifier::toAdminUsersInApp('danger', 'System health: DOWN', 'Database: Connection failed', '/dashboard');

        $this->assertSame(1, $count);
        $this->assertDatabaseHas('user_notifications', ['user_id' => $admin->id, 'title' => 'System health: DOWN']);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $disabledAdmin->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $teacher->id]);
    }

    public function test_transition_alerts_only_on_a_real_state_change(): void
    {
        $ok = ['overall' => SystemHealth::OK, 'checked_at' => 'now', 'checks' => []];
        $warn = ['overall' => SystemHealth::WARN, 'checked_at' => 'now', 'checks' => [
            'backup' => ['status' => SystemHealth::WARN, 'label' => 'Backup', 'message' => 'No snapshots yet'],
        ]];
        $fail = ['overall' => SystemHealth::FAIL, 'checked_at' => 'now', 'checks' => [
            'database' => ['status' => SystemHealth::FAIL, 'label' => 'Database', 'message' => 'Connection failed'],
        ]];

        // First-ever reading: silent unless it is already a hard failure.
        $this->assertNull(SystemHealth::transition(null, $ok));
        $this->assertNull(SystemHealth::transition(null, $warn));
        $this->assertSame('danger', SystemHealth::transition(null, $fail)['type']);

        // Unchanged reading: never re-alerts.
        $this->assertNull(SystemHealth::transition($warn, $warn));

        // A real change: one alert in the new direction.
        $this->assertSame('warning', SystemHealth::transition($ok, $warn)['type']);
        $this->assertSame('danger', SystemHealth::transition($warn, $fail)['type']);
        $this->assertSame('success', SystemHealth::transition($fail, $ok)['type']);
    }

    public function test_health_command_caches_a_snapshot(): void
    {
        $this->artisan('omnivote:health')->assertSuccessful();

        $this->assertNotNull(SystemHealth::stored());
        $this->assertArrayHasKey('overall', SystemHealth::stored());
    }

    private function makeUser(array $attributes = []): User
    {
        return User::create(array_merge([
            'name' => 'Test User',
            'email' => Str::uuid().'@example.test',
            'password' => 'secret-password',
            'role' => 'student',
            'is_active' => true,
            'year_level' => '11',
        ], $attributes));
    }

    /** Minimal schema for the health probe's DB reads. */
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

        Schema::create('user_notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50)->default('info');
            $table->string('title', 200);
            $table->text('body')->nullable();
            $table->string('link', 500)->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'read_at']);
        });

        Schema::create('phases', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }
}
