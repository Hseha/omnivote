<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only view over the `audit_logs` trail (React Audit Log screen).
 *
 * GET /api/admin/audit-logs — paginated, filterable listing of sensitive
 * administrator actions and admin sign-in events.
 *
 * Admin only (`permission:audit.view`). The listing is a projection, never the
 * raw rows: only the columns the screen renders leave the server, and the
 * optional `user_agent` column is included only when it exists (the table
 * predates it on older installs).
 */
class AdminAuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['nullable', 'string', 'max:100'],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'entity_type' => ['nullable', 'string', 'max:50'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // The table is optional on older installs (see AuditLog). An absent
        // trail is an empty trail, not an error.
        if (! Schema::hasTable('audit_logs')) {
            return $this->emptyResponse((int) $request->integer('per_page', 25));
        }

        $hasUserAgent = Schema::hasColumn('audit_logs', 'user_agent');
        $hasUsers = Schema::hasTable('users');

        $query = DB::table('audit_logs')->orderByDesc('audit_logs.id');

        if ($hasUsers) {
            // Left join so a row whose actor was deleted still appears (with a
            // null name) instead of vanishing from the trail.
            $query->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
                ->select('audit_logs.*', 'users.name as user_name');
        } else {
            $query->select('audit_logs.*');
        }

        if ($action = (string) $request->string('action')) {
            $query->where('audit_logs.action', $action);
        }

        if ($userId = $request->integer('user_id')) {
            $query->where('audit_logs.user_id', $userId);
        }

        if ($entityType = (string) $request->string('entity_type')) {
            $query->where('audit_logs.entity_type', $entityType);
        }

        if ($from = $request->date('date_from')) {
            $query->where('audit_logs.created_at', '>=', $from->startOfDay());
        }

        if ($to = $request->date('date_to')) {
            $query->where('audit_logs.created_at', '<=', $to->endOfDay());
        }

        $page = $query->paginate($request->integer('per_page', 25));

        $data = collect($page->items())
            ->map(fn ($row) => $this->present((array) $row, $hasUserAgent))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => [
                'total' => $page->total(),
                'per_page' => $page->perPage(),
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function present(array $row, bool $hasUserAgent): array
    {
        $presented = [
            'id' => $row['id'],
            'user_id' => $row['user_id'],
            'user_name' => $row['user_name'] ?? null,
            'action' => $row['action'],
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'],
            'details' => $row['details'],
            'ip_address' => $row['ip_address'],
            'created_at' => $row['created_at'],
        ];

        if ($hasUserAgent) {
            $presented['user_agent'] = $row['user_agent'] ?? null;
        }

        return $presented;
    }

    private function emptyResponse(int $perPage): JsonResponse
    {
        return response()->json([
            'data' => [],
            'meta' => [
                'total' => 0,
                'per_page' => max(1, min(100, $perPage)),
                'current_page' => 1,
                'last_page' => 1,
            ],
        ]);
    }
}
