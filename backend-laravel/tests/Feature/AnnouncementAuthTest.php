<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Ownership + role enforcement for announcement CRUD. The delete endpoint is
 * the sharp edge: NO role — including admin — may delete an announcement
 * authored by another user; only the author can remove their own post. The SPA
 * mirrors the same rule so the trash button only appears on the user's own
 * announcements.
 */
class AnnouncementAuthTest extends TestCase
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

    public function test_admin_can_delete_only_their_own_announcements(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $otherAuthor = $this->makeUser(['role' => 'teacher']);
        $theirs = $this->makeAnnouncement($otherAuthor);

        $this->actingAs($admin)
            ->stateful()
            ->deleteJson('/api/admin/ssg/announcements/'.$theirs->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You may only delete your own announcements.');

        $this->assertModelExists($theirs);

        $mine = $this->makeAnnouncement($admin);

        $this->actingAs($admin)
            ->stateful()
            ->deleteJson('/api/admin/ssg/announcements/'.$mine->id)
            ->assertOk()
            ->assertJsonPath('message', 'Announcement deleted.');

        $this->assertModelMissing($mine);
    }

    public function test_ssg_president_can_delete_only_their_own_announcements(): void
    {
        $owner = $this->makeUser(['role' => 'ssg_president']);
        $otherAuthor = $this->makeUser(['role' => 'ssg_president', 'name' => 'Other SSG']);

        $mine = $this->makeAnnouncement($owner);
        $theirs = $this->makeAnnouncement($otherAuthor);

        $this->actingAs($owner)
            ->stateful()
            ->deleteJson('/api/admin/ssg/announcements/'.$mine->id)
            ->assertOk();

        $this->assertModelMissing($mine);

        $this->actingAs($owner)
            ->stateful()
            ->deleteJson('/api/admin/ssg/announcements/'.$theirs->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You may only delete your own announcements.');

        $this->assertModelExists($theirs);
    }

    public function test_teacher_can_delete_only_their_own_announcements(): void
    {
        $teacher = $this->makeUser(['role' => 'teacher']);
        $otherAuthor = $this->makeUser(['role' => 'teacher', 'name' => 'Other Teacher']);

        $mine = $this->makeAnnouncement($teacher);
        $theirs = $this->makeAnnouncement($otherAuthor);

        $this->actingAs($teacher)
            ->stateful()
            ->deleteJson('/api/admin/ssg/announcements/'.$mine->id)
            ->assertOk();

        $this->assertModelMissing($mine);

        $this->actingAs($teacher)
            ->stateful()
            ->deleteJson('/api/admin/ssg/announcements/'.$theirs->id)
            ->assertStatus(403)
            ->assertJsonPath('message', 'You may only delete your own announcements.');

        $this->assertModelExists($theirs);
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

    private function makeAnnouncement(User $author): Announcement
    {
        return Announcement::create([
            'user_id' => $author->id,
            'title' => 'Test Announcement',
            'body' => 'Hello election voters.',
            'published_at' => now(),
        ]);
    }

    /** Minimal schema for the models exercised by these flows. */
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

        Schema::create('announcements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title');
            $table->text('body');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });
    }
}