<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Phase;
use App\Models\Position;
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

        $tallies = DB::table('vote_ledger')
            ->select('position_key', 'candidate_ref', DB::raw('COUNT(*) as votes'))
            ->groupBy('position_key', 'candidate_ref')
            ->orderBy('position_key')
            ->orderByDesc('votes')
            ->get()
            ->groupBy('position_key');

        $positions = Position::query()
            ->where('tier', 'school')
            ->orderBy('sort_order')
            ->get()
            ->keyBy('slug');

        $candidateRefs = $tallies->flatten(1)->pluck('candidate_ref');
        $candidates = Candidate::query()
            ->whereIn('candidate_ref', $candidateRefs)
            ->with('user:id,name')
            ->get()
            ->keyBy('candidate_ref');

        $officers = collect();

        foreach ($tallies as $positionKey => $rows) {
            $position = $positions->get($positionKey);
            if (! $position) {
                continue;
            }

            $seatCount = max(1, (int) $position->seat_count);
            foreach ($rows->take($seatCount) as $row) {
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