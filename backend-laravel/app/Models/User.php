<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\PasswordResetLink;

#[Fillable(['name', 'email', 'password', 'role', 'student_id', 'has_voted', 'year_level', 'block_number', 'department', 'course', 'is_active', 'needs_review', 'review_reason', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes', 'avatar_url', 'must_change_password'])]
#[Hidden(['password', 'remember_token', 'failed_login_attempts', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Failed logins before the account starts backing off. */
    public const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * Ceiling on the backoff window.
     *
     * SECURITY (assessment M-3): this used to be a flat hard lockout — five
     * wrong guesses parked the account for a fixed 15 minutes. Because student
     * handles are derived from names, an attacker holding a class list could
     * lock EVERY voter out with five requests each, and students had no
     * self-service recovery, so each locked voter needed a manual admin unlock.
     *
     * The behaviour is now an exponential backoff that *decays*: each further
     * failure doubles the wait, and the wait never exceeds this ceiling, so an
     * account can never be parked indefinitely by a third party. Combined with
     * student self-service password reset, a real voter is never dependent on an
     * administrator to regain access.
     */
    public const LOCKOUT_MINUTES = 15;

    /** First backoff step, in minutes (doubles per extra failure). */
    public const BACKOFF_BASE_MINUTES = 1;

    /** Longest a backoff may ever grow to, in minutes. */
    public const BACKOFF_MAX_MINUTES = 60;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'has_voted' => 'boolean',
            'is_active' => 'boolean',
            'voted_at' => 'datetime',
            'locked_until' => 'datetime',
            'needs_review' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_enabled' => 'boolean',
            'two_factor_recovery_codes' => 'array',
        ];
    }

    /** The number of failed TOTP/recovery-code attempts allowed per window. */
    public const TWO_FACTOR_MAX_ATTEMPTS = 5;

    /** How long a completed password login stays valid for the 2FA step. */
    public const TWO_FACTOR_VERIFY_TTL = 300; // 5 minutes

    /** Whether this panel account requires a second factor at login. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_enabled && filled($this->two_factor_secret);
    }

    /** List the plaintext recovery codes (used once at display/regeneration). */
    public function twoFactorRecoveryCodes(): array
    {
        $codes = $this->two_factor_recovery_codes ?? [];

        // Values are stored bcrypt-hashed; this method is only safe to call for
        // codes the caller is freshly generating, not for codes in the store.
        return array_values($codes);
    }

    /**
     * Whether the account is currently in a failed-login backoff window.
     *
     * Named for backwards compatibility with the panel's "Locked" status label
     * and the existing `unlock` endpoint; the underlying behaviour is a decaying
     * backoff rather than a hard lockout (see BACKOFF_MAX_MINUTES).
     */
    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    /**
     * Failed attempts that still count.
     *
     * Once a backoff window has elapsed the counter is treated as zero, so the
     * penalty decays on its own. Without this the raw `failed_login_attempts`
     * value only ever grew, which meant an attacker could make a victim's
     * backoff converge on the BACKOFF_MAX_MINUTES ceiling permanently — a
     * self-sustaining denial of service that no amount of waiting would clear
     * (assessment M-3). An administrator unlock or a successful sign-in still
     * hard-clears the stored value.
     */
    public function effectiveFailedAttempts(): int
    {
        if ($this->locked_until === null) {
            return (int) $this->failed_login_attempts;
        }

        // Window still running: the full count applies.
        if ($this->locked_until->isFuture()) {
            return (int) $this->failed_login_attempts;
        }

        // Window has elapsed — decayed back to zero.
        return 0;
    }

    /** Seconds remaining in the current backoff window, or 0 when not backing off. */
    public function backoffSecondsRemaining(): int
    {
        if (! $this->isLocked()) {
            return 0;
        }

        return max(0, (int) ceil(now()->diffInSeconds($this->locked_until, false)));
    }

    /**
     * How long the backoff should be after `$attempts` total failures.
     *
     * No backoff below MAX_LOGIN_ATTEMPTS, then 1, 2, 4, 8 … minutes, clamped
     * to BACKOFF_MAX_MINUTES so the penalty is always finite.
     */
    public static function backoffMinutesFor(int $attempts): int
    {
        if ($attempts < self::MAX_LOGIN_ATTEMPTS) {
            return 0;
        }

        $steps = $attempts - self::MAX_LOGIN_ATTEMPTS;
        $minutes = self::BACKOFF_BASE_MINUTES * (2 ** min($steps, 16));

        return (int) min(self::BACKOFF_MAX_MINUTES, $minutes);
    }

    /**
     * Record a failed login and extend the backoff window.
     *
     * Each additional failure doubles the wait, bounded by BACKOFF_MAX_MINUTES.
     */
    public function recordFailedLogin(): void
    {
        $this->failed_login_attempts = $this->effectiveFailedAttempts() + 1;

        $minutes = self::backoffMinutesFor((int) $this->failed_login_attempts);
        $this->locked_until = $minutes > 0 ? now()->addMinutes($minutes) : null;

        $this->save();
    }

    /** Clear backoff state (used on successful login and admin unlock). */
    public function clearLockout(): void
    {
        $this->failed_login_attempts = 0;
        $this->locked_until = null;
        $this->save();
    }

    public function candidate(): HasOne
    {
        return $this->hasOne(Candidate::class);
    }

    public function ballotDraft(): HasOne
    {
        return $this->hasOne(BallotDraft::class);
    }

    /**
     * Route the loopback broker's reset-link email through the SPA: the token
     * lands on the React Reset Password screen instead of a dead Laravel route.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new PasswordResetLink($token, (string) $this->email));
    }
}
