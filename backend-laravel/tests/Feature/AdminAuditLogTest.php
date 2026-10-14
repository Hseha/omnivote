<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Admin sign-in auditing + the read-only audit-log endpoint (React Audit Log).
 *
 *   login success / failure / lockout / 2FA failure -> audit_logs rows
 *   GET /api/admin/audit-logs -> admin only (permission:audit.view), paginated,
 *                                filterable
 *
 * Uses the same isolated in-memory SQLite schema style as the other auth tests,
 * extended with the audit_logs table (including the user_agent column).
 */
class AdminAuditLogTest extends TestCase
{
    private const PASSWORD = 'secret-password';

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

    public function test_successful_login_writes_a_success_audit_row(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertOk();

        $row = DB::table('audit_logs')->where('action', 'login_success')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertStringContainsString($admin->name, (string) $row->details);
        $this->assertNotNull($row->user_agent);
    }

    public function test_failed_login_writes_a_failure_audit_row(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        $this->stateful()
            ->postJson('/api/admin/login', ['email' => $admin->email, 'password' => 'wrong-password'])
            ->assertStatus(401);

        $row = DB::table('audit_logs')->where('action', 'login_failed')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->user_id);
        $this->assertStringContainsString($admin->email, (string) $row->details);
    }

    public function test_failed_login_for_an_unknown_address_is_still_recorded(): void
    {
        $this->stateful()
            ->postJson('/api/admin/login', ['email' => 'nobody@example.test', 'password' => 'whatever'])
            ->assertStatus(401);

        $row = DB::table('audit_logs')->where('action', 'login_failed')->first();
        $this->assertNotNull($row);
        $this->assertNull($row->user_id);
        $this->assertStringContainsString('nobody@example.test', (string) $row->details);
    }

    public function test_logout_writes_a_logout_audit_row(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $this->stateful()->postJson('/api/admin/logout')->assertNoContent();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'logout',
            'user_id' => $admin->id,
        ]);
    }

    public function test_audit_listing_requires_the_view_permission(): void
    {
        // A panel role without audit.view (SSG Adviser).
        $teacher = $this->makeUser(['role' => 'teacher']);
        $this->actingAs($teacher);

        $this->stateful()
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(403);
    }

    public function test_audit_listing_rejects_guests(): void
    {
        $this->stateful()
            ->getJson('/api/admin/audit-logs')
            ->assertStatus(401);
    }

    public function test_audit_listing_returns_rows_with_pagination_meta(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        DB::table('audit_logs')->insert([
            [
                'user_id' => $admin->id,
                'action' => 'settings_updated',
                'entity_type' => 'settings',
                'entity_id' => null,
                'details' => 'Security settings updated.',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'user_id' => $admin->id,
                'action' => 'candidate_certified',
                'entity_type' => 'candidate',
                'entity_id' => 7,
                'details' => 'Certified winner.',
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->actingAs($admin);

        $response = $this->stateful()
            ->getJson('/api/admin/audit-logs?per_page=1&page=1')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [['id', 'user_id', 'user_name', 'action', 'entity_type', 'entity_id', 'details', 'ip_address', 'created_at']],
                'meta' => ['total', 'per_page', 'current_page', 'last_page'],
            ]);

        $body = $response->json();
        $this->assertSame(2, $body['meta']['total']);
        $this->assertSame(1, $body['meta']['per_page']);
        $this->assertSame(2, $body['meta']['last_page']);
        $this->assertCount(1, $body['data']);
        // Newest first.
        $this->assertSame('candidate_certified', $body['data'][0]['action']);
        // The actor name is joined in for the UI.
        $this->assertSame($admin->name, $body['data'][0]['user_name']);
    }

    public function test_audit_listing_filters_by_action(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);

        foreach (['login_success', 'login_failed', 'login_success'] as $action) {
            DB::table('audit_logs')->insert([
                'user_id' => $admin->id,
                'action' => $action,
                'entity_type' => 'user',
                'entity_id' => $admin->id,
                'details' => $action,
                'ip_address' => '127.0.0.1',
                'user_agent' => 'Mozilla/5.0 test',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->actingAs($admin);

        $body = $this->stateful()
            ->getJson('/api/admin/audit-logs?action=login_success')
            ->assertOk()
            ->json();

        $this->assertSame(2, $body['meta']['total']);
        foreach ($body['data'] as $row) {
            $this->assertSame('login_success', $row['action']);
        }
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
            'password' => self::PASSWORD,
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
