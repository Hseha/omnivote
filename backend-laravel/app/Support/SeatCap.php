<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\Position;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * The seat cap — how many candidates one party may field for one position.
 *
 * ## Why the cap is counted per RACE, not per position
 *
 * A position is not always one race. `Position::scopeDimensions()` describes
 * who may vote in a seat, and a scoped seat is contested separately in every
 * electorate it covers:
 *
 *   - `governor`, `vice_governor`, the provincial posts → scoped by
 *     `department`, so every college runs its OWN provincial race;
 *   - `year_level_representative` → scoped by `department` AND `year_level`,
 *     so every (college, year) pair is its own race;
 *   - the national posts and `senator` → `global`, one race for the whole
 *     school.
 *
 * Counting the cap per (position, party) ignored that split, so a seat that
 * the app itself treats as per-college was counted school-wide: with one
 * approved Governor ASLE candidate in any college, every other college's
 * Governor ASLE seat read as taken, the student apply returned 409
 * `seat_taken`, and the admin could not approve a candidate for an open
 * race. The results and roster code already grouped a scoped seat per
 * electorate (`Position::groupKeyFor()`); this helper makes the cap agree
 * with it.
 *
 * For a global seat the race filter is a no-op, so the national tier behaves
 * exactly as before: `seat_count` candidates per party, school-wide.
 *
 * ## The rule
 *
 * ```
 * count(candidates where position_id = P AND party_name = X
 *       AND approval_status IN ('pending', 'approved')
 *       AND the candidate stands in the same race as the reference account)
 *   <= position(P).seat_count
 * ```
 *
 * `pending` and `approved` claims hold the seat; `rejected` and `withdrawn`
 * free it. A null party ("No party") is its own bucket, with the same cap.
 */
final class SeatCap
{
    /**
     * The candidates holding a seat for $position inside the race the
     * $reference account belongs to.
     *
     * $reference is the account the race is measured from: the applicant (at
     * apply time), the student a candidate belongs to (at approval time), or
     * the caller (for the availability lookup). A null reference means an
     * unknown race, which matches nothing for a scoped seat.
     */
    public static function query(Position $position, ?User $reference, ?int $exceptCandidateId = null): Builder
    {
        $query = Candidate::query()
            ->where('position_id', $position->id)
            ->whereIn('approval_status', ['pending', 'approved']);

        if ($exceptCandidateId !== null) {
            $query->where('id', '!=', $exceptCandidateId);
        }

        // A global seat is one race for the whole school: the cap stays a
        // plain per-position count.
        if ($position->isGloballyScoped()) {
            return $query;
        }

        foreach ($position->scopeDimensions() as $dim) {
            // A pinned dimension (a literal scope value) is the same for every
            // candidate, so it never narrows the race.
            if ($dim['value'] !== null) {
                continue;
            }

            if (! in_array($dim['type'], Position::SCOPE_TYPES, true)) {
                continue;
            }

            $value = $reference?->{$dim['type']};

            if (! is_string($value) || trim($value) === '') {
                // The reference has no value for this dimension, so it is not
                // in any race — nothing can be counted against it.
                return $query->whereRaw('1 = 0');
            }

            $query->whereHas('user', function (Builder $owner) use ($dim, $value) {
                $owner->whereRaw(
                    'LOWER(TRIM(users.'.$dim['type'].')) = ?',
                    [mb_strtolower(trim($value))]
                );
            });
        }

        return $query;
    }

    /**
     * How many candidates hold the $party seat in the reference's race.
     */
    public static function count(Position $position, ?string $party, ?User $reference, ?int $exceptCandidateId = null): int
    {
        $query = self::query($position, $reference, $exceptCandidateId);

        if ($party === null) {
            $query->whereNull('candidates.party_name');
        } else {
            $query->whereRaw('UPPER(TRIM(candidates.party_name)) = ?', [strtoupper(trim($party))]);
        }

        return $query->count();
    }

    /**
     * Whether the $party seat in the reference's race is already taken.
     */
    public static function isTaken(Position $position, ?string $party, ?User $reference, ?int $exceptCandidateId = null): bool
    {
        return self::count($position, $party, $reference, $exceptCandidateId) >= (int) $position->seat_count;
    }

    /**
     * Per-party candidate counts in the reference's race, keyed by party name.
     * A candidate with no party is bucketed under `_none`, which can never
     * collide with a canonical party name.
     *
     * @return array<string, int>
     */
    public static function partyCounts(Position $position, ?User $reference): array
    {
        $rows = self::query($position, $reference)
            ->selectRaw('candidates.party_name, COUNT(*) as c')
            ->groupBy('candidates.party_name')
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $key = $row->party_name ?? '_none';
            $counts[$key] = ($counts[$key] ?? 0) + (int) $row->c;
        }

        return $counts;
    }
}
