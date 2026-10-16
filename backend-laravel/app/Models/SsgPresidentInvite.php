<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A one-time, expiring password-setup token for a newly granted SSG President.
 *
 * SECURITY: the credential-handoff design never transmits a password (neither
 * by email nor in an API response). Instead the grant mints a CSPRNG token,
 * stores only its SHA-256 hash here, and points the President at the in-app
 * setup screen through their notification feed. The token is single-use
 * (`used_at`) and time-limited (`expires_at`) so a leaked invite link cannot be
 * replayed; the raw value exists only in memory between issuing and delivery.
 */
class SsgPresidentInvite extends Model
{
    /** How long a freshly issued setup token stays valid. */
    public const TTL_MINUTES = 60;

    protected $fillable = ['user_id', 'issued_by', 'token_hash', 'expires_at', 'used_at'];

    protected $casts = [
        'expires_at' => 'datetime',
        'used_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /**
     * Mint a fresh invite for a user, invalidating any outstanding ones so only
     * the most recently issued link can ever be redeemed.
     *
     * @return string the plaintext token to embed in the in-app link
     */
    public static function issue(User $user, ?int $issuedBy = null): string
    {
        // A 64-char CSPRNG token: 256 bits of entropy, so it is infeasible to
        // guess and safe to store as a bare SHA-256 digest (no bcrypt needed at
        // this length, and the digest keeps lookup a single indexed query).
        $token = bin2hex(random_bytes(32));

        static::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        static::create([
            'user_id' => $user->id,
            'issued_by' => $issuedBy,
            'token_hash' => self::hash($token),
            'expires_at' => now()->addMinutes(self::TTL_MINUTES),
            'used_at' => null,
        ]);

        return $token;
    }

    /** Deterministic, index-friendly digest of a plaintext token. */
    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Resolve a still-valid invite for the given plaintext token, or null when
     * the token is unknown, already redeemed, or expired.
     */
    public static function findValid(string $token): ?self
    {
        $invite = static::query()
            ->where('token_hash', self::hash($token))
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->first();

        return $invite;
    }

    /** Burn the invite so the same link can never be redeemed twice. */
    public function redeem(): void
    {
        $this->forceFill(['used_at' => now()])->save();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
