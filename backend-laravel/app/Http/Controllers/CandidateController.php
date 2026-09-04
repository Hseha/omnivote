<?php

namespace App\Http\Controllers;

use App\Http\Requests\CandidateApplicationRequest;
use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CandidateController extends Controller
{
    /**
     * GET /api/candidates
     *
     * Public, approved-only listing consumed by the Flutter Candidates screen.
     * Supports `position`, `tier`, `search` and `grade` query filters and
     * always emits `candidate_ref` (the opaque token used on the ballot).
     */
    public function index(Request $request): JsonResponse
    {
        $candidates = Candidate::query()
            ->with(['user:id,name,email,student_id', 'position:id,slug,label,tier,seat_count'])
            ->where('approval_status', 'approved')
            ->when($request->query('position'), fn ($q, $p) => $q->where('position_id', $p))
            ->when($request->query('tier'), fn ($q, $t) => $q->whereHas('position', fn ($p) => $p->where('tier', $t)))
            ->when($request->query('search'), fn ($q, $s) => $q->where(
                fn ($w) => $w->where('slogan', 'like', "%{$s}%")
                    ->orWhereHas('user', fn ($u) => $u->where('name', 'like', "%{$s}%"))
            ))
            ->when($request->query('grade'), fn ($q, $g) => $q->whereHas('user', fn ($u) => $u->where('email', 'like', "%{$g}%")))
            ->orderByDesc('created_at')
            ->paginate($request->integer('per_page', 50));

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
     * GET /api/candidates/{candidate}
     */
    public function show(Request $request, $candidate): JsonResponse
    {
        $candidate = Candidate::query()
            ->with(['user:id,name,email,student_id', 'position:id,slug,label,tier,seat_count'])
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

        return response()->json(['candidate' => $this->payload($candidate->load('position', 'user'))], 201);
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
            'student_id' => $candidate->user?->student_id,
            'grade_line' => $candidate->user?->student_id ? "Student ID {$candidate->user->student_id}" : null,
            'grade_level' => null,
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
