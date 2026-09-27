<?php

namespace Tests\Unit;

use App\Models\User;
use Tests\TestCase;

/**
 * The is_active flag and the failed-login lockout are the two account-state
 * gates shared by both sign-in flows, so their model-level behaviour is
 * covered here without touching the database.
 */
class UserAccountStatusTest extends TestCase
{
    public function test_is_active_is_cast_to_a_boolean(): void
    {
        $this->assertFalse((new User(['is_active' => 0]))->is_active);
        $this->assertTrue((new User(['is_active' => 1]))->is_active);
        $this->assertTrue((new User(['is_active' => true]))->is_active);
    }

    public function test_is_locked_is_false_without_a_lockout_timestamp(): void
    {
        $this->assertFalse((new User)->isLocked());
    }

    public function test_is_locked_is_true_while_the_lockout_is_in_the_future(): void
    {
        $user = new User;
        $user->locked_until = now()->addMinutes(User::LOCKOUT_MINUTES);

        $this->assertTrue($user->isLocked());
    }

    public function test_is_locked_is_false_once_the_lockout_has_expired(): void
    {
        $user = new User;
        $user->locked_until = now()->subMinute();

        $this->assertFalse($user->isLocked());
    }
}
