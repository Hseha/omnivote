<?php

namespace Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DisablesTwoFactorEnforcement;
use Tests\TestCase;

/**
 * Public branding endpoint (/api/branding).
 *
 * The endpoint always returns the default accent colors so unauthenticated
 * surfaces (login screen, browser accents) always work. But a client that
 * renders theme-pack accents must know whether a school actually configured a
 * color, so the payload carries explicit `primaryConfigured` /
 * `secondaryConfigured` flags ("was this key ever stored?"), mirroring the
 * mobile app's `Branding.primaryConfigured`.
 */
class BrandingEndpointTest extends TestCase
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

        Schema::create('election_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function test_unconfigured_branding_returns_defaults_with_flags_off(): void
    {
        $this->getJson('/api/branding')->assertOk()->assertJson([
            'branding' => [
                'siteName' => 'OmniVote',
                'primaryColor' => '#2563eb',
                'secondaryColor' => '#64748b',
                'primaryConfigured' => false,
                'secondaryConfigured' => false,
            ],
        ]);
    }

    public function test_a_configured_accent_sets_its_configured_flag(): void
    {
        DB::table('election_settings')->insert([
            [
                'key' => 'admin.settings.branding.primaryColor',
                'value' => '#14b8a6',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'key' => 'admin.settings.branding.secondaryColor',
                'value' => '#0d9488',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->getJson('/api/branding')->assertOk()->assertJson([
            'branding' => [
                'primaryColor' => '#14b8a6',
                'secondaryColor' => '#0d9488',
                'primaryConfigured' => true,
                'secondaryConfigured' => true,
            ],
        ]);
    }
}
