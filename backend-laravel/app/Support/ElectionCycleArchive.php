<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\ElectionCandidateRecord;
use App\Models\Phase;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Per-cycle election archive.
 *
 * `ElectionRecordArchive` freezes the candidate roster. This adds the layer
 * around it: the cycle header (when the election ran, how it ended, how many
 * people voted) and the ballot as it was (the seats, including seats nobody
 * contested).
 *
 * A cycle is identified by `election_cycle_id` in `election_settings` — a
 * stable id, NOT a timestamp. A timestamp id would make every call look like a
 * new cycle, and the archive has to be able to tell "capture this cycle" from
 * "enrich the cycle I already captured".
 *
 * Every entry point is best-effort: an archive failure must never break a
 * phase read, a result finalization or a clean-slate reset. Failures are
 * logged and swallowed, exactly like `TermArchive` and the announcement
 * notifier do.
 */
class ElectionCycleArchive
{
    /** `election_settings` key holding the current cycle's id. */
    public const SETTING_CYCLE_ID = 'election_cycle_id';

    /** The polls closed; the roster and schedule were captured. */
    public const STATUS_CLOSED = 'voting_closed';

    /** An administrator declared the winners; the tally was folded in. */
    public const STATUS_FINALIZED = 'finalized';

    /** The cycle was reset before it could conclude. */
    public const STATUS_ABORTED = 'aborted';

    /**
     * Serialises the three capture paths against each other.
     *
     * The phase is derived from the clock, so two requests can observe the
     * same transition at the same moment — every student app polls the status
     * endpoint, and they all wake up together when the deadline passes. Under
     * the lock the loser waits, finds the cycle already captured, and skips
     * it. The capture itself is idempotent per cycle, so the lock only has to
     * stop two writers racing, not prevent a repeat.
     */
    private const CAPTURE_LOCK = 'election-cycle-capture';

    /** How long a capture may hold the lock, and how long a caller waits. */
    private const LOCK_TTL_SECONDS = 60;
    private const LOCK_WAIT_SECONDS = 5;

    /**
     * Capture the cycle that just closed: header, roster, ballot and turnout.
     *
     * Called from the `voting_closed` phase transition. Idempotent — a cycle
     * is captured once, no matter how many times the phase is re-read.
     *
     * @return int number of candidate records written
     */
    public static function snapshotClosedCycle(): int
    {
        return self::withCaptureLock(fn () => self::captureClosedCycle());
    }

