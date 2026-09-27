<?php

namespace App\Http\Controllers;

use App\Models\Position;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class PositionController extends Controller
{
    /**
     * List active ballot positions (both tiers) for the Flutter candidate/
     * ballot flows.
     */
    public function index(): JsonResponse
    {
        $positions = Position::query()
            ->where('is_active', true)
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'label' => $p->label,
                'name' => $p->label,
                'tier' => $p->tier,
                'seat_count' => $p->seat_count,
                'description' => $p->label,
                'is_active' => $p->is_active,
                // Electorate scope (assessment M-4), surfaced so the client can
                // hide a seat the voter is not eligible for rather than letting
                // them fill in a ballot the server will reject.
                'scope_type' => $p->scope_type ?? Position::SCOPE_GLOBAL,
                'scope_value' => $p->scope_value,
            ]);

        return response()->json($positions);
    }

    /**
     * PATCH /api/admin/positions/{position} — update a single ballot position.
     *
     * Labels, seat counts, and the tier can be corrected after seeding. The
     * slug stays immutable (ballot selections and candidate applications key
     * on it), so it is edited here rather than through the config dump.
     *
     * The electorate scope (assessment M-4) is editable, but only while the seat
     * has no ballots against it. Scope is validated at ballot-submission time
     * and the ledger is deliberately anonymous, so there is no way to retroactively
     * identify and discard votes cast under the old scope — letting an admin
     * change it mid-election would silently invalidate an unknown set of
     * already-counted ballots.
     */
    public function update(Request $request, Position $position): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'required', 'string', 'max:255'],
            'seat_count' => ['sometimes', 'required', 'integer', 'min:1', 'max:50'],
            'tier' => ['sometimes', 'required', Rule::in(['national', 'provincial'])],
            'is_active' => ['sometimes', 'boolean'],
            'scope_type' => ['sometimes', 'required', 'string', Rule::in(Position::SCOPE_TYPES)],
            'scope_value' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $scopeChanging = array_key_exists('scope_type', $validated) || array_key_exists('scope_value', $validated);

        if ($scopeChanging && $this->hasRecordedBallots($position)) {
            return response()->json([
                'message' => 'The electorate scope cannot be changed once ballots have been cast for this position.',
            ], 409);
        }

        if (array_key_exists('label', $validated)) {
            $position->label = trim($validated['label']);
        }
        if (array_key_exists('seat_count', $validated)) {
            $position->seat_count = $validated['seat_count'];
        }
        if (array_key_exists('tier', $validated)) {
            // Moving a position between tiers: keep one canonical row rather
            // than duplicating the slug elsewhere.
            $position->tier = $validated['tier'];
        }
        if (array_key_exists('is_active', $validated)) {
            $position->is_active = $validated['is_active'];
        }
        if (array_key_exists('scope_type', $validated)) {
            $position->scope_type = $validated['scope_type'];
        }
        if (array_key_exists('scope_value', $validated)) {
            $position->scope_value = $validated['scope_value'] !== null
                ? trim($validated['scope_value'])
                : null;
        }
        $position->save();

        return response()->json([
            'message' => 'Position updated.',
            'position' => [
                'id' => $position->id,
                'slug' => $position->slug,
                'title' => $position->label,
                'label' => $position->label,
                'tier' => $position->tier,
                'seat_count' => $position->seat_count,
                'active' => $position->is_active,
                'scope_type' => $position->scope_type ?? Position::SCOPE_GLOBAL,
                'scope_value' => $position->scope_value,
            ],
        ]);
    }

    /** Whether any ledger row has been written for this position's slug. */
    private function hasRecordedBallots(Position $position): bool
    {
        // Defensive: an install that has not migrated the ledger yet (or a
        // focused unit test) must not 500 on an unrelated settings edit.
        if (! Schema::hasTable('vote_ledger')) {
            return false;
        }

        return DB::table('vote_ledger')
            ->where('position_key', $position->slug)
            ->exists();
    }
}
