<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Phase;
use App\Models\Position;
use App\Support\TermArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

        $positions = Position::query()
            ->where('tier', 'national')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('slug');

        $candidateRefs = $tallies->flatten(1)->pluck('candidate_ref');
        $candidates = Candidate::query()
            ->whereIn('candidate_ref', $candidateRefs)
            ->with('user:id,name')
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
                // `position` is read below for the slug lookup, so it has to be
                // eager loaded too or each elected officer costs an extra query.
                ->with(['user:id,name', 'position:id,slug'])
                ->orderBy('position_id')
                ->orderBy('winner_rank')
                ->get()
                ->each(function (Candidate $candidate) use ($officers, $positions) {
                    $position = $positions->get($candidate->position?->slug);
                    if (! $position) {
                        return;
                    }

                    $officers->push([
                        'position_key' => $position->slug,
                        'position_label' => $position->label,
                        'candidate_ref' => $candidate->candidate_ref,
                        'name' => $candidate->user?->name ?? $candidate->candidate_ref,
                        'votes' => (int) $candidate->vote_total,
                        'seat_count' => $position->seat_count,
                    ]);
                });

            return response()->json(['data' => $officers->values()]);
        }

        foreach ($tallies as $positionKey => $rows) {
            $position = $positions->get($positionKey);
            if (! $position) {
                continue;
            }

            $seatCount = max(1, (int) $position->seat_count);
            foreach ($rows->take($seatCount) as $row) {
                // Archived winners are from a past term and are not returned by
                // the fallback either, even if they still lead the raw tally.
                if ($archivedRefs->contains($row->candidate_ref)) {
                    continue;
                }

                $officers->push([
                    'position_key' => $positionKey,
                    'position_label' => $position->label,
                    'candidate_ref' => $row->candidate_ref,
                    'name' => $candidates->get($row->candidate_ref)?->user?->name ?? $row->candidate_ref,
                    'votes' => (int) $row->votes,
                    'seat_count' => $seatCount,
                ]);
            }
        }

        return response()->json(['data' => $officers->values()]);
    }
}
