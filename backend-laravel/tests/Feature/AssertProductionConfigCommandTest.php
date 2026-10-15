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
            'session.encrypt' => true,
            'sanctum.stateful' => ['omnivote.example.edu'],
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        $this->artisan('security:assert-production-config')
            ->expectsOutputToContain('Production configuration looks safe.')
            ->assertSuccessful();
    }

    public function test_reports_an_unencrypted_session_store(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://omnivote.example.edu',
            'session.secure' => true,
            'session.encrypt' => false,
            'sanctum.stateful' => ['omnivote.example.edu'],
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        $this->artisan('security:assert-production-config')
            ->expectsOutputToContain('SESSION_ENCRYPT must be true')
            ->assertExitCode(Command::FAILURE);
    }

    public function test_reports_a_missing_stateful_panel_host(): void
    {
        config([
            'app.env' => 'production',
            'app.debug' => false,
            'app.url' => 'https://omnivote.example.edu',
            'session.secure' => true,
            'session.encrypt' => true,
            'sanctum.stateful' => ['localhost', '127.0.0.1'],
            'app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        ]);

        $this->artisan('security:assert-production-config')
            ->expectsOutputToContain('SANCTUM_STATEFUL_DOMAINS must include the panel host')
            ->assertExitCode(Command::FAILURE);
    }
}
