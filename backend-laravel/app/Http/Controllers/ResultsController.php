<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\VoteLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public results + anonymous receipt verification.
 *
 *   GET  /api/results             — per-position tallies (voting_closed only)
 *   POST /api/results/verify      — { receipt_token } → { counted: bool }
 */
class ResultsController extends Controller
{
    public function index(): JsonResponse
    {
        $tallies = DB::table('vote_ledger')
            ->select('position_key', 'candidate_ref', DB::raw('COUNT(*) as votes'))
            ->groupBy('position_key', 'candidate_ref')
            ->orderBy('position_key')
            ->orderByDesc('votes')
            ->get()
            ->groupBy('position_key');

        $names = Candidate::query()
            ->whereIn('candidate_ref', $tallies->flatten(1)->pluck('candidate_ref'))
            ->with('position')
            ->get()
            ->keyBy('candidate_ref');

        $labels = \App\Models\Position::query()
            ->whereIn('slug', $tallies->keys())
            ->pluck('label', 'slug');

        $results = $tallies->map(function ($rows, $positionKey) use ($names, $labels) {
            return [
                'position_key' => $positionKey,
                'position_label' => $labels[$positionKey] ?? str_replace('_', ' ', ucfirst($positionKey)),
                'candidates' => $rows->map(fn ($row) => [
                    'name' => $names[$row->candidate_ref]?->user?->name ?? $row->candidate_ref,
                    'position_key' => $positionKey,
                    'votes' => (int) $row->votes,
                    'candidate_ref' => $row->candidate_ref,
                ])->values(),
            ];
        })->values();

        return response()->json(['results' => $results]);
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt_token' => ['required', 'string'],
        ]);

        $hmac = hash_hmac('sha256', $validated['receipt_token'], (string) config('app.key'));
        $counted = VoteLedger::where('receipt_hmac', $hmac)->exists();

        return response()->json(['counted' => $counted]);
    }
}