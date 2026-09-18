<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Admin-only user access management.
 *
 * GET  /api/admin/users?...               — paginated listing (safe fields only)
 * PATCH /api/admin/users/{user}/role       — assign an approved role
 * PATCH /api/admin/users/{user}/status     — toggle is_active
 * POST  /api/admin/users/{user}/password-reset — one-time temp password
 *
 * All routes require auth + permission:manage_accounts (admin only).
 */
class AdminUserController extends Controller
{
    private const SAFE_ROLES = ['admin', 'teacher', 'candidate', 'student', 'ssg_president'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string'],
            'role' => ['nullable', 'string', Rule::in(self::SAFE_ROLES)],
            'is_active' => ['nullable', 'string', 'in:true,false,1,0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $isActive = $request->has('is_active')
            ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
            : null;

        $users = User::query()
            ->when($request->query('search'), function ($q, $search) {
                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%"));
            })
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($isActive !== null, fn ($q) => $q->where('is_active', $isActive))
            ->orderBy('name')
            ->paginate($request->integer('per_page', 20))
            ->withQueryString();

        return response()->json([
            'data' => $users->map(fn (User $u) => $this->present($u))->values(),
            'meta' => [
                'total' => $users->total(),
                'per_page' => $users->perPage(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'from' => $users->firstItem(),
                'to' => $users->lastItem(),
            ],
            'links' => [
                'first' => $users->url(1),
                'last' => $users->url($users->lastPage()),
                'prev' => $users->previousPageUrl(),
                'next' => $users->nextPageUrl(),
            ],
            'filters' => [
                'search' => $request->query('search'),
                'role' => $request->query('role'),
                'is_active' => $request->query('is_active'),
            ],
        ]);
    }

    private function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'student_id' => $user->student_id,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'has_voted' => (bool) $user->has_voted,
            'year_level' => $user->year_level,
            'block_number' => $user->block_number,
            'date_added' => $user->created_at?->format('M d, Y'),
        ];
    }

    public function updateRole(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'role' => ['required', 'string', Rule::in(self::SAFE_ROLES)],
        ]);

        $acting = $request->user();
        if ($acting->id === $user->id) {
            return response()->json(['message' => 'You cannot change your own role.'], 422);
        }

        $user->role = $validated['role'];
        $user->save();
        $user->tokens()->delete();

        return response()->json(['data' => $this->present($user)]);
    }

    public function updateStatus(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            'is_active' => ['required', 'boolean'],
        ]);

        $acting = $request->user();
        if ($user->role === 'admin' && ! $validated['is_active']) {
            $remaining = User::where('role', 'admin')->where('is_active', true)->count();
            if ($remaining <= 1) {
                return response()->json(['message' => 'Cannot disable the final active administrator.'], 409);
            }
        }
        if ($acting->id === $user->id && ! $validated['is_active']) {
            return response()->json(['message' => 'You cannot disable your own account.'], 422);
        }

        $user->is_active = $validated['is_active'];
        $user->save();
        $user->tokens()->delete();

        return response()->json(['data' => $this->present($user)]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $acting = $request->user();
        if ($acting->id === $user->id) {
            return response()->json(['message' => 'You cannot reset your own password from here.'], 422);
        }

        // Plaintext value is never persisted; the User model casts 'password' => 'hashed'.
        $tempPassword = Str::random(12);
        $user->password = $tempPassword;
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Temporary password generated.',
            'temporary_password' => $tempPassword,
            'user' => $this->present($user),
        ]);
    }
}
