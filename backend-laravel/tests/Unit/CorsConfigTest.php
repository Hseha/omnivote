<?php

namespace Tests\Unit;

use Tests\TestCase;

/**
 * `config/cors.php` is environment-driven: deployment origins come from
 * APP_URL / FRONTEND_URL plus an optional CORS_ALLOWED_ORIGINS list, and the
 * Vite dev origins are only added when APP_ENV=local. These tests evaluate the
 * config file directly under controlled env so that behaviour is locked down
 * and a production host can never silently allow-list localhost.
 */
class CorsConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function tearDown(): void
    {
        foreach ($this->original as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        $this->original = [];

        parent::tearDown();
    }

    /**
     * @param  array<string, string>  $values
     */
    private function setEnv(array $values): void
    {
        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $this->original)) {
                $this->original[$key] = getenv($key);
            }
            putenv("$key=$value");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    /**
     * @return array<int, string>
     */
    private function origins(): array
    {
        $config = require base_path('config/cors.php');

        return $config['allowed_origins'];
    }

    public function test_production_excludes_localhost_and_dedupes_app_and_frontend_urls(): void
    {
        $this->setEnv([
            'APP_ENV' => 'production',
            'APP_URL' => 'https://omnivote.example.edu',
            'FRONTEND_URL' => 'https://omnivote.example.edu',
            'CORS_ALLOWED_ORIGINS' => '',
        ]);

        $origins = $this->origins();

        $this->assertSame(['https://omnivote.example.edu'], $origins);
        $this->assertNotContains('http://localhost:5173', $origins);
        $this->assertNotContains('http://127.0.0.1:8080', $origins);
    }

    public function test_local_adds_the_vite_dev_origins(): void
    {
        $this->setEnv([
            'APP_ENV' => 'local',
            'APP_URL' => 'http://localhost:8000',
            'FRONTEND_URL' => 'http://localhost:5173',
            'CORS_ALLOWED_ORIGINS' => '',
        ]);

        $origins = $this->origins();

        $this->assertContains('http://localhost:5173', $origins);
        $this->assertContains('http://127.0.0.1:8080', $origins);
    }

    public function test_extra_origins_are_read_from_env_and_trimmed(): void
    {
        $this->setEnv([
            'APP_ENV' => 'production',
            'APP_URL' => 'https://omnivote.example.edu',
            'FRONTEND_URL' => 'https://omnivote.example.edu',
            'CORS_ALLOWED_ORIGINS' => ' https://console.example.edu , https://alt.example.edu , ',
        ]);

        $origins = $this->origins();

        $this->assertContains('https://console.example.edu', $origins);
        $this->assertContains('https://alt.example.edu', $origins);
        // Trailing/blank comma entries are dropped, never emitted as ''.
        $this->assertNotContains('', $origins);
    }
}
