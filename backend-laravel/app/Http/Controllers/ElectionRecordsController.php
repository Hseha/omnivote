<?php

namespace App\Http\Controllers;

use App\Models\ElectionCandidateRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only review of every election cycle: the candidate roster (winners and
 * non-winners, all parties), the ballot as it was, and the cycle header —
 * when it ran, how it ended, how many people voted.
 *
 * A cycle is identified by `cycle_id`. Records captured before the cycle
 * header existed still read back: the header is optional and falls back to
 * what the roster itself knows.
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

        $cycles = $records->groupBy('cycle_id')->map(function ($rows, $cycleId) {
            $first = $rows->first();
            $header = $this->cycleHeader((string) $cycleId);
            $byParty = $rows->groupBy(fn ($r) => $r->party_name ?? '(no party)');

            return [
                'cycle_id' => $cycleId ?: null,
                'term_label' => $header['term_label'] ?: $first?->term_label ?: 'Unknown Term',
                'status' => $header['status'],
                'election_title' => $header['election_title'],
                'snapshot_at' => $header['snapshot_at'] ?? $first?->snapshot_at?->toIso8601String(),
                'closed_at' => $header['closed_at'],
                'finalized_at' => $header['finalized_at'],
                'aborted_at' => $header['aborted_at'],
                'schedule' => $header['schedule'],
                'turnout' => $header['turnout'],
                'candidates_total' => $header['candidates_total'] ?? $rows->count(),
                'seats_filled' => $header['seats_filled'] ?? (int) $rows->where('certified_winner', true)->count(),
                'total' => $rows->count(),
                'winners' => (int) $rows->where('certified_winner', true)->count(),
                'positions' => $this->cyclePositions((string) $cycleId),
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

        // Every term on record, not just the filtered ones: the filter
        // dropdown has to keep offering the terms it is not currently showing,
        // or selecting one collapses the list down to that term.
        $terms = ElectionCandidateRecord::query()
            ->whereNotNull('term_label')
            ->distinct()
            ->pluck('term_label')
            ->sort()
            ->values();

        return response()->json([
            'cycles' => $cycles,
            'filters' => [
                'parties' => $parties,
                'positions' => $positions,
                'terms' => $terms,
                'results' => ['elected', 'lost', 'pending', 'rejected', 'withdrawn'],
            ],
        ]);
    }

    /**
     * The cycle header, when one was captured.
     *
     * Records written before the cycle header existed have no header: the
     * caller falls back to what the roster knows.
     *
     * @return array<string, mixed>
     */
    private function cycleHeader(string $cycleId): array
    {
        $empty = [
            'term_label' => null,
            'status' => 'unknown',
            'election_title' => null,
            'snapshot_at' => null,
            'closed_at' => null,
            'finalized_at' => null,
            'aborted_at' => null,
            'schedule' => null,
            'turnout' => null,
            'candidates_total' => null,
            'seats_filled' => null,
        ];

        if ($cycleId === '' || ! Schema::hasTable('election_cycles')) {
            return $empty;
        }

        try {
            $row = DB::table('election_cycles')->where('cycle_id', $cycleId)->first();
        } catch (\Throwable) {
            return $empty;
        }

        if ($row === null) {
            return $empty;
        }

        return [
            'term_label' => $row->term_label,
            'status' => $row->status,
            'election_title' => $row->election_title,
            'snapshot_at' => $row->snapshot_at ? (string) $row->snapshot_at : null,
            'closed_at' => $row->closed_at ? (string) $row->closed_at : null,
            'finalized_at' => $row->finalized_at ? (string) $row->finalized_at : null,
            'aborted_at' => $row->aborted_at ? (string) $row->aborted_at : null,
            'schedule' => [
                'registration_opens_at' => $row->registration_opens_at ? (string) $row->registration_opens_at : null,
                'registration_closes_at' => $row->registration_closes_at ? (string) $row->registration_closes_at : null,
                'voting_opens_at' => $row->voting_opens_at ? (string) $row->voting_opens_at : null,
                'voting_closes_at' => $row->voting_closes_at ? (string) $row->voting_closes_at : null,
                'term_ends_at' => $row->term_ends_at ? (string) $row->term_ends_at : null,
            ],
            'turnout' => [
                'registered_students' => $row->registered_students === null ? null : (int) $row->registered_students,
                'voters_who_voted' => $row->voters_who_voted === null ? null : (int) $row->voters_who_voted,
                'votes_cast' => $row->votes_cast === null ? null : (int) $row->votes_cast,
            ],
            'candidates_total' => $row->candidates_total === null ? null : (int) $row->candidates_total,
            'seats_filled' => $row->seats_filled === null ? null : (int) $row->seats_filled,
        ];
    }

    /**
     * The ballot as it was for this cycle — every seat, including the ones
     * nobody contested.
     *
     * @return array<int, array<string, mixed>>
     */
    private function cyclePositions(string $cycleId): array
    {
        if ($cycleId === '' || ! Schema::hasTable('election_cycle_positions')) {
            return [];
        }

        try {
            $rows = DB::table('election_cycle_positions')
                ->where('cycle_id', $cycleId)
                ->orderBy('sort_order')
                ->orderBy('position_label')
                ->get();
        } catch (\Throwable) {
            return [];
        }

        return $rows->map(fn ($r) => [
            'slug' => $r->position_slug,
            'label' => $r->position_label,
            'tier' => $r->position_tier,
            'seat_count' => $r->seat_count === null ? null : (int) $r->seat_count,
            'sort_order' => $r->sort_order === null ? null : (int) $r->sort_order,
            'scope_type' => $r->scope_type,
            'scope_value' => $r->scope_value,
            'scope_type_2' => $r->scope_type_2,
            'scope_value_2' => $r->scope_value_2,
        ])->values()->all();
    }
}
