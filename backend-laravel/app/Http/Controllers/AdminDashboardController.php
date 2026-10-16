<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Candidate;
use App\Models\Phase;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\SystemHealth;
use App\Support\TermArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Admin dashboard + results endpoints for the React SPA.
 */
class AdminDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        // Auto-archive the previous term the moment its Term Ends date passes,
        // so the winner "validity" window closes without a manual step.
        TermArchive::runIfDue();

        $totalVoters = User::where('role', 'student')->count();
        $votesCast = User::where('has_voted', true)->count();
        $approvedCandidates = Candidate::where('approval_status', 'approved')->count();

        return response()->json([
            'stats' => [
                'total_voters' => $totalVoters,
                'votes_cast' => $votesCast,
                'turnout_rate' => $totalVoters > 0 ? round(($votesCast / $totalVoters) * 100, 1) : 0,
                'approved_candidates' => $approvedCandidates,
            ],
            'election_phase' => Phase::current()?->name,
            'announcements' => Announcement::query()
                ->published()
                ->with('author:id,name,avatar_url')
                ->latest('published_at')
                ->limit(5)
                ->get()
                ->map(fn (Announcement $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'published_at' => $a->published_at?->toIso8601String(),
                    'author' => $a->author ? [
                        'name' => $a->author->name,
                        'avatar_url' => $a->author->avatar_url,
                    ] : null,
                ]),
            'recent_actions' => UserNotification::query()
                ->for(auth()->id())
                ->limit(8)
                ->get()
                ->map(fn (UserNotification $n) => [
                    'type' => $n->type,
                    'title' => $n->title,
                    'body' => $n->body,
                    'link' => $n->link,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'read' => $n->isRead(),
                ]),
            'user' => [
                'name' => auth()->user()->name,
                'role' => match (auth()->user()->role) {
                    'admin' => 'System Administrator',
                    'ssg_president' => 'SSG President',
                    default => 'SSG Adviser',
                },
            ],
            // Latest cached health snapshot (written by omnivote:health).
            // Admin-only: teachers / SSG president see the plain API-ok card.
            'system_health' => auth()->user()->role === 'admin' ? SystemHealth::stored() : null,
        ]);
    }

    /**
     * Unified administrative analytics feed for the dashboard monitoring
     * panels (GET /api/admin/dashboard/analytics, `permission:dashboard.view`):
     *
     *  - `velocity`: hourly vote-ledger counts over the trailing 24 clock
     *    hours as `[{hour: "HH:00", count: X}, ...]`, oldest → newest, with
     *    empty buckets zero-filled so the frontend chart never has to guess
     *    gaps.
     *  - `security_logs`: the latest 6 operational / security events, lifted
     *    from `audit_logs` when the trail exists (older installs may predate
     *    it) and degrading to the newest admin `user_notifications` rows of
     *    system severity types otherwise.
     *
     * CRYPTOGRAPHIC ANONYMITY (H-1): the velocity query reads `vote_ledger`
     * alone — position/candidate/receipt columns — and is never joined
     * against `users`, so no per-voter linkage can leak through metrics.
     */
    public function analytics(): JsonResponse
    {
        return response()->json([
            'velocity' => $this->hourlyVoteVelocity(),
            'security_logs' => $this->securityLogs(),
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Distinct-ballot counts bucketed per hour over the trailing 24 h.
     *
     * A submission writes one `vote_ledger` row per selected candidate, so a
     * raw row `COUNT(*)` reports *selections* and is inflated by the number of
     * positions on the ballot. Every row of a single ballot shares its
     * `receipt_hmac`, so `COUNT(DISTINCT receipt_hmac)` is the true number of
     * ballots cast in the hour.
     *
     * Buckets are keyed on the full hour stamp (not hour-of-day) and the window
     * is exactly the 24 hour-slots shown — the current hour plus the 23 before
     * it, each anchored to the start of its hour — so a bucket can never absorb
     * a partial hour from roughly 24 h earlier.
     *
     * The bucket expression is chosen from the active driver (SQLite's
     * `strftime`, MySQL/MariaDB's `DATE_FORMAT`, PostgreSQL's `TO_CHAR`) so the
     * aggregation stays in SQL on every supported engine; any other driver
     * falls back to bucketing in PHP.
     *
     * @return array<int, array{hour: string, count: int}>
     */
    private function hourlyVoteVelocity(): array
    {
        // 24 hourly slots ending with the current hour: the current hour plus
        // the 23 before it, each anchored to the start of its hour.
        $currentHour = now()->startOfHour();
        $slots = collect(range(23, 0))
            ->map(fn (int $back) => $currentHour->copy()->subHours($back))
            ->values();

        // Plain array keyed by the full 'Y-m-d H:00' stamp so two different
        // days' same clock-hour can never collide; a Collection's ArrayAccess
        // offset can't be modified by reference, so we merge into an array.
        $buckets = [];

        foreach ($slots as $slot) {
            $buckets[$slot->format('Y-m-d H:00')] = ['hour' => $slot->format('H:00'), 'count' => 0];
        }

        if (! Schema::hasTable('vote_ledger')) {
            return array_values($buckets);
        }

        // Start of the oldest slot — anything earlier is outside the window.
        $windowStart = $slots->first();

        $driver = DB::connection()->getDriverName();

        $bucketExpression = match ($driver) {
            'sqlite' => "strftime('%Y-%m-%d %H:00', recorded_at)",
            'mysql', 'mariadb' => "DATE_FORMAT(recorded_at, '%Y-%m-%d %H:00')",
            'pgsql' => "TO_CHAR(recorded_at, 'YYYY-MM-DD HH24:00')",
            default => null,
        };

        if ($bucketExpression !== null) {
            // COUNT(DISTINCT receipt_hmac): one ballot, many rows, one ballot.
            $counts = DB::table('vote_ledger')
                ->selectRaw($bucketExpression.' as bucket, COUNT(DISTINCT receipt_hmac) as aggregate')
                ->where('recorded_at', '>=', $windowStart)
                ->groupByRaw($bucketExpression)
                ->get()
                ->pluck('aggregate', 'bucket');
        } else {
            // Portable fallback: bucket in PHP, still counting distinct ballots
            // and still fully decoupled from any user identity.
            $counts = DB::table('vote_ledger')
                ->where('recorded_at', '>=', $windowStart)
                ->get(['recorded_at', 'receipt_hmac'])
                ->groupBy(fn ($row) => Carbon::parse($row->recorded_at)->format('Y-m-d H:00'))
                ->map(fn ($rows) => $rows->pluck('receipt_hmac')->unique()->count());
        }

        foreach ($counts as $bucketKey => $count) {
            // SQLite returns the strftime() string verbatim; MySQL/Postgres may
            // pad/format slightly, so normalize to the same 'Y-m-d H:00' key.
            $key = Carbon::parse($bucketKey)->format('Y-m-d H:00');

            if (isset($buckets[$key])) {
                $buckets[$key]['count'] = (int) $count;
            }
        }

        return array_values($buckets);
    }

    /**
     * Latest 6 security / operational events for the audit stream.
     *
     * Prefers `audit_logs` (the durable trail written by App\Support\AuditLog)
     * and falls back to the admin `user_notifications` feed on installs whose
     * schema predates that table, so the panel degrades honestly instead of
     * erroring. IP addresses and affected entities are folded into one
     * readable context snippet per row.
     *
     * @return array<int, array{id: string|int, action: string, label: string, severity: string, detail: string, at: string|null}>
     */
    private function securityLogs(): array
    {
        if (Schema::hasTable('audit_logs')) {
            $hasUsers = Schema::hasTable('users');

            $query = DB::table('audit_logs')->orderByDesc('audit_logs.id')->limit(6);

            if ($hasUsers) {
                $query->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                    ->select('audit_logs.*', 'users.name as actor_name');
            } else {
                $query->select('audit_logs.*');
            }

            return $query->get()->map(function ($row) {
                $action = (string) $row->action;

                return [
                    'id' => $row->id,
                    'action' => $action,
                    'label' => $this->humanizeAction($action),
                    'severity' => $this->auditSeverity($action),
                    'detail' => $this->auditDetailSnippet((array) $row),
                    'at' => $row->created_at
                        ? Carbon::parse($row->created_at)->toIso8601String()
                        : null,
                ];
            })->values()->all();
        }

        // Fallback: newest admin notifications carrying a system severity type.
        return UserNotification::query()
            ->whereIn('type', ['success', 'warning', 'danger', 'info'])
            ->latest()
            ->limit(6)
            ->get()
            ->map(fn (UserNotification $n) => [
                'id' => 'n:'.$n->id,
                'action' => (string) $n->type,
                'label' => $n->title ?: 'System event',
                'severity' => match ($n->type) {
                    'danger' => 'critical',
                    'warning' => 'warning',
                    default => 'success',
                },
                'detail' => (string) $n->body,
                'at' => $n->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /** Maps an audit action to the panel's three-tier severity scale. */
    private function auditSeverity(string $action): string
    {
        return match (true) {
            // Active brute-force backoffs, denied sign-ins, disabled accounts
            // and other hard security stops.
            in_array($action, [
                'account_locked',
                'login_backoff_triggered',
                'login_denied',
                'user_disabled',
                '2fa_failed',
            ], true) => 'critical',
            // Manual overrides an operator should review.
            in_array($action, [
                'login_failed',
                'user_role_changed',
                'user_email_changed',
                'settings_updated',
                'password_reset',
                'backup_restored',
                'backup_deleted',
                '2fa_disabled',
                'user_archived',
            ], true) => 'warning',
            // Routine operational flags: sign-ins, backups, credential
            // rotations, health-adjacent account changes.
            default => 'success',
        };
    }

    /**
     * Folds the row's IP, actor and affected entity into a single readable
     * snippet, e.g. `Created backup omnivote-2026-10-16.zip · 192.0.2.14`.
     *
     * @param  array<string, mixed>  $row
     */
    private function auditDetailSnippet(array $row): string
    {
        $context = [];

        if (! empty($row['ip_address'])) {
            $context[] = 'IP '.$row['ip_address'];
        }

        if (! empty($row['actor_name'])) {
            $context[] = (string) $row['actor_name'];
        } elseif (! empty($row['entity_type']) && ! empty($row['entity_id'])) {
            $context[] = $row['entity_type'].' #'.$row['entity_id'];
        }

        $details = trim((string) ($row['details'] ?? ''));

        return $context === []
            ? $details
            : ($details === '' ? implode(' · ', $context) : $details.' · '.implode(' · ', $context));
    }

    /** `user_role_changed` → `User Role Changed`. */
    private function humanizeAction(string $action): string
    {
        return ucwords(str_replace('_', ' ', $action));
    }

    public function results(): JsonResponse
    {
        TermArchive::runIfDue();

        // Reuse the exact same projection as the public results endpoint.
        return app(ResultsController::class)->index();
    }
}
