<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Phase;
use App\Models\Position;
use App\Support\TermArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * The certified officer roster, shared by the SSG President web console and
 * the mobile projection.
 *
 * The response is a flat `data` array of seated officers in ballot order
 * (national tier first, then provincial; positions in their configured
 * `sort_order`). Each row carries the fields the console needs to rebuild the
 * tier -> position -> electorate hierarchy:
 *
 *   - `tier`                 — the position's tier (national / provincial)
 *   - `position_key`         — the position slug, the grouping key
 *   - `position_base_label`  — the position label on its own
 *   - `position_scope`       — the electorate label (null for a global seat)
 *   - `position_scoped`      — whether the position is scoped at all
 *   - `seat_count`           — seats contested per electorate
 *   - `is_elected`           — true for confirmed winners, false for the
 *                              provisional top-N fallback used before a
 *                              finalize has run
 */
class SsgOfficerController extends Controller
{
    public function index(): JsonResponse
    {
        if (Phase::current()?->name !== 'voting_closed') {
            return response()->json([
                'message' => 'The officer roster is available after voting is closed.',
            ], 403);
        }

        // A term that has ended archives its winners, so the current roster
        // must not keep listing last term's officers as still serving.
        TermArchive::runIfDue();

        $tallies = DB::table('vote_ledger')
            ->select('position_key', 'candidate_ref', DB::raw('COUNT(*) as votes'))
            ->groupBy('position_key', 'candidate_ref')
            ->orderBy('position_key')
            ->orderByDesc('votes')
            ->get()
            ->groupBy('position_key');

        // Every tier seats here (national + provincial): the console roster
        // and the shared mobile projection both show the full winner list.
        $positions = Position::query()
            ->orderBy('sort_order')
            ->get()
            ->keyBy('slug');

        // National seats are listed first, then provincial, each group in its
        // configured order. The tiers reuse overlapping sort_order values, so
        // the partition happens here in PHP — a SQL FIELD()/CASE ordering
        // would not be portable across the mysql production and sqlite test
        // databases.
        $positionOrder = $positions
            ->sortBy(fn (Position $p) => sprintf('%d-%04d', $p->tier === 'provincial' ? 1 : 0, (int) $p->sort_order))
            ->values()
            ->mapWithKeys(fn (Position $p, int $i) => [$p->slug => $i]);
        $seatOrder = fn ($rows) => $rows
            ->sortBy(fn (array $row) => $positionOrder[$row['position_key']] ?? PHP_INT_MAX)
            ->values();

        $candidateRefs = $tallies->flatten(1)->pluck('candidate_ref');
        $candidates = Candidate::query()
            ->whereIn('candidate_ref', $candidateRefs)
            ->with('user:id,name,year_level,department,course')
            ->get()
            ->keyBy('candidate_ref');

        // Elected candidates from a previous, archived term no longer sit in
        // office, so they are dropped alongside candidates that never won.
        $archivedRefs = $candidates
            ->filter(fn (Candidate $c) => $c->archived_at !== null)
            ->keys();
        $candidates = $candidates->reject(fn (Candidate $c) => $c->archived_at !== null);

        // Prefer the official finalized tally: only candidates actually marked
        // `elected` take a seat ("pending" = not a winner, "tied" = unresolved,
        // so neither can appear on the roster). If results were never finalized
        // (all rows still pending), fall back to the raw top-N by votes.
        $finalized = $candidates->first(fn (Candidate $c) => $c->election_status !== 'pending');

        $officers = collect();

        if ($finalized) {
            Candidate::query()
                ->where('election_status', 'elected')
                ->whereNull('archived_at')
                ->whereIn('position_id', $positions->pluck('id'))
                // `position` is read below for the slug lookup and its scope, so
                // it has to be eager loaded too or each elected officer costs an
                // extra query.
                ->with(['user:id,name,year_level,department,course', 'position'])
                ->orderBy('position_id')
                ->orderBy('winner_rank')
                ->get()
                ->each(function (Candidate $candidate) use ($officers, $positions) {
                    $position = $positions->get($candidate->position?->slug);
                    if (! $position) {
                        return;
                    }

                    $scope = $position->scopeLabelFor($candidate->user);

                    $officers->push([
                        'position_key' => $position->slug,
                        'position_label' => $scope ? "{$position->label} — {$scope}" : $position->label,
                        // The base label and the scope are also returned
                        // separately so the console can rebuild the
                        // position -> electorate hierarchy without parsing the
                        // display string above.
                        'position_base_label' => $position->label,
                        'position_scope' => $scope,
                        'position_scoped' => ! $position->isGloballyScoped(),
                        'candidate_ref' => $candidate->candidate_ref,
                        'name' => $candidate->user?->name ?? $candidate->candidate_ref,
                        'votes' => (int) $candidate->vote_total,
                        'seat_count' => $position->seat_count,
                        'tier' => $position->tier,
                        // This branch only runs for candidates explicitly marked
                        // `elected`, so every row here is a confirmed winner.
                        'is_elected' => true,
                    ]);
                });

            return response()->json(['data' => $seatOrder($officers)]);
        }

        foreach ($tallies as $positionKey => $rows) {
            $position = $positions->get($positionKey);
            if (! $position) {
                continue;
            }

            $seatCount = max(1, (int) $position->seat_count);

            // Group by electorate: a scoped seat (e.g. Year Level Representative,
            // scoped by college and year) has one leader per group, not one
            // overall. Archived winners are dropped from their group too.
            $groups = $rows
                ->reject(fn ($row) => $archivedRefs->contains($row->candidate_ref))
                ->groupBy(fn ($row) => $position->groupKeyFor($candidates->get($row->candidate_ref)?->user));

            foreach ($groups as $groupRows) {
                $owner = $candidates->get($groupRows->first()->candidate_ref)?->user;
                $scope = $position->scopeLabelFor($owner);
                $label = $scope ? "{$position->label} — {$scope}" : $position->label;

                foreach ($groupRows->take($seatCount) as $row) {
                    $officers->push([
                        'position_key' => $positionKey,
                        'position_label' => $label,
                        'position_base_label' => $position->label,
                        'position_scope' => $scope,
                        'position_scoped' => ! $position->isGloballyScoped(),
                        'candidate_ref' => $row->candidate_ref,
                        'name' => $candidates->get($row->candidate_ref)?->user?->name ?? $row->candidate_ref,
                        'votes' => (int) $row->votes,
                        'seat_count' => $seatCount,
                        'tier' => $position->tier,
                        // No finalize has run, so these are the provisional
                        // top-N by votes rather than confirmed winners.
                        'is_elected' => false,
                    ]);
                }
            }
        }

        return response()->json(['data' => $seatOrder($officers)]);
    }
}
