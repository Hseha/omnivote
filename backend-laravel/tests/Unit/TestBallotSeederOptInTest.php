<?php

namespace Tests\Unit;

use Database\Seeders\TestBallotSeeder;
use PHPUnit\Framework\TestCase;

/**
 * The demo-ballot opt-in flag must be exact: only the literal "true"
 * (case-insensitive, surrounding whitespace tolerated) opens the
 * TestBallotSeeder production guard. Anything else — "1", "yes", empty,
 * absent — keeps production seeding refused.
 */
class TestBallotSeederOptInTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['ALLOW_DEMO_SEED'], $_SERVER['ALLOW_DEMO_SEED']);
        putenv('ALLOW_DEMO_SEED');
        putenv('allow_demo_seed');

        parent::tearDown();
    }

    public function test_opt_in_is_closed_by_default(): void
    {
        $this->assertFalse((new TestBallotSeeder())->demoSeedAllowed());
    }

    public function test_only_literal_true_opens_the_opt_in(): void
    {
        $seeder = new TestBallotSeeder();

        foreach (['true', 'TRUE', ' True '] as $value) {
            $_ENV['ALLOW_DEMO_SEED'] = $value;
            $this->assertTrue($seeder->demoSeedAllowed(), "expected '{$value}' to opt in");
            unset($_ENV['ALLOW_DEMO_SEED']);
        }

        foreach (['1', 'yes', 'on', '', 'false', '0'] as $value) {
            $_ENV['ALLOW_DEMO_SEED'] = $value;
            $this->assertFalse($seeder->demoSeedAllowed(), "expected '{$value}' to stay closed");
            unset($_ENV['ALLOW_DEMO_SEED']);
        }
    }
}
