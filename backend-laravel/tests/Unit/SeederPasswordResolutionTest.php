<?php

namespace Tests\Unit;

use Database\Seeders\UserSeeder;
use Tests\TestCase;

/**
 * Where the dev panel logins get their passwords from (UserSeeder).
 *
 * database/data/users.json is committed, so it must never carry a usable
 * credential: it used to hold `admin123`, `teacher123` and the SSG president's
 * password in plain text, which put working administrator logins in every clone
 * of this repository. Passwords now come from the entry, the environment, or a
 * generated value — these tests pin that order, and pin the absence of
 * passwords from the fixture itself.
 */
class SeederPasswordResolutionTest extends TestCase
{
    private const ENV_KEY = 'SEED_DEV_PASSWORD';

    protected function tearDown(): void
    {
        unset($_ENV[self::ENV_KEY], $_SERVER[self::ENV_KEY]);
        putenv(self::ENV_KEY);

        parent::tearDown();
    }

    /**
     * env() consults $_ENV and $_SERVER before getenv(), so set all three and
     * this assertion holds whichever one the framework reads.
     */
    private function setSharedPassword(string $value): void
    {
        $_ENV[self::ENV_KEY] = $value;
        $_SERVER[self::ENV_KEY] = $value;
        putenv(self::ENV_KEY.'='.$value);
    }

    public function test_entry_password_wins_over_the_environment(): void
    {
        $this->setSharedPassword('shared-team-password');

        $resolved = (new UserSeeder)->resolvePassword([
            'email' => 'admin@omnivote.test',
            'password' => 'throwaway-local-override',
        ]);

        $this->assertSame('throwaway-local-override', $resolved['value']);
        $this->assertFalse($resolved['generated']);
    }

    public function test_environment_password_is_used_when_the_entry_has_none(): void
    {
        $this->setSharedPassword('shared-team-password');

        $resolved = (new UserSeeder)->resolvePassword(['email' => 'admin@omnivote.test']);

        $this->assertSame('shared-team-password', $resolved['value']);
        $this->assertFalse($resolved['generated']);
    }

    public function test_a_blank_entry_password_falls_through_to_the_environment(): void
    {
        $this->setSharedPassword('shared-team-password');

        $resolved = (new UserSeeder)->resolvePassword([
            'email' => 'admin@omnivote.test',
            'password' => '',
        ]);

        $this->assertSame('shared-team-password', $resolved['value']);
        $this->assertFalse($resolved['generated']);
    }

    public function test_with_nothing_supplied_a_long_password_is_generated(): void
    {
        $this->setSharedPassword('');

        $resolved = (new UserSeeder)->resolvePassword(['email' => 'admin@omnivote.test']);

        $this->assertTrue($resolved['generated']);
        $this->assertGreaterThanOrEqual(18, strlen($resolved['value']));
        $this->assertNotSame('admin123', $resolved['value']);
    }

    public function test_generated_passwords_differ_per_account(): void
    {
        $this->setSharedPassword('');

        $seeder = new UserSeeder;
        $first = $seeder->resolvePassword(['email' => 'admin@omnivote.test']);
        $second = $seeder->resolvePassword(['email' => 'teacher@omnivote.test']);

        $this->assertTrue($first['generated']);
        $this->assertTrue($second['generated']);
        $this->assertNotSame($first['value'], $second['value']);
    }

    public function test_the_committed_fixture_carries_no_passwords(): void
    {
        $path = database_path('data/users.json');
        $entries = json_decode(file_get_contents($path), true);

        $this->assertIsArray($entries);
        $this->assertNotEmpty($entries);

        foreach ($entries as $index => $entry) {
            $this->assertArrayNotHasKey(
                'password',
                $entry,
                "users.json entry #$index must not carry a password: the file is committed."
            );
        }
    }
}
