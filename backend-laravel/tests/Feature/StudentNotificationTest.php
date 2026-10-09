<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Student in-app notification center + the admin broadcast that feeds it.
 *
 *   GET  /api/notifications               — the caller's own feed + unread count
 *   POST /api/notifications/read          — mark one/all of the caller's rows
 *   POST /api/admin/notifications/broadcast — fan out to active students
 */
class StudentNotificationTest extends TestCase
{
    use DisablesTwoFactorEnforcement;

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
        $this->disableTwoFactorRequirement();
    }

    public function test_a_student_sees_only_their_own_feed_and_unread_count(): void
    {
        $student = $this->makeUser();
        $other = $this->makeUser();

        $this->notification($student, 'Unread one');
        $this->notification($student, 'Already read', read: true);
        $this->notification($other, 'Someone else\'s');

        $this->actingAs($student, 'sanctum')
            ->getJson('/api/notifications')
            ->assertOk()
            ->assertJsonPath('unread', 1)
            ->assertJsonCount(2, 'notifications');
    }

    public function test_marking_read_cannot_touch_another_students_row(): void
    {
        $student = $this->makeUser();
        $other = $this->makeUser();

        $mine = $this->notification($student, 'Mine');
        $theirs = $this->notification($other, 'Theirs');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/notifications/read', ['id' => $theirs->id])
            ->assertOk()
            ->assertJsonPath('updated', 0);

        $this->assertNull($theirs->fresh()->read_at);

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/notifications/read', ['id' => $mine->id])
            ->assertOk()
            ->assertJsonPath('updated', 1);

        $this->assertNotNull($mine->fresh()->read_at);
    }

    public function test_marking_read_without_an_id_clears_every_unread_row(): void
    {
        $student = $this->makeUser();

        $this->notification($student, 'One');
        $this->notification($student, 'Two');

        $this->actingAs($student, 'sanctum')
            ->postJson('/api/notifications/read')
            ->assertOk()
            ->assertJsonPath('updated', 2);
    }

    public function test_an_admin_broadcast_reaches_every_active_student(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $active = $this->makeUser(['name' => 'Active']);
        $disabled = $this->makeUser(['name' => 'Disabled', 'is_active' => false]);

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/notifications/broadcast', [
                'title' => 'Polls open tomorrow',
                'body' => 'Be ready.',
                'type' => 'info',
                'link' => '/vote-now',
            ])
            ->assertStatus(201)
            ->assertJsonPath('recipients', 1);

        $this->assertDatabaseHas('user_notifications', [
            'user_id' => $active->id,
            'title' => 'Polls open tomorrow',
        ]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $disabled->id]);
    }

    public function test_a_broadcast_can_target_a_single_year_level(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $year11 = $this->makeUser(['year_level' => '11']);
        $year12 = $this->makeUser(['year_level' => '12']);

        $this->actingAs($admin)
            ->stateful()
            ->postJson('/api/admin/notifications/broadcast', [
                'title' => 'Year 11 assembly',
                'year_level' => '11',
            ])
            ->assertStatus(201)
            ->assertJsonPath('recipients', 1);

        $this->assertDatabaseHas('user_notifications', ['user_id' => $year11->id]);
        $this->assertDatabaseMissing('user_notifications', ['user_id' => $year12->id]);
    }

    public function test_a_student_cannot_broadcast(): void
    {
        $student = $this->makeUser();

        $this->actingAs($student)
            ->stateful()
            ->postJson('/api/admin/notifications/broadcast', ['title' => 'Nope'])
            ->assertStatus(403);
    }

    private function stateful(): static
    {
        return $this->withHeaders(['Origin' => 'http://localhost']);
    }

    private function notification(User $user, string $title, bool $read = false): UserNotification
    {
        return UserNotification::create([
            'user_id' => $user->id,
            'type' => 'info',
            'title' => $title,
            'body' => null,
            'link' => null,
            'read_at' => $read ? now() : null,
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
            'year_level' => '11',
        ], $attributes));
    }

    /** Minimal schema for the notification flows exercised here. */
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
    }
}
