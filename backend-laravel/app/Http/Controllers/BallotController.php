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
 * A draft is the only user-scoped ballot artifact. Once submitted it keeps just
 * `status='submitted'`: the choices and the receipt are deliberately NOT stored
 * next to the user id, because the ledger's receipt HMAC would otherwise join
 * the voter to their ballot (security assessment H-1). The receipt is handed
 * back once in the submit response and kept by the voter's device.
 */
class BallotController extends Controller
{
    /** Most position keys a single draft may hold. */
    private const MAX_DRAFT_POSITIONS = 40;

    /** Most candidate refs stored against one position in a draft. */
    private const MAX_DRAFT_REFS_PER_POSITION = 20;

    public function me(Request $request): JsonResponse
    {
        $draft = BallotDraft::firstOrCreate(['user_id' => $request->user()->id]);
        $draft->refresh();

        return response()->json([
            'status' => $draft->status,
            // Empty once submitted: the submitted ballot is not retrievable
            // from the server by design (H-1). The receipt is likewise only
            // ever returned by the submit call itself.
            'selections' => $draft->selections ?? (object) [],
            'receipt_token' => null,
        ]);
    }

    public function submit(Request $request): JsonResponse
    {
        return app(VoteController::class)->submit($request);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        // Shape and size caps (security assessment L-4). The draft body was
        // accepted as an unconstrained `array`, so a student could store an
        // arbitrarily large or arbitrarily deep payload in their own draft row.
        // A real ballot is `{ position_slug: candidate_ref | candidate_ref[] }`,
        // so each key must look like a position slug and each leaf a short ref.
        $validated = $request->validate([
            'selections' => ['required', 'array', 'max:'.self::MAX_DRAFT_POSITIONS],
            'selections.*' => ['required'],
        ]);

        $problem = $this->selectionsProblem($validated['selections']);
        if ($problem !== null) {
            return response()->json(['message' => $problem], 422);
        }

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

    /**
     * Validates the `{ slug: ref | ref[] }` draft shape. Returns a
     * human-readable reason on the first violation, or null when acceptable.
     */
    private function selectionsProblem(array $selections): ?string
    {
        foreach ($selections as $slug => $value) {
            $slug = (string) $slug;

            if (strlen($slug) > 64 || ! preg_match('/^[a-z0-9_]+$/', $slug)) {
                return "'{$slug}' is not a valid position key.";
            }

            $refs = is_array($value) ? $value : [$value];

            if (count($refs) > self::MAX_DRAFT_REFS_PER_POSITION) {
                return "Position '{$slug}' accepts at most ".self::MAX_DRAFT_REFS_PER_POSITION.' selection(s).';
            }

            foreach ($refs as $ref) {
                if (! is_string($ref) || $ref === '' || strlen($ref) > 64) {
                    return "Position '{$slug}' has an invalid candidate reference.";
                }
            }
        }

        return null;
    }
}
