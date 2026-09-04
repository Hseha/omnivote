<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCandidateStatusRequest;
use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin candidate review endpoints consumed by the React Candidates screen
 * (`GET /api/admin/candidates` + `PATCH /api/admin/candidates/{id}`).
 */
class AdminCandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $candidates = Candidate::query()
            ->with(['user:id,name,email,student_id', 'position:id,slug,label,tier'])
            ->when($request->query('status'), fn ($q, $s) => $q->where('approval_status', $s))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($inner) => $inner
                    ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('position', fn ($p) => $p->where('label', 'like', "%{$s}%"))
            ))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json([
            'data' => $candidates->map(fn (Candidate $c) => [
                'id' => $c->id,
                'candidate_ref' => $c->candidate_ref,
                'name' => $c->user?->name,
                'email' => $c->user?->email,
                'student_id' => $c->user?->student_id,
                'position' => $c->position?->label,
                'position_id' => $c->position_id,
                'position_slug' => $c->position?->slug,
                'party' => $c->party_name,
                'slogan' => $c->slogan,
                'platform_statement' => $c->platform_statement,
                'status' => $c->approval_status,
                'submissionDate' => $c->created_at?->format('M d, Y'),
                'avatar' => null,
                'meta' => [
                    'total' => $candidates->total(),
                    'per_page' => $candidates->perPage(),
                    'current_page' => $candidates->currentPage(),
                ],
            ]),
        ]);
    }

    public function update(UpdateCandidateStatusRequest $request, Candidate $candidate): JsonResponse
    {
        $candidate->update(['approval_status' => $request->validated('status')]);

        return response()->json([
            'candidate' => [
                'id' => $candidate->id,
                'status' => $candidate->approval_status,
            ],
        ]);
    }

    /**
     * GET /api/admin/candidates/export — CSV export for the admin results screen.
     */
    public function export(): JsonResponse
    {
        $results = DB::table('vote_ledger')
            ->select('position_key', 'candidate_ref', DB::raw('COUNT(*) as votes'))
            ->groupBy('position_key', 'candidate_ref')
            ->orderBy('position_key')
            ->get();

        $candidateNames = Candidate::query()
            ->whereIn('candidate_ref', $results->pluck('candidate_ref'))
            ->get()
            ->keyBy('candidate_ref')
            ->map(fn (Candidate $c) => $c->user?->name ?? $c->candidate_ref);

        $csv = collect($results)->map(function ($row) use ($candidateNames) {
            return [
                'position' => $row->position_key,
                'candidate' => $candidateNames[$row->candidate_ref] ?? $row->candidate_ref,
                'votes' => $row->votes,
            ];
        });

        return response()->json(['message' => 'Use /api/admin/results with Accept: text/csv for a file export.'], 501);
    }
}