<?php

namespace App\Http\Controllers;

use App\Models\BallotDraft;
use App\Models\Candidate;
use App\Models\Position;
use App\Models\User;
use App\Models\VoteLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Anonymous vote submission.
 *
 * POST /api/vote
 * POST /api/ballot/me/submit   (aliases to the same handler)
 *
 * Guarantees:
 *   - single ACID transaction, voter row locked (no double cast);
 *   - candidate refs validated against approved candidates per position;
 *   - choices are written only to the decoupled `vote_ledger` (no user FK);
 *   - the user's `has_voted` flag flips atomically with the ledger rows;
 *   - the user-scoped ballot draft is marked submitted + receipt stored.
 */
class VoteController extends Controller
{
    public function submit(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'selections' => ['required', 'array', 'min:1'],
            // selections: { position_slug: candidate_ref | [candidate_ref, ...] }
        ]);

        $selections = $validated['selections'];

        // ---- Pre-flight: resolve positions and validate every candidate ref.
        $payload = $this->resolveSelections($selections);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $receipt = bin2hex(random_bytes(16));
        $receiptHmac = hash_hmac('sha256', $receipt, (string) config('app.key'));

        $result = DB::transaction(function () use ($user, $payload, $receipt, $receiptHmac) {
            $voter = User::whereKey($user->id)->lockForUpdate()->first();

            if (! $voter) {
                return response()->json(['message' => 'Voter not found'], 404);
            }

            if ($voter->has_voted) {
                return response()->json(['message' => 'Already voted'], 409);
            }

            foreach ($payload as $item) {
                foreach ($item['refs'] as $ref) {
                    VoteLedger::create([
                        'position_key' => $item['position_key'],
                        'candidate_ref' => $ref,
                        'receipt_hmac' => $receiptHmac,
                        'ledger_sequence' => null,
                    ]);
                }
            }

            $voter->has_voted = true;
            $voter->voted_at = now();
            $voter->save();

            BallotDraft::updateOrCreate(
                ['user_id' => $voter->id],
                [
                    'selections' => collect($payload)->mapWithKeys(
                        fn ($item) => [$item['position_key'] => $item['refs']]
                    )->all(),
                    'status' => 'submitted',
                    'receipt_token' => $receipt,
                    'submitted_at' => now(),
                ],
            );

            return ['receipt' => $receipt];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json($result, 201);
    }

    /**
     * Converts `{ slug: ref | ref[] }` into a normalized payload, confirming
     * every ref belongs to an approved candidate in that position. Returns a
     * 422 JsonResponse on the first invalid entry.
     */
    private function resolveSelections(array $selections): array|JsonResponse
    {
        $slugs = array_keys($selections);
        $positions = Position::query()
            ->whereIn('slug', $slugs)
            ->where('is_active', true)
            ->get()
            ->keyBy('slug');

        $refs = collect($selections)->flatten()->unique()->all();
        $candidates = Candidate::query()
            ->whereIn('candidate_ref', $refs)
            ->where('approval_status', 'approved')
            ->get();

        $payload = [];
        foreach ($selections as $slug => $value) {
            $position = $positions[$slug] ?? null;
            if (! $position) {
                return response()->json(['message' => "Unknown position: {$slug}"], 422);
            }

            $refsForPosition = is_array($value) ? $value : [$value];

            if (count($refsForPosition) > $position->seat_count) {
                return response()->json([
                    'message' => "Position '{$slug}' allows at most {$position->seat_count} selection(s).",
                ], 422);
            }

            foreach ($refsForPosition as $ref) {
                $candidate = $candidates->firstWhere('candidate_ref', $ref);
                if (! $candidate || (int) $candidate->position_id !== $position->id) {
                    return response()->json([
                        'message' => "Invalid or unapproved candidate for position '{$slug}'.",
                    ], 422);
                }
            }

            $payload[] = [
                'position_key' => $slug,
                'position_id' => $position->id,
                'refs' => array_values(array_unique($refsForPosition)),
            ];
        }

        return $payload;
    }
}
