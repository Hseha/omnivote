<?php

namespace App\Http\Controllers;

use App\Http\Requests\CandidateApplicationRequest;
use App\Models\Candidate;
use App\Models\Department;
use App\Models\Party;
use App\Models\Phase;
use App\Support\DepartmentCatalog;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CandidateController extends Controller
{
    /**
     * GET /api/candidates
     *
     * Public, approved-only listing consumed by the Flutter Candidates screen.
     * Supports `position`, `tier`, `department`, `party`, `search` and `grade`
     * query filters and always emits `candidate_ref` (the opaque token used on
     * the ballot).
     */
    public function index(Request $request): JsonResponse
    {
        // Every filter is validated as a scalar before it touches a query.
        // A raw array value (e.g. ?search[]=x) used to reach mb_strtolower()/
        // LIKE interpolation and produced an unhandled 500 with a full debug
        // payload — source paths, line numbers, stack trace — because
        // APP_DEBUG was on (security assessment M-1). Validation turns those
        // into a clean 422. The rules intentionally only assert *type*, not
        // value, so an unknown-but-scalar filter keeps its previous
        // "matches nothing" behaviour instead of becoming a 422.
        $request->validate([
            'position' => ['nullable', 'string', 'max:64'],
            'tier' => ['nullable', 'string', 'max:32'],
            'department' => ['nullable', 'string', 'max:255'],
            'party' => ['nullable', 'string', 'max:255'],
            'search' => ['nullable', 'string', 'max:255'],
            'grade' => ['nullable', 'string', 'max:32'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $candidates = Candidate::query()
            ->with(['user:id,name,email,department,year_level', 'position:id,slug,label,tier,seat_count'])
            ->where('approval_status', 'approved')
            ->when($request->query('position'), fn ($q, $p) => $q->where('position_id', $p))
            ->when($request->query('tier'), fn ($q, $t) => $q->whereHas('position', fn ($p) => $p->where('tier', $t)))
            ->when($request->query('department'), fn ($q, $d) => $q->whereHas('user', fn ($u) => $u->where('department', $d)))
            ->when($request->query('party'), fn ($q, $p) => $q->whereRaw('LOWER(party_name) = ?', [mb_strtolower($p)]))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('slogan', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"))
            ))
            ->when($request->query('grade'), fn ($q, $g) => $q->whereHas('user', fn ($u) => $u->where('year_level', $g)))
            ->orderByDesc('created_at')
            ->paginate(min(100, max(1, $request->integer('per_page', 50))));

        return response()->json([
            'data' => $candidates->map(fn (Candidate $c) => $this->payload($c)),
            'meta' => [
                'total' => $candidates->total(),
                'per_page' => $candidates->perPage(),
                'current_page' => $candidates->currentPage(),
            ],
        ]);
    }

    /**
     * GET /api/departments
     *
     * Department list for the Candidates drill-down. Prefers the managed
     * `departments` table, falling back to the registrar catalog when the
     * table has not been seeded yet.
     */
    public function departments(): JsonResponse
    {
        if (Schema::hasTable('departments')) {
            $names = Department::query()
                ->whereNotNull('name')
                ->where('name', '!=', '')
                ->orderBy('sort_order')
                ->pluck('name')
                ->values();
        }

        if (! isset($names) || $names->isEmpty()) {
            $names = collect(DepartmentCatalog::collegeNames())->values();
        }

        return response()->json(['data' => $names]);
    }

    /**
     * GET /api/parties
     *
     * Canonical party list (ASLE, SVEA) for the Candidates drill-down.
     */
    public function parties(): JsonResponse
    {
        $parties = Party::query()
            ->orderBy('sort_order')
            ->get(['id', 'name'])
            ->map(fn (Party $party) => [
                'id' => $party->id,
                'name' => $party->name,
            ])
            ->values();

        return response()->json(['data' => $parties]);
    }

    /**
     * GET /api/candidates/{candidate}
     */
    public function show(Request $request, $candidate): JsonResponse
    {
        $candidate = Candidate::query()
            ->with(['user:id,name,email,department,year_level', 'position:id,slug,label,tier,seat_count'])
            ->where('approval_status', 'approved')
            ->find($candidate);

        if (! $candidate) {
            return response()->json(['message' => 'Candidate not found'], 404);
        }

        return response()->json($this->payload($candidate));
    }

    /**
     * POST /api/candidate/apply  (multipart, registration phase only)
     */
    public function store(CandidateApplicationRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $existing = Candidate::where('user_id', $request->user()->id)->first();
        if ($existing && in_array($existing->approval_status, ['pending', 'approved'], true)) {
            return response()->json([
                'message' => 'You already have an active application.',
                'candidate' => $this->payload($existing),
            ], 409);
        }

        $sanitized = strip_tags($validated['platform_statement'], '<p><br><strong><em><ul><ol><li>');

        $candidate = Candidate::create([
            'user_id' => $request->user()->id,
            'position_id' => $validated['position_id'],
            'candidate_ref' => (string) Str::uuid(),
            'slogan' => $validated['slogan'] ?? null,
            'party_name' => $validated['party_name'] ?? null,
            'platform_statement' => $sanitized,
            'platform_points' => array_values(array_filter(array_map('trim', explode("\n", $sanitized)))),
            'approval_status' => 'pending',
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('candidates', 'public');
            $candidate->photo_path = $path;
            $candidate->save();
        }

        Notifier::toAdmins(
            'notifyOnRegistration',
            'info',
            'New candidate application',
            "{$candidate->user->name} applied for ".($candidate->position->label ?? 'a position').'.',
            '/candidates',
            false,
        );

        return response()->json(['candidate' => $this->payload($candidate->load('position', 'user'))], 201);
    }

    /**
     * PUT /api/candidate/apply  (multipart)
     *
     * Edit the caller's campaign. Allowed for pending AND approved candidates
     * while the election is in `registration` or `voting_open` (never after
     * polls close). Once a candidacy is approved the position is locked —
     * changing office requires committee re-review — but slogan, party,
     * platform and photo stay editable.
     */
    public function update(CandidateApplicationRequest $request): JsonResponse
    {
        if ($blocked = $this->assertCandidacyEditable($request)) {
            return $blocked;
        }

        $candidate = Candidate::where('user_id', $request->user()->id)->first();
        if (! $candidate) {
            return response()->json(['message' => 'No candidacy application found.'], 404);
        }

        if (! in_array($candidate->approval_status, ['pending', 'approved'], true)) {
            return response()->json(['message' => 'Only pending or approved applications can be edited.'], 409);
        }

        $validated = $request->validated();
        $sanitized = strip_tags($validated['platform_statement'], '<p><br><strong><em><ul><ol><li>');

        $candidate->update([
            // The position is locked once approved; pending applications may
            // still change office before the committee reviews them.
            'position_id' => $candidate->approval_status === 'approved'
                ? $candidate->position_id
                : $validated['position_id'],
            'slogan' => $validated['slogan'] ?? null,
            'party_name' => $validated['party_name'] ?? null,
            'platform_statement' => $sanitized,
            'platform_points' => array_values(array_filter(array_map('trim', explode("\n", $sanitized)))),
        ]);

        if ($request->hasFile('photo')) {
            $path = $request->file('photo')->store('candidates', 'public');
            if ($candidate->photo_path) {
                Storage::disk('public')->delete($candidate->photo_path);
            }
            $candidate->photo_path = $path;
            $candidate->save();
        }

        Notifier::toAdmins(
            'notifyOnRegistration',
            'info',
            'Candidate application updated',
            "{$candidate->user->name} updated their campaign for ".($candidate->position->label ?? 'a position').'.',
            '/candidates',
            false,
        );

        return response()->json(['candidate' => $this->payload($candidate->load('position', 'user'))]);
    }

    /**
     * POST /api/candidate/withdraw
     *
     * Forfeit a pending or approved candidacy. Allowed until polls close so an
     * approved candidate can still step down during voting. A withdrawn
     * candidacy disappears from the approved listings/ballot; votes already
     * cast are preserved (the tally already tolerates vanished candidates).
     */
    public function withdraw(Request $request): JsonResponse
    {
        if ($blocked = $this->assertCandidacyEditable($request)) {
            return $blocked;
        }

        $candidate = Candidate::where('user_id', $request->user()->id)->first();
        if (! $candidate) {
            return response()->json(['message' => 'No candidacy application found.'], 404);
        }

        if (! in_array($candidate->approval_status, ['pending', 'approved'], true)) {
            return response()->json(['message' => 'This application can no longer be withdrawn.'], 409);
        }

        $candidate->update(['approval_status' => 'withdrawn']);

        Notifier::toAdmins(
            'notifyOnRegistration',
            'info',
            'Candidate withdrew',
            "{$candidate->user->name} withdrew from running for ".($candidate->position->label ?? 'a position').'.',
            '/candidates',
            false,
        );

        return response()->json(['candidate' => $this->payload($candidate->load('position', 'user'))]);
    }

    /**
     * Shared gate for self-service candidacy changes: only meaningful while
     * applications are accepted and ballots can still be cast (registration
     * or voting_open). After polls close nothing may change.
     */
    private function assertCandidacyEditable(Request $request): ?JsonResponse
    {
        $phase = Phase::current()?->name;
        if (! in_array($phase, ['registration', 'voting_open'], true)) {
            return response()->json([
                'success' => false,
                'message' => 'Action not allowed in current election phase',
                'phase' => $phase,
            ], 403);
        }

        return null;
    }

    /**
     * Wire shape for the public contracts (camelCase tolerated by Flutter).
     */
    private function payload(Candidate $candidate): array
    {
        $position = $candidate->position;
        $photo = $candidate->photo_path
            ? asset('storage/'.$candidate->photo_path)
            : null;

        return [
            'id' => $candidate->id,
            'candidate_ref' => $candidate->candidate_ref,
            'name' => $candidate->user?->name,
            'full_name' => $candidate->user?->name,
            // NOTE: the student_id is deliberately NOT exposed here. These
            // payloads are served from public routes, and the student ID is a
            // credential-adjacent identifier (registrar imports used to set it
            // as the password). Admin views get it via the admin endpoints.
            'grade_level' => $candidate->user?->year_level,
            'department' => $candidate->user?->department,
            'photo_url' => $photo,
            'position' => $position ? [
                'id' => $position->id,
                'slug' => $position->slug,
                'label' => $position->label,
                'name' => $position->label,
                'tier' => $position->tier,
                'seat_count' => $position->seat_count,
                'description' => $position->label,
            ] : null,
            'position_id' => $candidate->position_id,
            'position_label' => $position?->label,
            'slogan' => $candidate->slogan,
            'platform_statement' => $candidate->platform_statement,
            'platform_points' => $candidate->platform_points ?? [],
            'qualifications' => [],
            'video_url' => null,
            'party_name' => $candidate->party_name,
            'approval_status' => $candidate->approval_status,
            'created_at' => $candidate->created_at?->toIso8601String(),
        ];
    }
}
