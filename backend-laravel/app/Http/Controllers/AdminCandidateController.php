<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdminCandidateRequest;
use App\Http\Requests\UpdateCandidateStatusRequest;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Party;
use App\Models\User;
use App\Support\DepartmentCatalog;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin candidate review endpoints consumed by the React Candidates screen
 * (`GET /api/admin/candidates` + `PATCH /api/admin/candidates/{id}`).
 */
class AdminCandidateController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Base query WITHOUT the status filter so the per-status counts reflect
        // every candidate matching the position/tier/party/search filters.
        $base = Candidate::query()
            ->with(['user:id,name,email,student_id', 'position:id,slug,label,tier'])
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($inner) => $inner
                    ->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"))
                    ->orWhereHas('position', fn ($p) => $p->where('label', 'like', "%{$s}%"))
                    ->orWhere('party_name', 'like', "%{$s}%")
            ))
            ->when($request->query('position_id'), fn ($q, $pid) => $q->where('position_id', (int) $pid))
            ->when($request->query('tier'), fn ($q, $t) => $q->whereHas('position', fn ($p) => $p->where('tier', $t)))
            ->when($request->query('department'), fn ($q, $d) => $q->whereHas('user', fn ($u) => $u->where('department', $d)))
            ->when($request->query('party'), fn ($q, $p) => $q->where('party_name', $p));

        $counts = (clone $base)
            ->selectRaw('approval_status, count(*) as c')
            ->groupBy('approval_status')
            ->pluck('c', 'approval_status');

        // Canonical party list from the managed `parties` table so the admin
        // dropdown matches what students see, even for parties that have no
        // candidate rows yet (previously derived from distinct candidate
        // party_name — silently hiding empty parties like SVEA).
        $parties = Schema::hasTable('parties')
            ? Party::query()
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->orderBy('sort_order')
                ->pluck('name')
                ->values()
            : collect();

        // Keep the dropdown useful even if the managed table is empty.
        if ($parties->isEmpty()) {
            $parties = Candidate::query()
                ->whereNotNull('party_name')
                ->where('party_name', '!=', '')
                ->distinct()
                ->orderBy('party_name')
                ->pluck('party_name')
                ->values();
        }

        $departments = Schema::hasTable('departments')
            ? Department::query()
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->orderBy('sort_order')
                ->pluck('name')
                ->values()
            : collect();

        // Keep the dropdown in sync even if the managed table is empty.
        if ($departments->isEmpty()) {
            $departments = collect(DepartmentCatalog::collegeNames())->values();
        }

        $perPage = max(1, min(100, $request->integer('per_page', 20)));

        $candidates = (clone $base)
            ->when($request->query('status'), fn ($q, $s) => $q->where('approval_status', $s))
            ->orderByDesc('created_at')
            ->paginate($perPage);

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
                'tier' => $c->position?->tier,
                'party' => $c->party_name,
                'slogan' => $c->slogan,
                'platform_statement' => $c->platform_statement,
                'status' => $c->approval_status,
                'submissionDate' => $c->created_at?->format('M d, Y'),
                // Real photo when the candidate uploaded one; null otherwise so
                // the panel falls back to its offline default user icon.
                'avatar' => $c->photo_path ? asset('storage/'.$c->photo_path) : null,
            ]),
            'meta' => [
                'total' => $candidates->total(),
                'per_page' => $candidates->perPage(),
                'current_page' => $candidates->currentPage(),
                'last_page' => $candidates->lastPage(),
                'counts' => [
                    'pending' => (int) ($counts['pending'] ?? 0),
                    'approved' => (int) ($counts['approved'] ?? 0),
                    'rejected' => (int) ($counts['rejected'] ?? 0),
                ],
                'parties' => $parties->all(),
                'departments' => $departments->all(),
            ],
        ]);
    }

    /**
     * POST /api/admin/candidates — create a candidacy on behalf of an
     * existing registered student (by student_id + chosen position/party).
     * Mirrors the student self-apply flow so the row lands in `pending` and
     * goes through the same review gate.
     */
    public function store(StoreAdminCandidateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::where('student_id', $validated['student_id'])->firstOrFail();

        $existing = Candidate::where('user_id', $user->id)->first();
        if ($existing && in_array($existing->approval_status, ['pending', 'approved'], true)) {
            return response()->json([
                'message' => 'This student already has an active application.',
            ], 409);
        }

        $sanitized = ! empty($validated['platform_statement'])
            ? strip_tags($validated['platform_statement'], '<p><br><strong><em><ul><ol><li>')
            : null;

        $candidate = Candidate::create([
            'user_id' => $user->id,
            'position_id' => $validated['position_id'],
            'candidate_ref' => (string) Str::uuid(),
            'slogan' => $validated['slogan'] ?? null,
            'party_name' => $validated['party_name'] ?? null,
            'platform_statement' => $sanitized,
            'platform_points' => $sanitized
                ? array_values(array_filter(array_map('trim', explode("\n", $sanitized))))
                : [],
            'approval_status' => 'pending',
        ]);

        return response()->json([
            'message' => 'Candidate created as pending and awaiting approval.',
            'candidate' => [
                'id' => $candidate->id,
                'name' => $user->name,
                'student_id' => $user->student_id,
                'position' => $candidate->position?->label,
                'tier' => $candidate->position?->tier,
                'party' => $candidate->party_name,
                'status' => $candidate->approval_status,
            ],
        ], 201);
    }

    /**
     * POST /api/admin/parties — add a party to the canonical `parties` table
     * so it shows in the student app immediately and in the admin candidate
     * filters (which read that table). Names are case-insensitively unique.
     */
    public function storeParty(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $name = trim($validated['name']);
        if (Party::whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->exists()) {
            return response()->json(['message' => 'That party already exists.'], 409);
        }

        $party = Party::create([
            'name' => $name,
            'sort_order' => ((int) Party::max('sort_order')) + 1,
        ]);

        return response()->json([
            'message' => 'Party added.',
            'party' => [
                'id' => $party->id,
                'name' => $party->name,
                'sort_order' => $party->sort_order,
            ],
        ], 201);
    }

    public function update(UpdateCandidateStatusRequest $request, Candidate $candidate): JsonResponse
    {
        $candidate->update(['approval_status' => $request->validated('status')]);

        $candidateName = $candidate->user?->name ?? $candidate->candidate_ref;

        Notifier::toAdmins(
            'emailOnAdminAction',
            $candidate->approval_status === 'approved' ? 'success' : 'info',
            'Candidate '.$candidate->approval_status,
            "Candidate {$candidateName} was {$candidate->approval_status}.",
            '/candidates',
            false,
        );

        return response()->json([
            'candidate' => [
                'id' => $candidate->id,
                'status' => $candidate->approval_status,
            ],
        ]);
    }

    /**
     * POST /api/admin/candidates/{candidate}/certify — ratify this candidate
     * as a certified election winner. Certification is the gate that makes
     * the SSG President grant available to the candidate's account.
     */
    public function certify(Request $request, Candidate $candidate): JsonResponse
    {
        $validated = $request->validate([
            // Front end passes the position label for the confirmation toast.
            'position' => ['nullable', 'string', 'max:255'],
        ]);

        if ($candidate->certified_winner) {
            return response()->json(['message' => 'Candidate is already certified as a winner.'], 409);
        }

        $candidate->certified_winner = true;
        $candidate->certified_at = now();
        $candidate->save();

        return response()->json([
            'message' => 'Candidate certified as election winner.',
            'candidate' => [
                'id' => $candidate->id,
                'candidate_ref' => $candidate->candidate_ref,
                'certified_winner' => true,
                'certified_at' => $candidate->certified_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * DELETE /api/admin/candidates/{candidate}/certify — revoke a mistaken
     * certification. If the candidate's account already holds the single-seat
     * ssg_president role, the role is pulled back (no auto-demote target is
     * guessed; the admin grants it to another winner explicitly).
     */
    public function revokeCertification(Request $request, Candidate $candidate): JsonResponse
    {
        if (! $candidate->certified_winner) {
            return response()->json(['message' => 'Candidate is not certified as a winner.'], 409);
        }

        $demoted = null;
        $candidate->certified_winner = false;
        $candidate->certified_at = null;
        $candidate->save();

        $account = $candidate->user;
        if ($account && $account->role === 'ssg_president') {
            $account->role = 'student';
            $account->save();
            $account->tokens()->delete();
            $demoted = ['id' => $account->id, 'name' => $account->name, 'email' => $account->email];
        }

        return response()->json([
            'message' => 'Certification revoked.',
            'candidate' => [
                'id' => $candidate->id,
                'certified_winner' => false,
            ],
            'ssg_role_removed_from' => $demoted,
        ]);
    }

    /**
     * POST /api/admin/candidates/{candidate}/resolve-tie — break a boundary
     * tie by promoting this candidate. Only candidates currently flagged
     * `tied` may be resolved; every other tied candidate for the same seat
     * is demoted to `pending` so exactly one winner fills that slot.
     */
    public function resolveTie(Request $request, Candidate $candidate): JsonResponse
    {
        if ($candidate->election_status !== 'tied') {
            return response()->json([
                'message' => 'This candidate is not in a pending tie.',
            ], 409);
        }

        $positionId = $candidate->position_id;

        // Everyone else in the same position waiting on this tie loses out.
        Candidate::query()
            ->where('position_id', $positionId)
            ->where('election_status', 'tied')
            ->where('id', '!=', $candidate->id)
            ->update(['election_status' => 'pending']);

        $nextRank = (int) Candidate::query()
            ->where('position_id', $positionId)
            ->where('election_status', 'elected')
            ->max('winner_rank') + 1;

        $candidate->forceFill([
            'election_status' => 'elected',
            'winner_rank' => $nextRank,
        ])->save();

        if (! $candidate->certified_winner) {
            $candidate->forceFill([
                'certified_winner' => true,
                'certified_at' => now(),
            ])->save();
        }

        app(ResultsController::class)->refreshResultsAnnouncement($request->user());

        return response()->json([
            'message' => 'Tie resolved. Winner certified.',
            'candidate' => [
                'id' => $candidate->id,
                'candidate_ref' => $candidate->candidate_ref,
                'election_status' => 'elected',
                'winner_rank' => $candidate->winner_rank,
                'certified_winner' => true,
            ],
        ]);
    }

    /**
     * GET /api/admin/candidates/export — CSV export for the admin results screen.
     *
     * Deliberately a stub: the real file export lives on
     * `GET /admin/results` with `Accept: text/csv`. It used to scan the whole
     * vote ledger and hydrate every candidate first only to throw the result
     * away and answer 501 — a full table scan per call for nothing.
     */
    public function export(): JsonResponse
    {
        return response()->json(['message' => 'Use /api/admin/results with Accept: text/csv for a file export.'], 501);
    }
}
