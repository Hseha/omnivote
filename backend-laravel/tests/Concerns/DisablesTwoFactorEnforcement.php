<?php

namespace Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Most feature tests exercise behaviour unrelated to two-factor auth while
 * acting as panel staff. The secure default now requires 2FA enrollment, so
 * these tests opt out explicitly — exactly as an administrator can on Settings
 * → Security — instead of every unrelated suite depending on 2FA state. The
 * secure default itself is covered by TwoFactorLoginTest.
 */
trait DisablesTwoFactorEnforcement
{
    protected function disableTwoFactorRequirement(): void
    {
        if (! Schema::hasTable('election_settings')) {
            Schema::create('election_settings', function (Blueprint $table) {
                $table->id();
                $table->string('key')->unique();
                $table->text('value')->nullable();
                $table->timestamps();
            });
        }

        DB::table('election_settings')->updateOrInsert(
            ['key' => 'admin.settings.security.twoFactorRequired'],
            ['value' => 'false', 'created_at' => now(), 'updated_at' => now()],
        );
    }
}