    /**
     * The capture itself. Runs under the capture lock.
     */
    private static function captureClosedCycle(): int
    {
        try {
            if (! self::tablesReady()) {
                return 0;
            }

            $cycleId = self::resolveCycleId();
            if ($cycleId === null) {
                return 0;
            }

            $existing = self::cycleRow($cycleId);
            if ($existing !== null && $existing->status === self::STATUS_FINALIZED) {
                // Already concluded — nothing to capture.
                return 0;
            }

            $schedule = self::currentSchedule();
            $roster = ElectionRecordArchive::snapshot($schedule['term_label'], $cycleId);
            $positions = self::snapshotPositions($cycleId);

            self::writeCycleRow($cycleId, [
                'status' => self::STATUS_CLOSED,
                'term_label' => $schedule['term_label'],
                'election_title' => $schedule['election_title'],
                'registration_opens_at' => $schedule['registration_opens_at'],
                'registration_closes_at' => $schedule['registration_closes_at'],
                'voting_opens_at' => $schedule['voting_opens_at'],
                'voting_closes_at' => $schedule['voting_closes_at'],
                'term_ends_at' => $schedule['term_ends_at'],
                'results_published' => false,
                'registered_students' => $schedule['registered_students'],
                'voters_who_voted' => $schedule['voters_who_voted'],
                'votes_cast' => $schedule['votes_cast'],
                'candidates_total' => $roster,
                'closed_at' => $existing->closed_at ?? now(),
            ]);

            Log::info('ElectionCycleArchive: captured closed cycle '.$cycleId, [
                'candidates' => $roster,
                'positions' => $positions,
            ]);

            return $roster;
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::snapshotClosedCycle skipped: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Fold the final tally into the captured cycle.
     *
     * Called from the results finalization, after the vote ledger has been
     * tallied and the winners certified. The tally only exists at that point —
     * `candidates.vote_total` is written by the finalization itself — so a
     * cycle captured at `voting_closed` holds zeros until this runs.
     *
     * Creates the cycle if it was never captured, so a finalization is never
     * silently lost.
     *
     * @return int number of candidate records updated
     */
    public static function finalizeCycle(): int
    {
        return self::withCaptureLock(fn () => self::finalizeCycleLocked());
    }

    /**
     * The finalization itself. Runs under the capture lock.
     */
    private static function finalizeCycleLocked(): int
    {
        try {
            if (! self::tablesReady()) {
                return 0;
            }

            $cycleId = self::resolveCycleId();
            if ($cycleId === null) {
                return 0;
            }

            $existing = self::cycleRow($cycleId);
            $schedule = self::currentSchedule();

            // The roster may never have been captured (the admin finalized
            // without the console ever noticing the polls had closed). Capture
            // it now so the outcome is on record either way.
            $roster = ElectionRecordArchive::snapshot($schedule['term_label'], $cycleId);

            $updated = self::enrichRoster($cycleId);
            $seats = self::seatsFilled();

            self::writeCycleRow($cycleId, [
                'status' => self::STATUS_FINALIZED,
                'term_label' => $schedule['term_label'],
                'election_title' => $schedule['election_title'],
                'registration_opens_at' => $schedule['registration_opens_at'],
                'registration_closes_at' => $schedule['registration_closes_at'],
                'voting_opens_at' => $schedule['voting_opens_at'],
                'voting_closes_at' => $schedule['voting_closes_at'],
                'term_ends_at' => $schedule['term_ends_at'],
                'results_published' => true,
                'registered_students' => $schedule['registered_students'],
                'voters_who_voted' => $schedule['voters_who_voted'],
                'votes_cast' => $schedule['votes_cast'],
                'candidates_total' => $roster > 0 ? $roster : ($existing->candidates_total ?? null),
                'seats_filled' => $seats,
                'closed_at' => $existing->closed_at ?? now(),
                'finalized_at' => now(),
            ]);

            Log::info('ElectionCycleArchive: finalized cycle '.$cycleId, [
                'roster' => $roster,
                'updated' => $updated,
                'seats_filled' => $seats,
            ]);

            return $updated;
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::finalizeCycle skipped: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Record a cycle that was reset before it could conclude.
     *
     * The roster is captured here, before the reset deletes the non-winner
     * applications, so the cycle is still reviewable afterwards.
     *
     * @param  array<string, mixed>|null  $schedule  the schedule that was in
     *                                          force, when the caller already
     *                                          captured it (the reset clears
     *                                          the timeline before archiving)
     * @param  bool  $strict  When true, a failure to capture is re-thrown
     *                        instead of being swallowed, so the caller can
     *                        refuse to delete the live rows it failed to
     *                        record.
     * @return int number of candidate records written
     */
    public static function abortCycle(?array $schedule = null, bool $strict = false): int
    {
        return self::withCaptureLock(fn () => self::abortCycleLocked($schedule, $strict), $strict);
    }

    /**
     * The abort itself. Runs under the capture lock.
     */
    private static function abortCycleLocked(?array $schedule = null, bool $strict = false): int
    {
        try {
            if (! self::tablesReady()) {
                if ($strict) {
                    throw new ElectionArchiveFailed(
                        'The election archive tables are missing, so this cycle cannot be archived.'
                    );
                }

                return 0;
            }

            $cycleId = self::resolveCycleId();
            if ($cycleId === null) {
                return 0;
            }

            $existing = self::cycleRow($cycleId);
            if ($existing !== null && $existing->status === self::STATUS_FINALIZED) {
                // The cycle concluded and was archived as Past Terms. A later
                // reset does not rewrite that history.
                return 0;
            }

            $schedule = $schedule ?? self::currentSchedule();
            $roster = ElectionRecordArchive::snapshot($schedule['term_label'], $cycleId, $strict);

            // Freeze the ballot too, exactly as a cycle that reached the polls
            // does. Without this a cycle reset mid-registration keeps the
            // roster but loses "the ballot as it was" — the positions, their
            // seat counts and their scopes — so the review is half a record.
            self::snapshotPositions($cycleId);

            // A cycle that had already closed keeps that status — the polls
            // did close, and the archive holds its roster. Only a cycle that
            // never got that far is 'aborted'.
            $status = ($existing !== null && $existing->status === self::STATUS_CLOSED)
                ? self::STATUS_CLOSED
                : self::STATUS_ABORTED;

            self::writeCycleRow($cycleId, [
                'status' => $status,
                'term_label' => $schedule['term_label'],
                'election_title' => $schedule['election_title'],
                'registration_opens_at' => $schedule['registration_opens_at'],
                'registration_closes_at' => $schedule['registration_closes_at'],
                'voting_opens_at' => $schedule['voting_opens_at'],
                'voting_closes_at' => $schedule['voting_closes_at'],
                'term_ends_at' => $schedule['term_ends_at'],
                'results_published' => false,
                'registered_students' => $schedule['registered_students'],
                'voters_who_voted' => $schedule['voters_who_voted'],
                'votes_cast' => $schedule['votes_cast'],
                'candidates_total' => $roster,
                'closed_at' => $existing->closed_at ?? null,
                'aborted_at' => now(),
            ]);

            return $roster;
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::abortCycle skipped: '.$e->getMessage());

            if ($strict) {
                throw $e instanceof ElectionArchiveFailed
                    ? $e
                    : new ElectionArchiveFailed(
                        'The candidate record for this cycle could not be archived: '.$e->getMessage(),
                        0,
                        $e
                    );
            }

            return 0;
        }
    }

    /**
     * Run a capture under the shared lock.
     *
     * A lock that cannot be taken in time is not an error worth failing on:
     * somebody else is capturing the same cycle, and their capture is the
     * one that counts. The timer backstop picks up anything genuinely missed.
     *
     * @param  callable(): int  $callback
     */
    private static function withCaptureLock(callable $callback, bool $strict = false): int
    {
        try {
            return Cache::lock(self::CAPTURE_LOCK, self::LOCK_TTL_SECONDS)
                ->block(self::LOCK_WAIT_SECONDS, $callback);
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive: capture lock unavailable: '.$e->getMessage());

            if ($strict) {
                // A lock that cannot be taken means the capture did not
                // happen. In strict mode that is as fatal as a failed write:
                // the caller is about to delete the rows the capture would
                // have recorded.
                throw $e instanceof ElectionArchiveFailed
                    ? $e
                    : new ElectionArchiveFailed(
                        'The cycle could not be archived: '.$e->getMessage(),
                        0,
                        $e
                    );
            }

            return 0;
        }
    }

    /**
     * Forget the current cycle id so the next election starts a fresh cycle.
     */
    public static function clearCycleId(): void
    {
        try {
            DB::table('election_settings')->where('key', self::SETTING_CYCLE_ID)->delete();
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::clearCycleId skipped: '.$e->getMessage());
        }
    }

    /** The current cycle id, or null when no cycle is in progress. */
    public static function currentCycleId(): ?string
    {
        try {
            $value = DB::table('election_settings')
                ->where('key', self::SETTING_CYCLE_ID)
                ->value('value');
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /**
     * Which cycle do the live candidates belong to?
     *
     * The candidates are what make a cycle a cycle — the schedule can be
     * edited mid-election, and the window can be cleared, but the people who
     * ran are the same people. So the cycle is resolved by matching the live
     * candidates against the roster that was captured for them.
     *
     * A cycle whose candidates have all been archived (the winners of a past
     * term) does not match: those rows are excluded, so a new election never
     * inherits the previous one's records.
     */
    private static function cycleIdForCandidates(): ?string
    {
        if (! Schema::hasTable('candidates')
            || ! Schema::hasTable('election_candidate_records')
            || ! Schema::hasColumn('election_candidate_records', 'candidate_ref')) {
            return null;
        }

        try {
            $query = Candidate::query()->whereNotNull('candidate_ref');

            if (Schema::hasColumn('candidates', 'archived_at')) {
                $query->whereNull('archived_at');
            }

            $refs = $query->pluck('candidate_ref');
        } catch (\Throwable) {
            return null;
        }

        if ($refs->isEmpty()) {
            return null;
        }

        try {
            $row = ElectionCandidateRecord::query()
                ->whereIn('candidate_ref', $refs)
                ->orderByDesc('id')
                ->first();
        } catch (\Throwable) {
            return null;
        }

        return $row?->cycle_id;
    }

    /**
     * How many candidates are still live (not archived).
     */
    private static function liveCandidateCount(): int
    {
        if (! Schema::hasTable('candidates')) {
            return 0;
        }

        try {
            $query = Candidate::query();

            if (Schema::hasColumn('candidates', 'archived_at')) {
                $query->whereNull('archived_at');
            }

            return $query->count();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * The cycle this archive operation belongs to.
     *
     * Resolution order:
     *  1. The cycle whose captured roster holds the live candidates.
     *  2. A brand-new cycle, when there are live candidates that no roster
     *     holds — that is a new election, and it must not be folded into the
     *     previous cycle's records.
     *  3. The current cycle, for an election with no candidates at all.
     */
    private static function resolveCycleId(): ?string
    {
        $byCandidates = self::cycleIdForCandidates();
        if ($byCandidates !== null) {
            return $byCandidates;
        }

        if (self::liveCandidateCount() > 0) {
            return self::mintCycleId();
        }

        return self::currentCycleId() ?? self::mintCycleId();
    }

    /**
     * Mint and store a new cycle id.
     */
    private static function mintCycleId(): ?string
    {
        try {
            $cycleId = (string) Str::uuid();

            DB::table('election_settings')->updateOrInsert(
                ['key' => self::SETTING_CYCLE_ID],
                ['value' => $cycleId, 'updated_at' => now()],
            );

            return $cycleId;
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::mintCycleId failed: '.$e->getMessage());

            return null;
        }
    }

    /**
     * The schedule the cycle ran under, plus the turnout counters.
     *
     * Reads `Phase::timeline()` — never `Phase::current()`, which would
     * re-enter the phase transition this archive is called from.
     *
     * Public because the reset captures the schedule BEFORE it clears the
     * timeline: by the time the archive runs, the window is already gone.
     *
     * @return array<string, mixed>
     */
    public static function currentSchedule(): array
    {
        $timeline = Phase::timeline();

        return [
            'term_label' => TermArchive::labelFor(),
            'election_title' => self::settingValue('title'),
            'registration_opens_at' => self::parseDate($timeline['registration_opens_at'] ?? null),
            'registration_closes_at' => self::parseDate($timeline['registration_closes_at'] ?? null),
            'voting_opens_at' => self::parseDate($timeline['voting_opens_at'] ?? null),
            'voting_closes_at' => self::parseDate($timeline['voting_closes_at'] ?? null),
            'term_ends_at' => TermArchive::termEndsAt(),
            'registered_students' => self::registeredStudents(),
            'voters_who_voted' => self::votersWhoVoted(),
            'votes_cast' => self::votesCast(),
        ];
    }

    /**
     * A raw `election_settings` value.
     */
    private static function settingValue(string $key): ?string
    {
        try {
            $value = DB::table('election_settings')->where('key', $key)->value('value');
        } catch (\Throwable) {
            return null;
        }

        return is_string($value) && trim($value) !== '' ? $value : null;
    }

    private static function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How many eligible voter accounts exist. Mirrors the count the student
     * app is shown after the polls close.
     */
    private static function registeredStudents(): ?int
    {
        if (! Schema::hasTable('users')) {
            return null;
        }

        try {
            return User::query()->voters()->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How many of them cast a ballot. A count, never a list: the vote ledger
     * has no user foreign key on purpose (anonymity, H-1) and a per-voter
     * turnout list would rebuild exactly the link that design removes.
     */
    private static function votersWhoVoted(): ?int
    {
        if (! Schema::hasTable('users') || ! Schema::hasColumn('users', 'has_voted')) {
            return null;
        }

        try {
            return User::query()->where('has_voted', true)->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How many selections were recorded in the anonymous ledger.
     */
    private static function votesCast(): ?int
    {
        if (! Schema::hasTable('vote_ledger')) {
            return null;
        }

        try {
            return DB::table('vote_ledger')->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * How many seats are filled (certified winners).
     */
    private static function seatsFilled(): ?int
    {
        if (! Schema::hasTable('candidates')
            || ! Schema::hasColumn('candidates', 'certified_winner')) {
            return null;
        }

        try {
            return Candidate::query()->where('certified_winner', true)->count();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Freeze the ballot: every position, including the seats nobody contested.
     */
    private static function snapshotPositions(string $cycleId): int
    {
        if (! Schema::hasTable('positions')) {
            return 0;
        }

        try {
            $positions = Position::query()->orderBy('sort_order')->get();
        } catch (\Throwable) {
            return 0;
        }

        try {
            DB::table('election_cycle_positions')->where('cycle_id', $cycleId)->delete();

            $stamp = now();
            $rows = [];

            foreach ($positions as $position) {
                $rows[] = [
                    'cycle_id' => $cycleId,
                    'position_slug' => $position->slug,
                    'position_label' => $position->label,
                    'position_tier' => $position->tier,
                    'seat_count' => $position->seat_count,
                    'sort_order' => $position->sort_order,
                    'scope_type' => $position->scope_type,
                    'scope_value' => $position->scope_value,
                    'scope_type_2' => $position->scope_type_2,
                    'scope_value_2' => $position->scope_value_2,
                    'is_active' => (bool) $position->is_active,
                    'snapshot_at' => $stamp,
                ];
            }

            if ($rows !== []) {
                DB::table('election_cycle_positions')->insert($rows);
            }

            return count($rows);
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::snapshotPositions skipped: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Fold the final tally into the captured roster.
     *
     * The roster rows are matched on `candidate_ref` — the same opaque
     * reference the ballot uses, so the update is exact even when two
     * candidates share a name.
     */
    private static function enrichRoster(string $cycleId): int
    {
        if (! Schema::hasTable('candidates')
            || ! Schema::hasTable('election_candidate_records')
            || ! Schema::hasColumn('election_candidate_records', 'candidate_ref')) {
            return 0;
        }

        try {
            $records = ElectionCandidateRecord::query()
                ->where('cycle_id', $cycleId)
                ->whereNotNull('candidate_ref')
                ->get()
                ->keyBy('candidate_ref');
        } catch (\Throwable) {
            return 0;
        }

        if ($records->isEmpty()) {
            return 0;
        }

        try {
            $candidates = Candidate::query()
                ->whereNotNull('candidate_ref')
                ->get()
                ->keyBy('candidate_ref');
        } catch (\Throwable) {
            return 0;
        }

        $updated = 0;

        foreach ($records as $ref => $record) {
            $candidate = $candidates->get($ref);
            if ($candidate === null) {
                continue;
            }

            ElectionCandidateRecord::query()
                ->where('id', $record->id)
                ->update([
                    'election_status' => $candidate->election_status,
                    'vote_total' => (int) ($candidate->vote_total ?? 0),
                    'winner_rank' => $candidate->winner_rank,
                    'certified_winner' => (bool) ($candidate->certified_winner ?? false),
                ]);

            $updated++;
        }

        return $updated;
    }

    /**
     * The stored cycle row, or null.
     */
    private static function cycleRow(string $cycleId): ?object
    {
        try {
            return DB::table('election_cycles')->where('cycle_id', $cycleId)->first();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Insert or update the cycle header.
     *
     * Best-effort and independent of the roster: an install (or a test
     * schema) without the cycle tables still gets its candidate records.
     *
     * @param  array<string, mixed>  $attributes
     */
    private static function writeCycleRow(string $cycleId, array $attributes): bool
    {
        if (! Schema::hasTable('election_cycles')) {
            return false;
        }

        try {
            $attributes['cycle_id'] = $cycleId;
            $attributes['updated_at'] = now();

            $existing = self::cycleRow($cycleId);

            if ($existing === null) {
                $attributes['created_at'] = now();
                DB::table('election_cycles')->insert($attributes);

                return true;
            }

            DB::table('election_cycles')->where('cycle_id', $cycleId)->update($attributes);

            return true;
        } catch (\Throwable $e) {
            Log::warning('ElectionCycleArchive::writeCycleRow skipped: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Are the tables this archive needs present? Older installs without them
     * are a no-op, exactly like the other archive helpers.
     *
     * Only the roster tables are required. The cycle tables are optional: the
     * candidate records are the archive that predates them, and losing the
     * header must never cost the roster.
     */
    private static function tablesReady(): bool
    {
        return Schema::hasTable('candidates')
            && Schema::hasTable('election_candidate_records');
    }
}
