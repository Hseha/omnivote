<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\Course;
use App\Models\Department;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\DepartmentCatalog;
use App\Support\Notifier;
use App\Support\TemporaryPassword;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Admin-only user access management.
 *
 * GET    /api/admin/users                        — paginated listing + global role stats
 * POST   /api/admin/users                        — create a staff account (teacher/admin)
 * PATCH  /api/admin/users/{user}/role        — assign an approved role
 * PATCH  /api/admin/users/{user}/status      — toggle is_active
 * PATCH  /api/admin/users/{user}/email       — correct the sign-in email address
 * POST   /api/admin/users/{user}/password-reset  — one-time temp password
 * POST   /api/admin/users/{user}/unlock          — clear the failed-login lockout
 * POST   /api/admin/users/{user}/grant-ssg       — grant ssg_president to a certified winner
 * GET    /api/admin/users/certified-winners      — winners eligible for the SSG grant
 * GET    /api/admin/users/export                 — CSV export honouring current filters
 *
 * All routes require auth + permission:manage_accounts (admin only).
 */
class AdminUserController extends Controller
{
    private const SAFE_ROLES = ['admin', 'teacher', 'candidate', 'student', 'ssg_president'];

    /** Roles creatable via the create-staff-account endpoint. */
    private const CREATABLE_ROLES = ['admin', 'teacher'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'search' => ['nullable', 'string'],
            'role' => ['nullable', 'string', Rule::in(self::SAFE_ROLES)],
            'is_active' => ['nullable', 'string', 'in:true,false,1,0'],
            'status' => ['nullable', 'string', 'in:active,inactive,locked'],
            'department' => ['nullable', 'string', 'max:100'],
            'needs_review' => ['nullable', 'string', 'in:true,false,1,0'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $users = $this->filteredQuery($request)
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
                'status' => $request->query('status'),
                'department' => $request->query('department'),
                'needs_review' => $request->query('needs_review'),
            ],
            // Global, unpaginated aggregates so the stat row never depends on
            // which slice of users happens to be on screen.
            'stats' => $this->stats(),
            // College → Course cascade: `departments` is the flat list of
            // college names for the filter dropdown, `courses` carries each
            // program with its owning college so the UI can filter by college.
            'departments' => $this->departments(),
            'courses' => $this->courses(),
        ]);
    }

    /**
     * College names for dropdowns: the `departments` table (seeded from the
     * catalog and expanded/renamed by admins — source of truth) plus any
     * residual values still present on accounts/feed (resolved to canonical
     * college names), so filters never drift from what the data actually
     * contains. Falls back to the static catalog when the table is absent.
     */
    private function departments(): array
    {
        return collect(
            Schema::hasTable('departments')
                ? Department::query()->orderBy('sort_order')->pluck('name')
                : DepartmentCatalog::collegeNames()
        )
            ->merge(
                User::query()
                    ->whereNotNull('department')
                    ->where('department', '!=', '')
                    ->distinct()
                    ->pluck('department')
                    ->map(fn ($d) => DepartmentCatalog::resolveCollege($d) ?? $d)
            )
            ->merge(
                RegistrarImport::query()
                    ->whereNotNull('department')
                    ->where('department', '!=', '')
                    ->distinct()
                    ->pluck('department')
                    ->map(fn ($d) => DepartmentCatalog::resolveCollege($d) ?? $d)
            )
            ->filter(fn ($d) => $d !== null && trim((string) $d) !== '')
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Programs for the College → Course cascade: every course with the college
     * name it belongs to, ordered by college sort then course sort.
     */
    private function courses(): array
    {
        return Department::query()
            ->orderBy('sort_order')
            ->with(['courses' => fn ($q) => $q->orderBy('sort_order')])
            ->get()
            ->flatMap(fn (Department $d) => $d->courses->map(fn (Course $c) => [
                'name' => $c->name,
                'college' => $d->name,
            ]))
            ->values()
            ->all();
    }

    /**
     * All global stat cards are computed over the whole users table, not the
     * current page (which previously made Active/Inactive/Staff counters show
     * values from a 20-row slice — the "0 STAFF" bug). SSG President is a
     * single-seat role granted to one specific student post-election, so its
     * card reports Assigned / Not Yet Assigned instead of a headcount.
     */
    private function stats(): array
    {
        $roleCounts = User::query()
            ->select('role', DB::raw('COUNT(*) as total'))
            ->groupBy('role')
            ->pluck('total', 'role');

        $count = fn (string $role): int => (int) ($roleCounts[$role] ?? 0);

        return [
            'total' => array_sum($roleCounts->all()),
            'active' => User::where('is_active', true)->count(),
            'inactive' => User::where('is_active', false)->count(),
            'locked' => User::where('locked_until', '>', now())->count(),
            'needs_review' => User::where('needs_review', true)->count(),
            'by_role' => [
                'admin' => $count('admin'),
                'teacher' => $count('teacher'),
                'student' => $count('student'),
                'candidate' => $count('candidate'),
            ],
            // Single-seat role: the UI shows Assigned / Not Yet Assigned.
            'ssg_president' => ['assigned' => $count('ssg_president')],
            // "Staff" = every panel-capable role, aggregated server-side.
            'staff' => $count('admin') + $count('teacher') + $count('ssg_president'),
        ];
    }

    /**
     * POST /api/admin/users/departments — add a college to the department list.
     */
    public function storeDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $name = trim($validated['name']);
        if ($name === '') {
            return response()->json(['message' => 'Department name cannot be empty.'], 422);
        }

        // Accept the legacy short code too ("CCS" → "College of Computer Studies")
        // so admins can type either and always store the canonical name.
        $canonical = DepartmentCatalog::resolveCollege($name);
        if ($canonical === null) {
            $existing = Department::where('name', $name)->first();
            $canonical = $existing?->name ?? $name;
            if ($existing !== null) {
                return response()->json(['message' => "Department '{$name}' already exists."], 422);
            }
        }

        // Case-insensitive so "engineering" can't slip in next to "Engineering".
        $exists = Department::whereRaw('LOWER(name) = ?', [mb_strtolower($canonical)])->exists();
        if ($exists) {
            return response()->json(['message' => "Department '{$canonical}' already exists."], 422);
        }

        Department::create([
            'name' => $canonical,
            'sort_order' => (int) Department::max('sort_order') + 1,
        ]);
        $this->audit($request, 'department_added', "Added department '{$canonical}'.");

        return response()->json([
            'message' => "Department '{$canonical}' added.",
            'departments' => $this->departments(),
        ], 201);
    }

    /**
     * PATCH /api/admin/users/departments — rename a college everywhere.
     *
     * Renames the catalog row plus every `users.department` /
     * `registrar_imports.department` cell that matches the old value, so User
     * Management, filters, and the import guard stay consistent in one atomic
     * step. Courses keep their names and simply move with the college.
     */
    public function renameDepartment(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => ['required', 'string', 'max:100'],
            'to' => ['required', 'string', 'max:100'],
        ]);

        $from = trim($validated['from']);
        $to = trim($validated['to']);
        if ($to === '') {
            return response()->json(['message' => 'The new department name cannot be empty.'], 422);
        }
        if (mb_strtolower($from) === mb_strtolower($to)) {
            return response()->json(['message' => 'The new name is the same as the current one.'], 422);
        }

        $department = DepartmentCatalog::resolveCollege($from) !== null
            ? Department::where('name', DepartmentCatalog::resolveCollege($from))->first()
            : Department::where('name', $from)->first();
        if ($department === null) {
            return response()->json(['message' => "Department '{$from}' does not exist."], 404);
        }
        if (Department::where('name', $to)->where('id', '!=', $department->id)->exists()) {
            return response()->json(['message' => "Department '{$to}' already exists."], 422);
        }

        $fromName = $department->name;
        $affectedUsers = 0;
        $affectedRegistrar = 0;
        DB::transaction(function () use ($department, $to, &$affectedUsers, &$affectedRegistrar): void {
            // Capture BEFORE the rename — update() syncs the model's original
            // attributes to the new name, so reading them afterwards would
            // match zero rows.
            $fromName = $department->name;
            $department->update(['name' => $to]);

            $affectedUsers = User::where('department', $fromName)
                ->update(['department' => $to]);
            $affectedRegistrar = RegistrarImport::where('department', $fromName)
                ->update(['department' => $to]);
        });
        $this->audit($request, 'department_renamed', "Renamed department '{$fromName}' to '{$to}'.");

        return response()->json([
            'message' => "Department '{$fromName}' renamed to '{$to}'.",
            'affected_users' => $affectedUsers,
            'affected_registrar_rows' => $affectedRegistrar,
            'departments' => $this->departments(),
        ]);
    }

    /**
     * POST /api/admin/users/courses — add a program under a college.
     *
     * Accepts either a canonical college name or a legacy short code ("CCS")
     * exactly like departments do, and stores the canonical course name so the
     * registrar import and account forms stay consistent.
     */
    public function storeCourse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'department' => ['required', 'string', 'max:100'],
        ]);

        $name = trim($validated['name']);
        $departmentName = $this->resolveDepartmentName($validated['department']);
        if ($name === '') {
            return response()->json(['message' => 'Course name cannot be empty.'], 422);
        }
        if ($departmentName === null) {
            return response()->json(['message' => 'Unknown department.'], 422);
        }

        $department = Department::where('name', $departmentName)->first();
        if ($department === null) {
            return response()->json(['message' => "Department '{$departmentName}' does not exist."], 404);
        }

        // Case-insensitive so "information technology" can't slip in next to
        // the canonical "Bachelor of Science in Information Technology".
        $exists = Course::where('department_id', $department->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->exists();
        if ($exists) {
            return response()->json(['message' => "Course '{$name}' already exists under {$departmentName}."], 422);
        }

        Course::create([
            'department_id' => $department->id,
            'name' => $name,
            'sort_order' => (int) Course::where('department_id', $department->id)->max('sort_order') + 1,
        ]);
        $this->audit($request, 'course_added', "Added course '{$name}' under {$departmentName}.", 'course');

        return response()->json([
            'message' => "Course '{$name}' added under {$departmentName}.",
            'courses' => $this->courses(),
        ], 201);
    }

    /**
     * PATCH /api/admin/users/courses — rename a program within a college.
     */
    public function renameCourse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', 'max:100'],
            'from' => ['required', 'string', 'max:100'],
            'to' => ['required', 'string', 'max:100'],
        ]);

        $from = trim($validated['from']);
        $to = trim($validated['to']);
        if ($to === '') {
            return response()->json(['message' => 'The new course name cannot be empty.'], 422);
        }
        if (mb_strtolower($from) === mb_strtolower($to)) {
            return response()->json(['message' => 'The new name is the same as the current one.'], 422);
        }

        $departmentName = $this->resolveDepartmentName($validated['department']);
        if ($departmentName === null) {
            return response()->json(['message' => 'Unknown department.'], 422);
        }
        $department = Department::where('name', $departmentName)->first();
        if ($department === null) {
            return response()->json(['message' => "Department '{$departmentName}' does not exist."], 404);
        }

        $course = Course::where('department_id', $department->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($from)])
            ->first();
        if ($course === null) {
            return response()->json(['message' => "Course '{$from}' does not exist under {$departmentName}."], 404);
        }
        if (Course::where('department_id', $department->id)
            ->where('id', '!=', $course->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($to)])
            ->exists()) {
            return response()->json(['message' => "Course '{$to}' already exists under {$departmentName}."], 422);
        }

        $fromName = $course->name;
        $course->update(['name' => $to]);
        $this->audit($request, 'course_renamed', "Renamed course '{$fromName}' to '{$to}' under {$departmentName}.", 'course');

        return response()->json([
            'message' => "Course '{$fromName}' renamed to '{$to}' under {$departmentName}.",
            'courses' => $this->courses(),
        ]);
    }

    /**
     * DELETE /api/admin/users/courses — remove a program from a college.
     *
     * `users.course` holds plain text, so removing a program only drops it from
     * the selectable list; existing students keep their recorded value.
     */
    public function deleteCourse(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'department' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
        ]);

        $name = trim($validated['name']);
        $departmentName = $this->resolveDepartmentName($validated['department']);
        if ($departmentName === null) {
            return response()->json(['message' => 'Unknown department.'], 422);
        }

        $department = Department::where('name', $departmentName)->first();
        if ($department === null) {
            return response()->json(['message' => "Department '{$departmentName}' does not exist."], 404);
        }

        $course = Course::where('department_id', $department->id)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->first();
        if ($course === null) {
            return response()->json(['message' => "Course '{$name}' does not exist under {$departmentName}."], 404);
        }

        $course->delete();
        $this->audit($request, 'course_deleted', "Deleted course '{$name}' under {$departmentName}.", 'course');

        return response()->json([
            'message' => "Course '{$name}' removed from {$departmentName}.",
            'courses' => $this->courses(),
        ]);
    }

    /** Resolve a college code or name to its canonical department name. */
    private function resolveDepartmentName(string $value): ?string
    {
        $canonical = DepartmentCatalog::resolveCollege($value);
        if ($canonical !== null) {
            return $canonical;
        }

        return Department::where('name', $value)->exists() ? $value : null;
    }

    /** Write an audit row when the audit_logs table is available. */
    private function audit(Request $request, string $action, string $details, string $entityType = 'department'): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => null,
            'details' => $details,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Shared filter builder so listing and CSV export stay in lockstep. */
    private function filteredQuery(Request $request)
    {
        $status = $request->query('status');
        $isActive = $request->has('is_active')
            ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN)
            : null;

        return User::query()
            ->when($request->query('search'), function ($q, $search) {
                $q->where(fn ($inner) => $inner
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('student_id', 'like', "%{$search}%"));
            })
            ->when($request->query('role'), fn ($q, $role) => $q->where('role', $role))
            // Legacy boolean filter kept for compatibility with older clients.
            ->when($isActive !== null, fn ($q) => $q->where('is_active', $isActive))
            // Richer tri-state status: active / inactive / locked (lockout).
            ->when($status === 'active', fn ($q) => $q
                ->where('is_active', true)
                ->where(fn ($w) => $w->whereNull('locked_until')->orWhere('locked_until', '<=', now())))
            ->when($status === 'inactive', fn ($q) => $q->where('is_active', false))
            ->when($status === 'locked', fn ($q) => $q->where('locked_until', '>', now()))
            ->when($request->query('department'), fn ($q, $d) => $q->where('department', $d))
            ->when($request->has('needs_review'), function ($q) use ($request) {
                $q->where('needs_review', filter_var(
                    $request->query('needs_review'),
                    FILTER_VALIDATE_BOOLEAN
                ));
            });
    }

    private function present(User $user): array
    {
        $locked = $user->isLocked();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'student_id' => $user->student_id,
            'role' => $user->role,
            'is_active' => (bool) $user->is_active,
            'locked' => $locked,
            'locked_until' => $locked ? $user->locked_until?->toIso8601String() : null,
            'has_voted' => (bool) $user->has_voted,
            'year_level' => $user->year_level,
            'block_number' => $user->block_number,
            'department' => $user->department,
            'course' => $user->course,
            'needs_review' => (bool) $user->needs_review,
            'review_reason' => $user->review_reason,
            'date_added' => $user->created_at?->format('M d, Y'),
        ];
    }

    /**
     * POST /api/admin/users — create a staff account (teacher or admin).
     * A temporary password is generated server-side and returned once so the
     * admin can hand it over securely.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'role' => ['required', 'string', Rule::in(self::CREATABLE_ROLES)],
            'department' => ['nullable', 'string', 'max:100'],
            'course' => ['nullable', 'string', 'max:191'],
        ]);

        $tempPassword = TemporaryPassword::generate();

        [$department, $course] = $this->resolveDepartmentCourse(
            $validated['department'] ?? null,
            $validated['course'] ?? null,
        );

        $user = User::create([
            'name' => trim($validated['name']),
            'email' => strtolower(trim($validated['email'])),
            'password' => $tempPassword, // 'hashed' cast on the model
            'role' => $validated['role'],
            'department' => $department,
            'course' => $course,
            'is_active' => true,
        ]);

        return response()->json([
            'message' => 'Account created.',
            'temporary_password' => $tempPassword,
            'user' => $this->present($user),
        ], 201);
    }

    /**
     * Validate a College → Course pair: course (when given) must be one of the
     * programs the chosen college actually offers; legacy college codes
     * ("CCS") are resolved to the canonical name. Returns [department, course].
     */
    private function resolveDepartmentCourse(?string $department, ?string $course): array
    {
        $department = $department !== null ? trim($department) : null;
        $course = $course !== null ? trim($course) : null;

        if ($department === '') {
            $department = null;
        }
        if ($course === '') {
            $course = null;
        }

        if ($department !== null) {
            $canonical = DepartmentCatalog::resolveCollege($department);
            if ($canonical === null) {
                abort(422, "Department '{$department}' is not in the current department list.");
            }
            $department = $canonical;
        }

        if ($course !== null) {
            if ($department === null) {
                abort(422, 'A department is required when a course is chosen.');
            }
            $resolved = DepartmentCatalog::resolveCourse($department, $course);
            if ($resolved === null) {
                abort(422, "Course '{$course}' is not offered by {$department}.");
            }
            $course = $resolved;
        }

        return [$department, $course];
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

        Notifier::toAdmins(
            'emailOnAdminAction',
            'info',
            'User role changed',
            "{$acting->name} set {$user->name}'s role to {$user->role}.",
            '/users',
        );

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
        // Revoke every Sanctum token so sessions opened before the change cannot
        // outlive it — required when disabling an account (and harmless on
        // re-enable, where the account simply signs in again).
        $user->tokens()->delete();

        Notifier::toAdmins(
            'emailOnAdminAction',
            $validated['is_active'] ? 'success' : 'warning',
            $validated['is_active'] ? 'User account activated' : 'User account disabled',
            "{$acting->name} ".($validated['is_active'] ? 'activated' : 'disabled')." {$user->name}'s account.",
            '/users',
        );

        // Tell the account owner their eligibility changed. Best-effort: a
        // disabled student cannot sign in to read it, but the row is waiting
        // if the account is later re-enabled.
        if ($user->role === 'student') {
            Notifier::notifyUser(
                $user->id,
                $validated['is_active'] ? 'success' : 'warning',
                $validated['is_active'] ? 'Your account was activated' : 'Your account was disabled',
                $validated['is_active']
                    ? 'You can now vote in the election.'
                    : 'Contact your registrar if you believe this is a mistake.',
                '/profile',
            );
        }

        return response()->json(['data' => $this->present($user)]);
    }

    /**
     * PATCH /api/admin/users/{user}/email — correct a typo'd or obsolete email
     * address. The email is the student's sign-in handle on the Flutter app and
     * the registrar-import join key, so a fix here is explicit and validated.
     */
    public function updateEmail(Request $request, User $user): JsonResponse
    {
        $validated = $request->validate([
            // The handle may be a plain name-derived slug (john.michael.valles)
            // for registrar-imported students, so it is not validated as a
            // strict email address.
            'email' => [
                'required', 'string', 'max:255',
                Rule::unique('users', 'email')->ignore($user->id),
            ],
        ]);

        $user->email = strtolower(trim($validated['email']));
        $user->save();

        return response()->json([
            'message' => 'Email address updated.',
            'data' => $this->present($user),
        ]);
    }

    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $acting = $request->user();
        if ($acting->id === $user->id) {
            return response()->json(['message' => 'You cannot reset your own password from here.'], 422);
        }

        // Plaintext value is never persisted; the User model casts 'password' => 'hashed'.
        $tempPassword = TemporaryPassword::generate();
        $user->password = $tempPassword;
        $user->save();
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Temporary password generated.',
            'temporary_password' => $tempPassword,
            'user' => $this->present($user),
        ]);
    }

    /**
     * POST /api/admin/users/{user}/unlock — clear the failed-login backoff
     * (the same state the login controllers set after repeated bad passwords).
     */
    public function unlock(User $user): JsonResponse
    {
        if (! $user->isLocked() && (int) $user->failed_login_attempts === 0) {
            return response()->json(['message' => 'Account is not locked.'], 409);
        }

        $user->clearLockout();

        return response()->json(['data' => $this->present($user)]);
    }

    /**
     * POST /api/admin/users/bulk-unlock — clear the failed-login backoff for
     * every account currently in one.
     *
     * SECURITY (assessment M-3): the lockout was a denial-of-service vector
     * because student handles are derived from names, so five guesses per
     * handle could lock a whole registry out of voting and each victim needed an
     * individual admin unlock. This makes the remedy a single action instead of
     * N, so an administrator can undo an attack in one request.
     *
     * The backoff now also decays on its own (User::BACKOFF_MAX_MINUTES), so
     * this is a convenience for a live incident rather than the only way out.
     */
    public function bulkUnlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Optional guard so an operator cannot clear accounts they did not
            // mean to touch; omit it to unlock everything currently backing off.
            'roles' => ['sometimes', 'array', 'max:5'],
            'roles.*' => ['string', 'in:student,teacher,admin,ssg_president'],
        ]);

        $query = User::query()
            ->whereNotNull('locked_until')
            ->where('locked_until', '>', now());

        if (! empty($validated['roles'])) {
            $query->whereIn('role', $validated['roles']);
        }

        $locked = $query->get(['id', 'failed_login_attempts', 'locked_until']);

        foreach ($locked as $account) {
            $account->clearLockout();
        }

        return response()->json([
            'message' => 'Cleared the failed-login backoff for '.count($locked).' account(s).',
            'unlocked_count' => count($locked),
            'unlocked_user_ids' => $locked->pluck('id')->all(),
        ]);
    }

    /**
     * GET /api/admin/users/certified-winners — candidates flagged as certified
     * winners that can receive the single-seat ssg_president role.
     */
    public function certifiedWinners(): JsonResponse
    {
        $winners = Candidate::query()
            ->where('certified_winner', true)
            ->with(['user:id,name,email,student_id,role', 'position:id,label'])
            ->orderBy('position_id')
            ->get();

        $existingSsg = User::where('role', 'ssg_president')->first();

        return response()->json([
            'data' => $winners->map(fn (Candidate $c) => [
                'candidate_id' => $c->id,
                'candidate_ref' => $c->candidate_ref,
                'user_id' => $c->user_id,
                'name' => $c->user?->name,
                'email' => $c->user?->email,
                'student_id' => $c->user?->student_id,
                'user_role' => $c->user?->role,
                'position' => $c->position?->label,
                'certified_at' => $c->certified_at?->format('M d, Y'),
                'already_ssg_president' => $c->user?->role === 'ssg_president',
            ])->values(),
            'ssg_president' => $existingSsg ? [
                'id' => $existingSsg->id,
                'name' => $existingSsg->name,
                'email' => $existingSsg->email,
            ] : null,
        ]);
    }

    /**
     * POST /api/admin/users/{user}/grant-ssg — hand the single-seat
     * ssg_president role to a certified election winner's account. The
     * backend enforces the certified-winner requirement; the UI only ever
     * offers this action for eligible accounts.
     */
    public function grantSsg(Request $request, User $user): JsonResponse
    {
        $isCertified = Candidate::where('user_id', $user->id)
            ->where('certified_winner', true)
            ->exists();

        if (! $isCertified) {
            return response()->json([
                'message' => 'This user has no certified election winner record. Only certified winners can be granted SSG President access.',
            ], 422);
        }

        $previous = User::where('role', 'ssg_president')
            ->where('id', '!=', $user->id)
            ->get();

        DB::transaction(function () use ($user, $previous) {
            // Single-seat role: demote any previous holder to student first.
            foreach ($previous as $holder) {
                $holder->role = 'student';
                $holder->save();
                $holder->tokens()->delete();
            }
            $user->role = 'ssg_president';
            $user->save();
            $user->tokens()->delete();
        });

        return response()->json([
            'data' => $this->present($user),
            'replaced' => $previous->map(fn (User $u) => [
                'id' => $u->id, 'name' => $u->name, 'email' => $u->email,
            ])->values(),
        ]);
    }

    /**
     * GET /api/admin/users/export — CSV export honouring the same filters as
     * the listing (search, role, status, department, needs_review).
     */
    public function export(Request $request): StreamedResponse
    {
        $fileName = 'omnivote-users-'.now()->format('Ymd-His').'.csv';
        $query = $this->filteredQuery($request)->orderBy('name');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            // Explicit $escape param: PHP 8.4 deprecates relying on the default.
            fputcsv($out, [
                'Name', 'Email', 'Student ID', 'Role', 'Status', 'Department', 'Course',
                'Year Level', 'Block', 'Needs Review', 'Review Reason', 'Date Added',
            ], ',', '"', '\\');

            $query->chunk(500, function ($users) use ($out) {
                foreach ($users as $u) {
                    $status = ! $u->is_active
                        ? 'Inactive'
                        : ($u->isLocked() ? 'Locked' : 'Active');

                    fputcsv($out, [
                        $this->csvCell($u->name),
                        $this->csvCell($u->email),
                        $this->csvCell($u->student_id),
                        $this->csvCell($u->role),
                        $status,
                        $this->csvCell($u->department),
                        $this->csvCell($u->course),
                        $this->csvCell($u->year_level),
                        $this->csvCell($u->block_number),
                        $u->needs_review ? 'Yes' : 'No',
                        $this->csvCell($u->review_reason),
                        $u->created_at?->format('Y-m-d H:i:s'),
                    ], ',', '"', '\\');
                }
            });

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * Neutralize CSV formula injection. Excel/Sheets execute cells beginning
     * with = + - @ (and tab/CR variants), so user-controlled values are
     * prefixed with an apostrophe before being written.
     */
    private function csvCell(mixed $value): string
    {
        $text = (string) ($value ?? '');

        return $text !== '' && preg_match('/^[=+\-@\t\r]/', $text) === 1
            ? "'".$text
            : $text;
    }
}
