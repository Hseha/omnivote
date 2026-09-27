<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ballot seat.
 *
 * `scope_type` / `scope_value` define WHO may vote in this seat (security
 * assessment M-4). Without them, `year_level_representative` was open to the
 * whole student body: the server validated that a candidate belonged to the
 * position, but never that the *voter* did.
 */
class Position extends Model
{
    /** Unrestricted — anyone eligible to vote may contest/vote this seat. */
    public const SCOPE_GLOBAL = 'global';

    /**
     * Scopes the backend can actually evaluate.
     *
     * Each maps to a column on `users`, so the voter and the candidate can both
     * be checked. A 'province' scope is intentionally absent: no such column
     * exists, and a scope that cannot be evaluated is worse than none because it
     * looks enforced.
     */
    public const SCOPE_TYPES = [
        self::SCOPE_GLOBAL,
        'year_level',
        'department',
        'course',
    ];

    protected $fillable = [
        'slug',
        'label',
        'tier',
        'scope_type',
        'scope_value',
        'seat_count',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'seat_count' => 'integer',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->where('approval_status', 'approved');
    }

    public function allCandidates(): HasMany
    {
        return $this->hasMany(Candidate::class);
    }

    /** The user attribute this position's scope is evaluated against. */
    public function scopeAttribute(): ?string
    {
        return $this->isGloballyScoped() ? null : $this->scope_type;
    }

    public function isGloballyScoped(): bool
    {
        return ($this->scope_type ?? self::SCOPE_GLOBAL) === self::SCOPE_GLOBAL;
    }

    /**
     * The value a voter must match to be eligible for this seat.
     *
     * A literal `scope_value` wins; when it is empty the seat is scoped to the
     * voter's OWN value for the attribute (the year-level representative case:
     * you vote for, and are voted for by, your own year level).
     */
    public function requiredValueFor(?string $voterValue): ?string
    {
        $literal = is_string($this->scope_value) ? trim($this->scope_value) : '';

        if ($literal !== '') {
            return $literal;
        }

        return ($voterValue !== null && $voterValue !== '') ? (string) $voterValue : null;
    }

    /**
     * Whether a voter holding `$voter` may vote in this seat.
     *
     * Returns true for a global seat. For a scoped seat the voter's attribute
     * must match the required value; a voter whose attribute is unknown (never
     * imported with a year level, say) is refused rather than allowed, so a
     * missing profile cannot become a way around the restriction.
     */
    public function voterIsInScope(?User $voter): bool
    {
        if ($this->isGloballyScoped()) {
            return true;
        }

        $attribute = $this->scopeAttribute();

        if ($attribute === null || $voter === null) {
            return false;
        }

        $voterValue = $voter->{$attribute};
        $required = $this->requiredValueFor($voterValue !== null ? (string) $voterValue : null);

        if ($required === null || $voterValue === null || $voterValue === '') {
            return false;
        }

        return $this->valuesMatch((string) $voterValue, $required);
    }

    /** Case- and whitespace-insensitive comparison of two scope values. */
    public function valuesMatch(string $a, string $b): bool
    {
        $normalise = fn (string $v): string => mb_strtolower(trim($v));

        return $normalise($a) === $normalise($b);
    }
}
