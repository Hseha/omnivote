<?php

namespace App\Http\Controllers;

use App\Models\BallotDraft;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student ballot draft endpoints (Flutter "My Ballot" flow).
 *
 *   GET  /api/ballot/me          — { status, selections, receipt_token? }
 *   POST /api/ballot/me/submit   — finalizes (delegates to VoteController)
 *
 * Drafts are the only user-scoped ballot artifact; once submitted only the
 * anonymous vote_ledger + the receipt token remain.
 */
class BallotController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $draft = BallotDraft::firstOrCreate(['user_id' => $request->user()->id]);
        $draft->refresh();

        return response()->json([
            'status' => $draft->status,
            'selections' => $draft->selections ?? (object) [],
            'receipt_token' => $draft->status === 'submitted' ? $draft->receipt_token : null,
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        return app(VoteController::class)->submit($request);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'selections' => ['required', 'array'],
        ]);

        $draft = BallotDraft::firstOrCreate(['user_id' => $request->user()->id]);
        $draft->update([
            'selections' => $validated['selections'],
            'status' => 'draft',
            'submitted_at' => null,
        ]);
        $draft->refresh();

        return response()->json([
            'status' => $draft->status,
            'selections' => $draft->selections ?? (object) [],
        ]);
    }
}