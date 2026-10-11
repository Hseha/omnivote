<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/candidacy/availability — per-party candidate counts per position.
 *
 * Consumed by the candidacy apply screen so a student can see which
 * (position, party) seats are already full *before* filling the form, instead
 * of discovering it on a submit 409. The server stays the single source of
 * truth: this returns raw counts and the client derives "taken"
 * (`party_counts[party] >= seat_count`).
 *
 * Null-party (independent) candidates are counted under the literal key
 * `_none`, which can never collide with a canonical party name (those are
 * validated against the parties table).
 */
class CandidacyAvailabilityController extends Controller
{
    public function index(): JsonResponse
    {
        $counts = Candidate::query()
            ->whereIn('approval_status', ['pending', 'approved'])
            ->whereNotNull('position_id')
            ->groupBy('position_id', 'party_name')
            ->select('position_id', 'party_name', DB::raw('COUNT(*) as c'))
            ->get()
            ->groupBy('position_id')
            ->map(fn ($rows) => $rows->mapWithKeys(fn ($row) => [
                (string) ($row->party_name ?? '_none') => (int) $row->c,
            ]));

        $positions = Position::query()
            ->where('is_active', true)
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->get(['id', 'slug', 'label', 'tier', 'seat_count'])
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'label' => $p->label,
                'tier' => $p->tier,
                'seat_count' => $p->seat_count,
                'party_counts' => $counts->get($p->id, collect())->all(),
            ]);

        return response()->json(['positions' => $positions]);
    }
}
