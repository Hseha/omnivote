<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central writer for the `audit_logs` trail.
 *
 * Sensitive administrator actions (role/status/email changes, password resets,
 * settings saves, candidate certification, 2FA changes) previously left no
 * durable record, so "who changed this and when" could not be answered after
 * the fact. Every such action now records a row here.
 *
 * The table is optional (older installs may predate it), so the write is a
 * no-op when it is absent — auditing must never break the action it describes.
 */
class AuditLog
{
    public static function record(
        Request $request,
        string $action,
        string $details,
        ?string $entityType = null,
        ?int $entityId = null,
    ): void {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => mb_substr($action, 0, 100),
            'entity_type' => $entityType === null ? null : mb_substr($entityType, 0, 50),
            'entity_id' => $entityId,
            'details' => mb_substr($details, 0, 2000),
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
