<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Phase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Student-facing candidacy endpoints backing the Flutter "My Candidacy" screen:
 *
 *   GET    /api/candidacy/me — full application payload (status, photo URL,
 *          position, rejection reason, seat competition, and — once polls
 *          close — the candidate's own finalized outcome).
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

        // The applicant's own finalized outcome is only revealed once polls
        // close — the same gate public /api/results uses. Before that the
        // row fields are pre-finalize defaults and would leak nothing useful
        // anyway, but the phase check keeps one consistent disclosure rule.
        $electionOutcome = null;
        if ($candidate->approval_status === 'approved' && Phase::current()?->name === 'voting_closed') {
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
