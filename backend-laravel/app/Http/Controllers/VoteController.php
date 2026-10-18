<?php

namespace App\Http\Controllers;

use App\Models\BallotDraft;
use App\Models\Candidate;
use App\Models\Position;
use App\Models\User;
use App\Models\VoteLedger;
use App\Support\AppSettings;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
 *   - the user-scoped ballot draft is reduced to `status='submitted'` with NO
 *     selections and NO receipt, so the ledger cannot be joined back to a
 *     voter (security assessment H-1).
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
        $payload = $this->resolveSelections($selections, $user);
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
                // The vote is already on the ledger. The client may be a
                // double-tap or, more commonly in this school-Wi-Fi setup, a
                // retry after the first response was lost — the ballot WAS
                // recorded, so this must read as "confirmed" to the voter, not
                // as an error. `voted_at` lets the client show that
                // confirmation, but the receipt is deliberately NOT echoed
                // back here: it is returned exactly once at submit time and
                // echoing it would give a database-reader a way to attach this
                // HMAC to a voter. Nothing in this body references choices or
                // the ledger (anonymity H-1).
                return response()->json([
                    'message' => 'Already voted',
                    'voted_at' => $voter->voted_at?->toIso8601String(),
                ], 409);
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

            // Ballot secrecy (security assessment H-1).
            //
            // The draft row is keyed by user_id, so anything left here joins a
            // named voter to their choices. It previously kept BOTH the
            // plaintext `selections` and the plaintext `receipt_token`, and
            // because the ledger stores HMAC(receipt), anyone with a database
            // dump could recompute that HMAC and read the voter's ballot back.
            //
            // On submit the row is reduced to proof-of-submission only: the
            // choices and the receipt are never written next to the user id.
            // The receipt is returned once in this response and held by the
            // voter; /api/results/verify resolves it against the ledger on its
            // own and needs no stored linkage.
            BallotDraft::updateOrCreate(
                ['user_id' => $voter->id],
                [
                    'selections' => null,
                    'status' => 'submitted',
                    'receipt_token' => null,
                    'submitted_at' => now(),
                ],
            );

            return ['receipt' => $receipt];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        // In-app confirmation (the email above is the optional out-of-band copy).
        Notifier::notifyUser(
            $user->id,
            'success',
            'Your vote was recorded',
            'Your ballot was securely recorded. Keep your receipt to verify it later.',
            '/ballot',
        );

        Notifier::emailUser(
            $user->id,
            'emailOnVote',
            'Your vote was recorded',
            "Thank you, {$user->name}. Your vote for this election has been securely recorded. Your receipt token was shown in the app at submission — keep it to verify your vote was counted.",
            '/ballot',
            false,
        );

        return response()->json($result, 201);
    }

    /**
     * Converts `{ slug: ref | ref[] }` into a normalized payload, confirming
     * every ref belongs to an approved candidate in that position. Returns a
     * 422 JsonResponse on the first invalid entry.
     *
     * ELECTORATE SCOPE (security assessment M-4).
     *
     * This previously validated only that the position was active, the slug
     * matched, `seat_count` was not exceeded and the candidate was approved for
     * that position. It never checked that the VOTER belonged to that seat's
     * electorate, so a first-year student could submit a ballot containing
     * `year_level_representative` (or any other scoped seat) and the server
     * recorded it. Flutter hid those seats from the ballot, but the client-side
     * filter is not a control — any HTTP client bypasses it.
     *
     * For a scoped position two things are now checked: the voter is in the
     * seat's electorate, AND the candidate they picked is in it too (otherwise a
     * year-12 candidate could be elected by the year-11 electorate).
     *
     * Positions default to `scope_type = 'global'`, which means unrestricted, so
     * an election that configures no scope behaves exactly as it did before.
     */
    private function resolveSelections(array $selections, ?User $voter): array|JsonResponse
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
            // Archived winners (Past Terms) must not be re-votable. The
            // column is schema-guarded so installs/tests without it are safe.
            ->when(Schema::hasColumn('candidates', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))
            // The owning account is needed to test the candidate's own scope.
            ->with('user:id,year_level,department,course')
            ->get();

        $payload = [];
        foreach ($selections as $slug => $value) {
            $position = $positions[$slug] ?? null;
            if (! $position) {
                return response()->json(['message' => "Unknown position: {$slug}"], 422);
            }

            if (! $position->voterIsInScope($voter)) {
                return response()->json([
                    'message' => "You are not eligible to vote for position '{$slug}'.",
                ], 403);
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

                if (! $this->candidateIsInScope($position, $candidate, $voter)) {
                    return response()->json([
                        'message' => "Candidate for position '{$slug}' is not in your electorate.",
                    ], 422);
                }
            }

            $payload[] = [
                'position_key' => $slug,
                'position_id' => $position->id,
                'refs' => array_values(array_unique($refsForPosition)),
            ];
        }

        // Settings → Voting Windows → maxVotesPerVoter: caps how many distinct
        // positions a single ballot may cover when an administrator has saved
        // the setting. Per-position seat_count is still enforced above; the
        // stored cap only narrows the overall ballot (admin label: "Number of
        // positions a voter can cast in one session"). Unconfigured → no cap.
        $maxPositions = AppSettings::voting('maxVotesPerVoter');
        if ($maxPositions !== null && $maxPositions !== false) {
            if (count($payload) > (int) $maxPositions) {
                return response()->json([
                    'message' => "Ballot allows voting in at most {$maxPositions} position(s).",
                ], 422);
            }
        }

        return $payload;
    }

    /**
     * Whether a candidate is standing in the same electorate as the voter.
     *
     * Delegates to {@see Position::candidateIsInScope()}, which evaluates every
     * scope dimension (a seat may be scoped by college AND year level). The
     * required value is always derived from the VOTER, never the candidate, so
     * one electorate cannot elect another's representative.
     */
    private function candidateIsInScope(Position $position, Candidate $candidate, ?User $voter): bool
    {
        return $position->candidateIsInScope($candidate->user, $voter);
    }
}
