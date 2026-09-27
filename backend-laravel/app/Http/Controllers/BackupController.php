<?php

namespace App\Http\Controllers;

use App\Support\AppSettings;
use App\Support\BackupManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Admin backup/restore surface (React Configuration → Backup & Restore).
 *
 *   GET    /api/admin/backups              — list snapshots
 *   POST   /api/admin/backups              — create a snapshot now
 *   GET    /api/admin/backups/{file}/download — download raw file
 *   POST   /api/admin/backups/restore      — replay a snapshot
 *   DELETE /api/admin/backups/{file}       — delete a snapshot
 *
 * Backups are portable JSON snapshots of the election data written to
 * storage/app/backups and (optionally) AES-256 encrypted with APP_KEY. Restore
 * replays inside a single transaction so a failure never leaves a partial DB.
 */
class BackupController extends Controller
{
    /** Wire metadata callers need to render the backup list on first paint. */
    public function index(): JsonResponse
    {
        return response()->json([
            'backups' => BackupManager::list(),
            'settings' => [
                'autoBackup' => AppSettings::backup('autoBackup', true),
                'backupFrequency' => AppSettings::backup('backupFrequency', 'daily'),
                'backupRetention' => AppSettings::backup('backupRetention', 30),
                'remoteStorage' => AppSettings::backup('remoteStorage', false),
                'backupEncryption' => AppSettings::backup('backupEncryption', true),
            ],
        ]);
    }

    /** Create a snapshot right now, honoring the stored encryption toggle. */
    public function store(Request $request): JsonResponse
    {
        // `boolean()` always returns a bool (never null), so it can only be used
        // when the request actually carries the flag; otherwise fall back to the
        // stored setting, which defaults to encrypting (true).
        $encrypt = $request->has('encrypt')
            ? $request->boolean('encrypt')
            : (bool) AppSettings::backup('backupEncryption', true);

        $backup = BackupManager::create($encrypt);

        $pruned = BackupManager::prune((int) AppSettings::backup('backupRetention', 30));

        $this->audit($request, 'backup_created', 'Created backup '.$backup['filename']
            .($pruned ? '; pruned '.implode(', ', $pruned) : ''));

        return response()->json([
            'message' => "Backup created: {$backup['label']}.",
            'backup' => $backup,
            'pruned' => $pruned,
        ], 201);
    }

    /**
     * Replay a snapshot over the current database. Explicitly requires the
     * consumer to name the snapshot that will be applied, then inserts an
     * audit log after the transaction commits.
     */
    public function restore(Request $request): JsonResponse
    {
        $validated = $request->validate(['file' => ['required', 'string']]);

        try {
            $restored = BackupManager::restore($validated['file']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $this->audit($request, 'backup_restored',
            'Restored backup '.$validated['file'].': '.implode(', ', $restored));

        return response()->json([
            'message' => 'Database restored from backup.',
            'restored' => $restored,
        ]);
    }

    /** Stream the raw snapshot file for download. */
    public function download(string $file): BinaryFileResponse
    {
        if (! preg_match('/^omnivote-\d{14}\.omsnapshot$/', $file)) {
            abort(404, 'Invalid backup filename.');
        }

        $path = BackupManager::directory().'/'.$file;
        if (! file_exists($path)) {
            abort(404, 'Backup not found.');
        }

        return response()->download($path, $file);
    }

    /** Delete a stored snapshot. */
    public function destroy(Request $request, string $file): JsonResponse
    {
        if (! BackupManager::delete($file)) {
            return response()->json(['message' => 'Backup not found.'], 404);
        }

        $this->audit($request, 'backup_deleted', 'Deleted backup '.$file);

        return response()->json(['message' => 'Backup deleted.', 'deleted' => $file]);
    }

    /**
     * Write an audit row when the audit_logs table is available (it is a
     * recent addition, so guard against installs migrated before it landed).
     */
    private function audit(Request $request, string $action, string $details): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::table('audit_logs')->insert([
            'user_id' => $request->user()?->id,
            'action' => $action,
            'entity_type' => 'backup',
            'entity_id' => null,
            'details' => $details,
            'ip_address' => $request->ip(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
