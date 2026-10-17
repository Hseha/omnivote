<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\PasswordResetLink;
use App\Support\AppSettings;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'student_id', 'has_voted', 'year_level', 'block_number', 'department', 'course', 'is_active', 'needs_review', 'review_reason', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes', 'avatar_url', 'must_change_password', 'ssg_setup_pending', 'archived_at'])]
#[Hidden(['password', 'remember_token', 'failed_login_attempts', 'two_factor_secret', 'two_factor_enabled', 'two_factor_recovery_codes'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Failed logins before the account starts backing off. */
    public const MAX_LOGIN_ATTEMPTS = 5;

    /**
     * The voter roles: every account that may cast a ballot and hold a
     * candidacy. A `candidate` is just a student with a live, approved
     * candidacy (see App\Support\CandidateRoleSync); both share the full
     * student feature set, so scope turnouts/gates on these roles rather than
     * the `student` literal.
     *
     * @var list<string>
     */
    public const VOTER_ROLES = ['student', 'candidate'];

    /**
     * Roles that keep the full Flutter student experience.
     *
     * This is NOT the same set as VOTER_ROLES. The seated `ssg_president` is
     * still a student in the app (dashboard, results, announcements,
     * notifications, profile) but is deliberately excluded from VOTER_ROLES so
     * they are never counted in turnout totals (scopeVoters) or silently
     * re-derived by CandidateRoleSync::sync, which would demote the seat back to
     * `student`/`candidate`. Gate mobile student routes on these roles; keep
     * voter/turnout math on VOTER_ROLES.
     *
     * @var list<string>
     */
    public const STUDENT_ROLES = ['student', 'candidate', 'ssg_president'];

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
            'ssg_setup_pending' => 'boolean',
            'archived_at' => 'datetime',
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

        // Settings → Security exposes a login-backoff kill switch. When an
        // admin turns it off, still count failures (the panel surfaces them and
        // the rate limiter still applies) but never open a lockout window.
        if (! AppSettings::security('loginBackoffEnabled', true)) {
            $this->locked_until = null;
            $this->save();

            return;
        }

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

    /** Restrict a query to voter accounts (student + candidate). */
    public function scopeVoters(Builder $query): Builder
    {
        return $query->whereIn('role', self::VOTER_ROLES);
    }

    /**
     * Resolve an account by the credential it signs in with: either the email
     * address/handle around which student accounts are built, or the optional
     * `username` a seated SSG President chooses for the governance console.
     *
     * Login paths accept both so a promoted President can use the same handle on
     * mobile and web without duplicating accounts.
     */
    public function scopeForLogin(Builder $query, string $identifier): Builder
    {
        $identifier = trim($identifier);

        // Usernames are stored lowercase; emails are matched verbatim (student
        // handles are already lowercase slugs, staff addresses are as entered).
        return $query->where(function (Builder $inner) use ($identifier): void {
            $inner->where('email', $identifier)
                ->orWhere('username', mb_strtolower($identifier));
        });
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
     * In-app notification rows for this account.
     *
     * Named `userNotifications` rather than `notifications` because the
     * `Notifiable` trait already declares `notifications()` for Laravel's own
     * polymorphic notifications table; the two are unrelated stores.
     */
    public function userNotifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
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
