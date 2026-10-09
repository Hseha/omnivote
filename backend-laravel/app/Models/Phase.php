<?php

namespace App\Models;

use App\Support\Notifier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class Phase extends Model
{
    protected $table = 'phases';

    protected $fillable = ['name', 'description', 'is_active'];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * The currently-active phase.
     *
     * When an election window is configured, the phase is a pure function of
     * the timeline and the current time — it is recomputed on every read, so a
     * transition happens automatically as wall-clock time crosses a boundary
     * (registration → voting_open → voting_closed) with no cron or admin save.
     * The `registration_closed` phase fills the gaps: before Registration
     * Opens and between Registration Closes and Voting Opens, where candidacy
     * applications are blocked.
     *
     * When no window is configured at all, no phase is active (clients show
     * "Not Configured" and phase-gated endpoints reject), matching the rule
     * that nothing is open until an admin defines a schedule.
     */
    public static function current(): ?self
    {
        $derived = static::derivedName(static::timeline());

        $active = static::where('is_active', true)->first();

        if ($derived === null) {
            // No timeline → nothing is live. The stale active row from a
            // previous manual save must not leak a phase, so clear it — but
            // only when there actually is one to clear (writes are cheap).
            if ($active !== null) {
                static::query()->update(['is_active' => false]);
            }

            return null;
        }

        // Unchanged phase: reuse the active row as-is. This is the hot path
        // (every phase-gated request + the 30s status poll), so avoiding the
        // setCurrent() write under it matters a lot in production.
        if ($active !== null && $active->name === $derived) {
            return $active;
        }

        static::setCurrent($derived);

        return static::where('name', $derived)->first();
    }

    /**
     * Time-driven phase reconciliation.
     *
     * Kept for callers that only persist a row; `current()` already derives
     * the phase from the timeline, so this is a no-op passthrough.
     */
    public static function reconcile(?self $phase): void
    {
        if ($phase === null) {
            return;
        }

        $derived = static::derivedName(static::timeline());
        if ($derived === null || $derived === $phase->name) {
            return;
        }

        static::setCurrent($derived);
    }

    /**
     * The configured election window as the phase logic consumes it.
     *
     * Prefers the canonical four-key timeline written by Settings → Voting
     * Windows (`registration_opens_at`, `registration_closes_at`,
     * `voting_opens_at`, `voting_closes_at`). When none of those exist yet
     * (Election Setup-only installs wrote just `voting_opens_at` = cycle start
     * and `voting_closes_at`), those legacy keys are mapped onto the same
     * shape so the phase still advances: the legacy start is treated as the
     * registration-open, registration-close, and voting-open boundary.
     */
    public static function timeline(): array
    {
        $rows = DB::table('election_settings')
            ->whereIn('key', [
                'registration_opens_at',
                'registration_closes_at',
                'voting_opens_at',
                'voting_closes_at',
            ])
            ->pluck('value', 'key');

        $canonical = [
            'registration_opens_at' => $rows['registration_opens_at'] ?? null,
            'registration_closes_at' => $rows['registration_closes_at'] ?? null,
            'voting_opens_at' => $rows['voting_opens_at'] ?? null,
            'voting_closes_at' => $rows['voting_closes_at'] ?? null,
        ];

        if (array_filter($canonical)) {
            return $canonical;
        }

        $legacyStart = $rows['voting_opens_at'] ?? null;
        $legacyClose = $rows['voting_closes_at'] ?? null;

        return [
            'registration_opens_at' => $legacyStart,
            'registration_closes_at' => $legacyStart,
            'voting_opens_at' => $legacyStart,
            'voting_closes_at' => $legacyClose,
        ];
    }

    /**
     * The phase implied by the configured window at the current moment.
     * Returns null when no window is configured at all.
     *
     * Strict, gap-filling timeline:
     *   - before Registration Opens                    → registration_closed
     *   - Registration Opened .. Registration Closes   → registration
     *   - Registration Closes .. Voting Opens          → registration_closed
     *   - Voting Opens .. Voting Closes                → voting_open
     *   - Voting Closes and beyond                     → voting_closed
     */
    public static function derivedName(array $dates): ?string
    {
        $now = now();
        $registrationOpens = self::parseDateTime($dates['registration_opens_at'] ?? null);
        $registrationCloses = self::parseDateTime($dates['registration_closes_at'] ?? null);
        $votingOpens = self::parseDateTime($dates['voting_opens_at'] ?? null);
        $votingCloses = self::parseDateTime($dates['voting_closes_at'] ?? null);

        if (! $registrationOpens && ! $registrationCloses && ! $votingOpens && ! $votingCloses) {
            return null;
        }

        // Before registration opens the window simply isn't live.
        if ($registrationOpens && $now->lt($registrationOpens)) {
            return 'registration_closed';
        }
        if ($registrationCloses && $now->lt($registrationCloses)) {
            return 'registration';
        }

        // Registration window done; voting not yet open (or never starts).
        if ($votingOpens && $now->lt($votingOpens)) {
            return 'registration_closed';
        }

        // Polls closed: results become visible.
        if ($votingCloses && $now->gte($votingCloses)) {
            return 'voting_closed';
        }

        // No voting window has ever been scheduled. Registration can stay open
        // when it has no close boundary (mirroring the "no close configured"
        // convention below), otherwise the waiting gap just extends — a real
        // cycle must never surface a fake "voting_open" here.
        if (! $votingOpens) {
            return $registrationOpens && ! $registrationCloses
                ? 'registration'
                : 'registration_closed';
        }

        // Between voting open and close (or no close configured): voting live.
        return 'voting_open';
    }

    private static function order(string $name): int
    {
        return match ($name) {
            'registration' => 0,
            'voting_open' => 1,
            'voting_closed' => 2,
            'registration_closed' => -1,
            default => -1,
        };
    }

    private static function parseDateTime(mixed $value): ?Carbon
    {
        $value = is_string($value) ? trim($value) : null;
        if (! $value) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Activates the named phase and deactivates every other phase atomically.
     *
     * `forceFill` + `save` guarantees the write even when the target row was
     * already loaded as active (an `update()` on an already-true boolean would
     * otherwise short-circuit as "not dirty" and leave every phase inactive).
     *
     * @throws ModelNotFoundException
     */
    public static function setCurrent(string $name): ?self
    {
        $phase = static::where('name', $name)->firstOrFail();

        $previous = static::where('is_active', true)->value('name');

        DB::transaction(function () use ($phase) {
            static::query()->update(['is_active' => false]);
            $phase->forceFill(['is_active' => true])->save();
        });

        // Physical transitions are the only moments worth a notification. The
        // no-op guard matters: `current()` already short-circuits an unchanged
        // phase, but a manual call with the same name must not re-notify.
        if ($previous !== $name) {
            static::announce($name);
        }

        return $phase->fresh();
    }

    /**
     * Fan a lifecycle notification out to students when the active phase
     * reaches a milestone. Best-effort — `Notifier` swallows failures so a
     * transient DB issue can never break phase reconciliation.
     */
    private static function announce(string $name): void
    {
        [$title, $body, $link] = match ($name) {
            'voting_open' => ['Voting is now open', 'Cast your ballot before the polls close.', '/vote-now'],
            'voting_closed' => ['Voting has closed', 'Thank you for voting. Results will be published soon.', '/results'],
            default => [null, null, null],
        };

        if ($title === null) {
            return;
        }

        Notifier::notifyStudents('info', $title, $body, $link);
    }
}
