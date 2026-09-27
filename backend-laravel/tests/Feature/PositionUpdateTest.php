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
 * Ballot position editing from the Election Setup screen.
 *
 *   PATCH /api/admin/positions/{position}
 *
 *   - gated behind auth + the `election.update_config` permission
 *   - edits label / seat_count / tier / is_active; the slug never changes
 *   - rejects empty titles, out-of-range seats, and bogus tiers
 *
 * Uses the same isolated in-memory SQLite schema as the other admin feature
 * tests (skipped locally where pdo_sqlite is missing — the CI runner has it).
 */
class PositionUpdateTest extends TestCase
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

    public function test_admin_can_edit_a_position(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $position = DB::table('positions')->where('slug', 'secretary')->first();

        $this->stateful()
            ->patchJson("/api/admin/positions/{$position->id}", [
                'label' => 'Recording Secretary',
                'seat_count' => 2,
                'tier' => 'national',
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('position.title', 'Recording Secretary')
            ->assertJsonPath('position.seat_count', 2);

        $this->assertSame('Recording Secretary', DB::table('positions')->where('id', $position->id)->value('label'));
        $this->assertSame(2, (int) DB::table('positions')->where('id', $position->id)->value('seat_count'));
    }

    public function test_empty_label_is_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $position = DB::table('positions')->where('slug', 'secretary')->first();

        $this->stateful()
            ->patchJson("/api/admin/positions/{$position->id}", ['label' => ''])
            ->assertStatus(422);
    }

    public function test_out_of_range_seats_are_rejected(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $position = DB::table('positions')->where('slug', 'secretary')->first();

        $this->stateful()
            ->patchJson("/api/admin/positions/{$position->id}", ['seat_count' => 99])
            ->assertStatus(422);

        // Nothing changed.
        $this->assertSame(1, (int) DB::table('positions')->where('id', $position->id)->value('seat_count'));
    }

    public function test_slug_is_immutable_through_edits(): void
    {
        $admin = $this->makeUser(['role' => 'admin']);
        $this->actingAs($admin);

        $position = DB::table('positions')->where('slug', 'secretary')->first();

        $this->stateful()
            ->patchJson("/api/admin/positions/{$position->id}", ['label' => 'Secretary', 'slug' => 'renamed'])
            ->assertOk();

        $this->assertSame(
            'secretary',
            DB::table('positions')->where('id', $position->id)->value('slug'),
        );
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $position = DB::table('positions')->where('slug', 'secretary')->first();

        $this->stateful()
            ->patchJson("/api/admin/positions/{$position->id}", ['label' => 'Secretary'])
            ->assertStatus(401);
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
            $table->string('tier')->default('national');
            $table->string('scope_type', 32)->default('global');
            $table->string('scope_value')->nullable();
            $table->unsignedTinyInteger('seat_count')->default(1);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('positions')->insert([
            'slug' => 'secretary',
            'label' => 'Secretary',
            'tier' => 'national',
            'seat_count' => 1,
            'sort_order' => 30,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
