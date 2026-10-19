<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Phase;
use App\Support\SsgPresidentGrant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Student-facing candidacy endpoints backing the Flutter "My Candidacy" screen:
 *
 *   GET    /api/candidacy/me — full application payload (status, photo URL,
 *          position, rejection reason, seat competition, and — once polls
 *          close AND the results are finalized — the candidate's own
 *          outcome).
 *   PUT    /api/candidacy/me — edit a still-pending application (registration
 *          phase only, mirroring the apply gate).
 *   DELETE /api/candidacy/me — withdraw a still-pending application.
 */
class CandidacyController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $candidate = Candidate::query()
            ->where('user_id', $request->user()->id)
            ->with('position:id,slug,label,tier,seat_count')
            ->first();

        if (! $candidate) {
            return response()->json(['status' => 'none']);
        }

        $position = $candidate->position;

        // Seat-competition context for the applicant: how many candidates
        // (pending or approved) are in the race against the position's cap.
        $applicantsCount = $candidate->position_id === null
            ? null
            : Candidate::query()
                ->where('position_id', $candidate->position_id)
                ->whereIn('approval_status', ['pending', 'approved'])
                ->count();

        // The applicant's own outcome is only revealed once polls close AND
        // the results are finalized (the same two gates public /api/results
        // carries). In the close → finalize window the row fields are still
        // pre-tally defaults ("pending", zero votes), so disclosing them
        // would show the candidate a meaningless "not elected" card for a
        // race that has not been decided yet — the app instead renders the
        // "awaiting official results" state while this stays null.
        $electionOutcome = null;
        if ($candidate->approval_status === 'approved'
            && Phase::current()?->name === 'voting_closed'
            && SsgPresidentGrant::resultsPublished()) {
            $electionOutcome = [
                'status' => $candidate->election_status,
                'rank' => $candidate->winner_rank,
                'votes' => $candidate->vote_total,
                'certified' => (bool) $candidate->certified_winner,
            ];
        }

        return response()->json([
            'status' => $candidate->approval_status,
            'rejection_reason' => $candidate->rejection_reason,
            'rejection_count' => $candidate->rejection_count,
            'reapply_blocked_until' => $candidate->reapply_blocked_until?->toIso8601String(),
            'created_at' => $candidate->created_at?->toIso8601String(),
            'updated_at' => $candidate->updated_at?->toIso8601String(),
            'applicants_count' => $applicantsCount,
            'election_outcome' => $electionOutcome,
            'candidate' => [
                'id' => $candidate->id,
                'position_id' => $candidate->position_id,
                'position' => $position ? [
                    'id' => $position->id,
                    'slug' => $position->slug,
                    'label' => $position->label,
                    'tier' => $position->tier,
                    'seat_count' => $position->seat_count,
                ] : null,
                'slogan' => $candidate->slogan,
                'party_name' => $candidate->party_name,
                'platform_statement' => $candidate->platform_statement,
                'photo_path' => $candidate->photo_path,
                // Absolute URL so the app renders the photo without building
                // storage paths client-side (mirrors CandidateController::payload).
                'photo_url' => $candidate->photo_path
                    ? asset('storage/'.$candidate->photo_path)
                    : null,
                'approval_status' => $candidate->approval_status,
                'rejection_reason' => $candidate->rejection_reason,
                'created_at' => $candidate->created_at?->toIso8601String(),
                'updated_at' => $candidate->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
