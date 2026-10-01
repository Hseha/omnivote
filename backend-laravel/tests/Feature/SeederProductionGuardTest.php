<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\RegistrarImport;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\StudentSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Demo seeders must not run against a production database.
 *
 * UserSeeder creates panel logins (admin / teacher / ssg_president) and
 * StudentSeeder creates a voter whose password is its own student ID. Neither
 * belongs in a live election database, and `php artisan db:seed` is one
 * mistyped host away from putting them there. CatalogSeeder is the exception:
 * it is structural and idempotent, so production legitimately runs it.
 *
 * The guards return early and warn rather than throwing, so a production
 * operator refreshing the catalog still gets a usable command.
 *
 * Every production case here passes --force, and that detail is load-bearing:
 * `db:seed` in the production environment asks "Do you really wish to run this
 * command?" and aborts if the answer is no, which in an unattended test run it
 * always is. Without --force the command would stop before reaching a seeder at
 * all, so these tests would pass whether or not any guard existed.
 *
 * Like the rest of the suite, this builds an isolated in-memory SQLite schema
 * instead of using RefreshDatabase: the migration set contains MySQL-only ALTER
 * TABLE statements and the default connection of this checkout points at a live
 * MySQL database (see DisabledUserLoginTest for the same reasoning).
 */
class SeederProductionGuardTest extends TestCase
{
    private const ENV_KEY = 'SEED_DEV_PASSWORD';

    protected function setUp(): void
    {
        parent::setUp();

        // Drop any connection resolved during boot before repointing the default
        // connection at the private in-memory database, so a seeder can never
        // reach the developer's MySQL database.
        DB::purge();

        config([
            'database.default' => 'omnivote_testing',
            'database.connections.omnivote_testing' => [
                'driver' => 'sqlite',
                'database' => ':memory:',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);

        DB::setDefaultConnection('omnivote_testing');
        DB::purge('omnivote_testing');

        $this->createSchema();
    }

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
        putenv(self::ENV_KEY);

        parent::tearDown();
    }

    /**
     * Declare the environment the seeders will observe. Asserted in the tests
     * below so a framework change here fails loudly instead of making the guards
     * look effective when they were never exercised.
     */
    private function useEnvironment(string $environment): void
    {
        $this->app->detectEnvironment(fn () => $environment);
    }

    public function test_user_seeder_creates_no_panel_logins_in_production(): void
    {
        $this->useEnvironment('production');
        $this->assertSame('production', $this->app->environment());

        $this->artisan('db:seed', ['--class' => UserSeeder::class, '--force' => true])
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
    }

    public function test_student_seeder_creates_no_voter_in_production(): void
    {
        $this->useEnvironment('production');

        $this->artisan('db:seed', ['--class' => StudentSeeder::class, '--force' => true])
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        $this->assertSame(0, User::count());
        $this->assertSame(0, RegistrarImport::count());
    }

    public function test_database_seeder_still_refreshes_the_catalog_in_production(): void
    {
        $this->useEnvironment('production');

        $this->artisan('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true])
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        // The catalog is present — production needs it — and no demo data is.
        $this->assertGreaterThan(0, Department::count());
        $this->assertSame(0, User::count());
        $this->assertSame(0, RegistrarImport::count());
    }

    public function test_user_seeder_still_runs_off_production(): void
    {
        // The suite normally runs as "testing"; assert that rather than assume it.
        $this->assertSame('testing', $this->app->environment());

        $_ENV[self::ENV_KEY] = 'shared-dev-password';
        $_SERVER[self::ENV_KEY] = 'shared-dev-password';
        putenv(self::ENV_KEY.'=shared-dev-password');

        $this->seed(UserSeeder::class);

        $this->assertSame(3, User::count());

        $admin = User::where('email', 'admin@omnivote.test')->firstOrFail();

        $this->assertSame('admin', $admin->role);
        // Proves the `hashed` cast performed the hashing (UserSeeder no longer
        // calls Hash::make itself) and that a login with the resolved password
        // would succeed.
        $this->assertTrue(Hash::check('shared-dev-password', $admin->password));

        // Re-running must not touch the existing account.
        $this->seed(UserSeeder::class);

        $this->assertSame(3, User::count());
        $this->assertTrue(Hash::check('shared-dev-password', $admin->fresh()->password));
    }

    /** Minimal schema for the tables the seeders touch. */
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
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->boolean('has_voted')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('must_change_password')->default(false);
            $table->timestamps();
        });

        Schema::create('registrar_imports', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('student_id')->unique();
            $table->string('full_name');
            $table->string('email')->nullable();
            $table->string('role')->nullable();
            $table->string('year_level')->nullable();
            $table->string('block_number')->nullable();
            $table->string('department')->nullable();
            $table->boolean('needs_review')->default(false);
            $table->string('review_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('courses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::create('department_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('department_id')->constrained('departments')->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });

        Schema::create('course_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('course_id')->constrained('courses')->cascadeOnDelete();
            $table->string('alias')->unique();
            $table->timestamps();
        });
    }
}
