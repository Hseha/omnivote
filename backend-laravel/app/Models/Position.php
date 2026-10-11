<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ballot seat.
 *
 * `scope_type` / `scope_value` (and an optional second dimension in
 * `scope_type_2` / `scope_value_2`) define WHO may vote in this seat (security
 * assessment M-4). A seat may be unscoped ('global'), scoped on one axis, or —
 * for the Year Level Representative — scoped on two axes at once (college AND
 * year level).
 *
 * Without them, `year_level_representative` was open to the whole student body:
 * the server validated that a candidate belonged to the position, but never
 * that the *voter* did.
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
        'scope_type_2',
        'scope_value_2',
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

    /**
     * The ordered electorate-scope dimensions this seat is restricted by.
     *
     * Global seats return an empty list. Each entry is `['type' => ..., 'value'
     * => ...]`, where a null `value` means "the voter's/candidate's own value for
     * that attribute" and a non-null value pins the dimension literally.
     *
     * @return array<int, array{type: string, value: ?string}>
     */
    public function scopeDimensions(): array
    {
        $raw = [
            [$this->scope_type, $this->scope_value],
            [$this->scope_type_2, $this->scope_value_2],
        ];

        $dims = [];
        foreach ($raw as [$type, $value]) {
            $type = is_string($type) ? strtolower(trim($type)) : '';
            if ($type === '' || $type === self::SCOPE_GLOBAL) {
                continue;
            }
            if (! in_array($type, self::SCOPE_TYPES, true)) {
                continue;
            }

            $value = is_string($value) && trim($value) !== '' ? trim($value) : null;
            $dims[] = ['type' => $type, 'value' => $value];
        }

        return $dims;
    }

    /**
     * The user attribute this position's (first) scope is evaluated against.
     *
     * Retained for callers that only care about the primary axis; a seat can now
     * carry a second dimension, so prefer {@see scopeDimensions()}.
     */
    public function scopeAttribute(): ?string
    {
        return $this->scopeDimensions()[0]['type'] ?? null;
    }

    public function isGloballyScoped(): bool
    {
        return $this->scopeDimensions() === [];
    }

    /**
     * The value a voter must match to be eligible for the primary dimension.
     *
     * A literal `scope_value` wins; when it is empty the seat is scoped to the
     * voter's OWN value for the attribute. Kept for single-dimension callers;
     * `voterIsInScope()` evaluates every dimension.
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
     * Returns true for a global seat. For a scoped seat EVERY dimension must
     * match; a voter whose attribute is unknown (never imported with a year
     * level, say) is refused rather than allowed, so a missing profile cannot
     * become a way around the restriction.
     */
    public function voterIsInScope(?User $voter): bool
    {
        $dims = $this->scopeDimensions();

        if ($dims === []) {
            return true;
        }

        if ($voter === null) {
            return false;
        }

        foreach ($dims as $dim) {
            $voterValue = $voter->{$dim['type']};

            if ($voterValue === null || $voterValue === '') {
                return false;
            }

            $required = $dim['value'] ?? (string) $voterValue;

            if (! $this->valuesMatch((string) $voterValue, $required)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether a candidate (owned by `$owner`) stands in the same electorate as
     * `$voter`.
     *
     * A global seat accepts anyone. For every scoped dimension the required
     * value MUST be derived from the voter, not from the candidate: feeding the
     * candidate's own value back makes the check compare the candidate against
     * itself and always pass — precisely the cross-electorate election the check
     * exists to stop.
     */
    public function candidateIsInScope(?User $owner, ?User $voter): bool
    {
        $dims = $this->scopeDimensions();

        if ($dims === []) {
            return true;
        }

        if ($owner === null || $voter === null) {
            return false;
        }

        foreach ($dims as $dim) {
            $voterValue = $voter->{$dim['type']};
            $required = $dim['value']
                ?? (($voterValue !== null && $voterValue !== '') ? (string) $voterValue : null);
            $ownerValue = $owner->{$dim['type']};

            if ($required === null || $ownerValue === null || $ownerValue === '') {
                return false;
            }

            if (! $this->valuesMatch((string) $ownerValue, $required)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The grouping key that turns one position into one contested seat per
     * electorate. Global seats yield a single (empty) group; a two-dimension
     * seat yields one group per (college, year).
     *
     * Values are lowercased and trimmed so casing drift cannot split a group.
     */
    public function groupKeyFor(?User $owner): string
    {
        $dims = $this->scopeDimensions();

        if ($dims === []) {
            return '';
        }

        $parts = [];
        foreach ($dims as $dim) {
            $value = $dim['value'];
            if ($value === null) {
                $raw = $owner?->{$dim['type']};
                $value = ($raw !== null && $raw !== '') ? (string) $raw : null;
            }

            $parts[] = $dim['type'].'='.($value === null ? '?' : mb_strtolower(trim($value)));
        }

        return implode('|', $parts);
    }

    /**
     * A short human label for the scope group, e.g. "College of Computer
     * Studies · Year 3". Null for a global seat or when no value is known.
     */
    public function scopeLabelFor(?User $owner): ?string
    {
        $dims = $this->scopeDimensions();

        if ($dims === []) {
            return null;
        }

        $parts = [];
        foreach ($dims as $dim) {
            $value = $dim['value'];
            if ($value === null) {
                $raw = $owner?->{$dim['type']};
                $value = ($raw !== null && $raw !== '') ? (string) $raw : null;
            }

            if ($value === null) {
                continue;
            }

            $parts[] = match ($dim['type']) {
                'year_level' => 'Year '.$value,
                default => $value,
            };
        }

        return $parts === [] ? null : implode(' · ', $parts);
    }

    /** Case- and whitespace-insensitive comparison of two scope values. */
    public function valuesMatch(string $a, string $b): bool
    {
        $normalise = fn (string $v): string => mb_strtolower(trim($v));

        return $normalise($a) === $normalise($b);
    }
}
