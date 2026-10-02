<?php

namespace Tests\Feature;

use Illuminate\Console\Command;
use Tests\TestCase;

class AssertProductionConfigCommandTest extends TestCase
{
    public function test_fails_if_the_environment_is_not_production(): void
    {
        config(['app.env' => 'local']);

        $this->artisan('security:assert-production-config')
            ->expectsOutputToContain("APP_ENV is 'local', not 'production'.")
            ->expectsOutputToContain('Refusing to deploy without APP_ENV=production.')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_check_mode_reports_a_non_production_environment_without_failing(): void
    {
        config(['app.env' => 'local']);

        $this->artisan('security:assert-production-config', ['--check' => true])
            ->expectsOutputToContain("APP_ENV is 'local', not 'production'.")
            ->expectsOutputToContain('--check given: reporting only, exiting 0.')
            ->assertSuccessful();
    }

    public function test_accepts_a_safe_production_configuration(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://omnivote.example.edu',
            'session.secure' => true,
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        $this->artisan('security:assert-production-config')
            ->expectsOutputToContain('Production configuration looks safe.')
            ->assertSuccessful();
    }
}
