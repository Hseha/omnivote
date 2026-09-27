<?php

namespace App\Support;

/**
 * Deterministic election tally resolver.
 *
 * Pure function: given a position's per-candidate vote totals and its number
 * of seats, decide who wins outright and which candidates are tied for a seat.
 *
 * Rules applied:
 *   - Winners fill the top `seatCount` slots by descending votes.
 *   - Candidates with equal votes that straddle the seat boundary are ALL
 *     flagged as tied — no arbitrary ordering, the admin breaks the tie.
 *   - A candidate group is only "elected" when it fits entirely inside the
 *     remaining seats; any group larger than the remaining seats is a tie.
 *   - Zero-vote groups never win; seats that can't be filled stay empty
 *     (the quota simply isn't met).
 */
class ElectionTally
{
    /**
     * @param  array<int, array{candidate_ref: string, votes: int}>  $rows
     * @return array{
     *     elected: array<string, int>,   // candidate_ref => winner_rank (1-based)
     *     tied: string[]                 // candidate_refs that need an admin tie-break
     * }
     */
    public static function resolve(array $rows, int $seatCount): array
    {
        $seatCount = max(1, $seatCount);

        // Sort by votes descending so the resolved order of the maps below is
        // deterministic (ties keep the row input order — who got there first
        // is irrelevant to the outcome, but it makes results reproducible).
        $ordered = collect($rows)
            ->filter(fn (array $row) => (int) $row['votes'] > 0)
            ->sortByDesc(fn (array $row) => (int) $row['votes']);

        // Group by identical vote totals, highest first.
        $byVotes = $ordered->mapWithKeys(fn (array $row) => [$row['candidate_ref'] => (int) $row['votes']]);

        $groups = $byVotes->groupBy(fn (int $votes) => $votes, preserveKeys: true);

        $elected = [];
        $tied = [];
        $remaining = $seatCount;
        $nextRank = 1;

        foreach ($groups as $group) {
            if ($remaining <= 0) {
                break; // All seats are decided; everyone below is a runner-up.
            }

            $refs = $group->keys()->all();

            if (count($refs) <= $remaining) {
                foreach ($refs as $ref) {
                    $elected[$ref] = $nextRank++;
                }
                $remaining -= count($refs);

                continue;
            }

            // Boundary tie: this equal-vote group is larger than the slots that
            // remain, so no subset can be picked without arbitrariness.
            foreach ($refs as $ref) {
                $tied[] = $ref;
            }
            $remaining = 0;
        }

        return [
            'elected' => $elected,
            'tied' => $tied,
        ];
    }
}
