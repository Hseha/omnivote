<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BackupManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Backup & restore from the React Configuration → Backup & Restore screen.
 *
 *   GET    /api/admin/backups                    — list snapshots
 *   POST   /api/admin/backups                    — create a snapshot now
 *   POST   /api/admin/backups/restore            — replay a snapshot
 *   GET    /api/admin/backups/{file}/download    — download raw file
 *   DELETE /api/admin/backups/{file}             — delete a snapshot
 *
 *   - gated behind auth + the settings.view / settings.update permissions
 *   - snapshot covers the election tables; restore is transactional
 *   - dangerous operations create an audit_logs row when that table exists
 *
 * Uses the same isolated in-memory SQLite schema as the other admin feature
 * tests (skipped locally where pdo_sqlite is missing — the CI runner has it).
 */
class BackupTest extends TestCase
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
        File::ensureDirectoryExists(BackupManager::directory());
        File::cleanDirectory(BackupManager::directory());
    }

    public function test_admin_can_create_and_list_backups(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/backups', ['encrypt' => true])
            ->assertStatus(201)
            ->assertJsonPath('backup.encrypted', true);

        $this->assertCount(1, File::files(BackupManager::directory()));

        $this->stateful()
            ->getJson('/api/admin/backups')
            ->assertOk()
            ->assertJsonCount(1, 'backups')
            ->assertJsonStructure(['backups' => [['filename', 'label', 'size', 'encrypted']]]);
    }

    public function test_restore_round_trips_the_data(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $positionId = DB::table('positions')->insertGetId([
            'slug' => 'treasurer',
            'label' => 'Treasurer',
            'tier' => 'school',
            'seat_count' => 1,
            'sort_order' => 20,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('election_settings')->insert([
            'key' => 'admin.settings.backup.backupEncryption',
            'value' => 'true',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $created = $this->stateful()
            ->postJson('/api/admin/backups', ['encrypt' => true])
            ->assertStatus(201)
            ->json('backup');

        // Mutate the data, then restore.
        DB::table('positions')->where('id', $positionId)->delete();
        DB::table('election_settings')->delete();
        $this->assertSame(0, DB::table('positions')->count());
        // Wiping settings must not lock the restore request itself out behind
        // the (default-on) 2FA requirement — keep that one config row present.
        $this->disableTwoFactorRequirement();

        $this->stateful()
            ->postJson('/api/admin/backups/restore', ['file' => $created['filename']])
            ->assertOk();

        $this->assertSame(1, DB::table('positions')->count());
        $this->assertSame('Treasurer', DB::table('positions')->where('id', $positionId)->value('label'));
        $this->assertSame(
            'true',
            DB::table('election_settings')->where('key', 'admin.settings.backup.backupEncryption')->value('value'),
        );
    }

    public function test_admin_can_download_and_delete_a_backup(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $created = $this->stateful()
            ->postJson('/api/admin/backups', ['encrypt' => true])
            ->assertStatus(201)
            ->json('backup');

        $this->stateful()
            ->get('/api/admin/backups/'.$created['filename'].'/download')
            ->assertOk();

        $this->stateful()
            ->deleteJson('/api/admin/backups/'.$created['filename'])
            ->assertOk()
            ->assertJsonPath('deleted', $created['filename']);

        $this->assertCount(0, File::files(BackupManager::directory()));
    }

    public function test_students_cannot_create_or_restore_backups(): void
    {
        $student = $this->makeUser(['role' => 'student']);
        $this->actingAs($student);

        $this->stateful()->postJson('/api/admin/backups')->assertStatus(403);
        $this->stateful()->postJson('/api/admin/backups/restore', ['file' => 'omnivote-20260920120000.omsnapshot'])->assertStatus(403);
    }

    public function test_restore_rejects_missing_or_malformed_files(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $this->stateful()
            ->postJson('/api/admin/backups/restore', ['file' => 'omnivote-20260920120000.omsnapshot'])
            ->assertStatus(422);

        File::put(BackupManager::directory().'/omnivote-20260920120000.omsnapshot', 'not valid');
        $this->stateful()
            ->postJson('/api/admin/backups/restore', ['file' => 'omnivote-20260920120000.omsnapshot'])
            ->assertStatus(422);
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

    /** Minimal schema for the flows exercised here (mirrors the real columns). */
    private function createSchema(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->rememberToken();
            $table->string('role')->default('student');
            $table->boolean('is_active')->default(true);
            $table->boolean('has_voted')->default(false);
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
            $table->string('tier')->default('school');
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }
}
