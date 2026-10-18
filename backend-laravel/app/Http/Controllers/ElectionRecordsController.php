<?php

namespace App\Http\Controllers;

use App\Models\ElectionCandidateRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Read-only review of every candidate from every reset election cycle
 * (winners and non-winners, all parties), preserved after clean-slate resets.
 */
class ElectionRecordsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ElectionCandidateRecord::query()
            ->orderByDesc('snapshot_at')
            ->orderBy('term_label')
            ->orderBy('position_tier')
            ->orderBy('position_sort_order')
            ->orderBy('position_label')
            ->orderBy('party_name')
            ->orderBy('user_name');

        $party = $request->query('party');
        if ($party) {
            $query->where('party_name', $party);
        }

        $position = $request->query('position');
        if ($position) {
            $query->where('position_label', $position);
        }

        $result = $request->query('result');
        if ($result) {
            $query->where('election_status', $result);
        }

        $term = $request->query('term');
        if ($term) {
            $query->where('term_label', $term);
        }

        $records = $query->get();

        $terms = $records->groupBy('term_label')->map(function ($rows, $label) {
            $first = $rows->first();
            $byParty = $rows->groupBy(fn ($r) => $r->party_name ?? '(no party)');

            return [
                'term_label' => $label ?: 'Unknown Term',
                'snapshot_at' => $first?->snapshot_at?->toIso8601String(),
                'cycle_id' => $first?->cycle_id ?? null,
                'total' => $rows->count(),
                'winners' => (int) $rows->where('certified_winner', true)->count(),
                'parties' => $byParty->map(function ($partyRows, $partyName) {
                    return [
                        'party' => $partyName,
                        'count' => $partyRows->count(),
                        'candidates' => $partyRows->values()->map(fn ($r) => [
                            'name' => $r->user_name ?? $r->student_id ?? '—',
                            'student_id' => $r->student_id,
                            'department' => $r->department,
                            'year_level' => $r->year_level,
                            'position' => $r->position_label ?: $r->position_slug,
                            'tier' => $r->position_tier,
                            'result' => $r->election_status,
                            'winner_rank' => $r->winner_rank,
                            'vote_total' => (int) $r->vote_total,
                            'certified_winner' => (bool) $r->certified_winner,
                            'slogan' => $r->slogan,
                        ]),
                    ];
                })->values(),
            ];
        })->values();

        $parties = ElectionCandidateRecord::query()
            ->whereNotNull('party_name')
            ->distinct()
            ->pluck('party_name')
            ->sort()
            ->values();

        $positions = ElectionCandidateRecord::query()
            ->whereNotNull('position_label')
            ->distinct()
            ->pluck('position_label')
            ->sort()
            ->values();

        return response()->json([
            'terms' => $terms,
            'filters' => [
                'parties' => $parties,
                'positions' => $positions,
                'results' => ['elected', 'lost', 'pending', 'rejected', 'withdrawn'],
            ],
        ]);
    }
}
