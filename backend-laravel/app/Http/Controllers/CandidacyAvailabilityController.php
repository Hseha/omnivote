<?php

namespace App\Http\Controllers;

use App\Models\Position;
use App\Support\SeatCap;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
 *
 * The counts are taken in the CALLER's race, not school-wide: a scoped seat
 * (the provincial posts are one race per college, the year-level seat one
 * race per college AND year level) is contested separately in every
 * electorate, so a student in one college must see that college's race — not
 * a school-wide total that would make another college's filled race look
 * like their own seat is taken. See SeatCap.
 */
class CandidacyAvailabilityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $voter = $request->user();

        $positions = Position::query()
            ->where('is_active', true)
            ->orderBy('tier')
            ->orderBy('sort_order')
            // The scope columns are NOT optional here: SeatCap reads them to
            // decide which race the caller is in, and a partially loaded model
            // would report every seat as global.
            ->get([
                'id', 'slug', 'label', 'tier', 'seat_count',
                'scope_type', 'scope_value', 'scope_type_2', 'scope_value_2',
            ])
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'label' => $p->label,
                'tier' => $p->tier,
                'seat_count' => $p->seat_count,
                'party_counts' => SeatCap::partyCounts($p, $voter),
            ]);

        return response()->json(['positions' => $positions]);
    }
}
