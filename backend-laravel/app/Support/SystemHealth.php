<?php

namespace App\Support;

use App\Models\Phase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Point-in-time server health probe for the OmniVote backend.
 *
 * Every check is self-contained and never throws: a failing dependency is
 * reported as a `fail`/`warn` status rather than bubbling an exception into
 * the scheduler or the request that triggered it. The engine is deliberately
 * dependency-light (DB, filesystem, config) so it can run under www-data from
 * a short-lived systemd timer.
 *
 * Statuses, in ascending severity: `ok` < `warn` < `fail`. The overall status
 * is the worst of the individual checks. The latest snapshot is cached on disk
 * so the admin dashboard can render it without paying the probe cost on every
 * page load; `transition()` decides whether a state change warrants paging the
 * administrators (and, intentionally, is silent on a first-ever reading unless
 * that reading is already a hard failure).
 */
class SystemHealth
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /** Disk usage (percent) at which we warn / fail. */
    private const DISK_WARN = 80;
    private const DISK_FAIL = 90;

    /**
     * Run every check and return a snapshot. Does not persist.
     *
     * @return array{overall: string, status: string, checked_at: string, checks: array<string, array{status: string, label: string, message: string}>}
     */
    public static function run(): array
    {
        $checks = [
            'database' => self::checkDatabase(),
            'storage' => self::checkStorage(),
            'disk' => self::checkDisk(),
            'queue' => self::checkQueue(),
            'backup' => self::checkBackup(),
            'phase' => self::checkPhase(),
            'migrations' => self::checkMigrations(),
        ];

        $overall = self::worst(array_map(fn (array $c) => $c['status'], $checks));

        return [
            'overall' => $overall,
            'status' => $overall,
            'checked_at' => now()->toIso8601String(),
            'checks' => $checks,
        ];
    }

    /**
     * The admin alert for a status change, or null when nothing should be sent.
     *
     * A first-ever reading only alerts on a hard failure (so a fresh deploy
     * does not page admins over a benign warning); an unchanged reading never
     * alerts, so a persistent problem is reported once, not every interval.
     *
     * @param  array|null  $previous  the previously persisted snapshot
     * @param  array  $current  the freshly computed snapshot
     * @return array{type: string, title: string, body: string}|null
     */
    public static function transition(?array $previous, array $current): ?array
    {
        $now = $current['overall'] ?? self::OK;
        $before = $previous['overall'] ?? null;

        if ($before === null && $now !== self::FAIL) {
            return null;
        }
        if ($before === $now) {
            return null;
        }

        [$type, $title] = match ($now) {
            self::FAIL => ['danger', 'System health: DOWN'],
            self::WARN => ['warning', 'System health: degraded'],
            default => ['success', 'System health: recovered'],
        };

        return ['type' => $type, 'title' => $title, 'body' => self::summary($current)];
    }

    /** One-line human summary of the non-ok checks (or an all-clear note). */
    public static function summary(array $snapshot): string
    {
        $checks = $snapshot['checks'] ?? [];
        $problems = array_filter(
            $checks,
            fn (array $c) => ($c['status'] ?? self::OK) !== self::OK,
        );

        if ($problems === []) {
            return 'All checks passed at '.($snapshot['checked_at'] ?? now()->toIso8601String()).'.';
        }

        $parts = array_map(
            fn (array $c) => ($c['label'] ?? 'Check').': '.($c['message'] ?? ''),
            $problems,
        );

        return implode('; ', $parts);
    }

    /** Absolute path of the cached snapshot. Overridable for tests. */
    public static function path(): string
    {
        return (string) (config('omnivote.health_snapshot') ?: storage_path('app/monitoring/health.json'));
    }

    /** Persist a snapshot for the dashboard to read back. Best-effort. */
    public static function persist(array $snapshot): void
    {
        try {
            File::ensureDirectoryExists(dirname(self::path()));
            File::put(self::path(), json_encode($snapshot, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } catch (\Throwable) {
            // A monitoring probe that breaks the app would defeat its purpose.
        }
    }

    /** The last persisted snapshot, or null when the timer has never run. */
    public static function stored(): ?array
    {
        try {
            $path = self::path();
            if (! File::exists($path)) {
                return null;
            }

            $data = json_decode((string) File::get($path), true);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    private static function checkDatabase(): array
    {
        try {
            $start = microtime(true);
            DB::select('select 1');
            $ms = (int) round((microtime(true) - $start) * 1000);

            return self::result($ms > 1000 ? self::WARN : self::OK, 'Database', "Connected in {$ms} ms");
        } catch (\Throwable) {
            return self::result(self::FAIL, 'Database', 'Connection failed');
        }
    }

    private static function checkStorage(): array
    {
        try {
            $dir = storage_path('app');
            File::ensureDirectoryExists($dir);
            $probe = $dir.'/health-probe-'.bin2hex(random_bytes(4)).'.tmp';
            File::put($probe, 'ok');
            $writable = File::get($probe) === 'ok';
            File::delete($probe);

            return self::result(
                $writable ? self::OK : self::FAIL,
                'Storage',
                $writable ? 'Writable' : 'Not writable',
            );
        } catch (\Throwable) {
            return self::result(self::FAIL, 'Storage', 'Not writable');
        }
    }

    private static function checkDisk(): array
    {
        try {
            $path = storage_path();
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                return self::result(self::WARN, 'Disk', 'Usage unknown');
            }

            $usedPct = (int) round((($total - $free) / $total) * 100);
            $status = match (true) {
                $usedPct >= self::DISK_FAIL => self::FAIL,
                $usedPct >= self::DISK_WARN => self::WARN,
                default => self::OK,
            };

            return self::result($status, 'Disk', "{$usedPct}% used (".BackupManager::humanSize((int) $free).' free)');
        } catch (\Throwable) {
            return self::result(self::WARN, 'Disk', 'Usage unknown');
        }
    }

    private static function checkQueue(): array
    {
        try {
            $failed = Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0;
            $pending = Schema::hasTable('jobs') ? (int) DB::table('jobs')->count() : 0;

            if ($failed > 0) {
                return self::result(self::WARN, 'Queue', "{$failed} failed job(s)");
            }

            return self::result(self::OK, 'Queue', "{$pending} pending");
        } catch (\Throwable) {
            return self::result(self::WARN, 'Queue', 'Unknown');
        }
    }

    private static function checkBackup(): array
    {
        try {
            $backups = BackupManager::list();
            if ($backups === []) {
                return self::result(self::WARN, 'Backup', 'No snapshots yet');
            }

            $retention = max(1, (int) AppSettings::backup('backupRetention', 30));
            $latest = $backups[0];
            $created = Carbon::parse($latest['created_at'] ?? $latest['label'] ?? 'now');
            $ageDays = (int) $created->diffInDays(now());

            $status = match (true) {
                $ageDays >= $retention => self::FAIL,
                $ageDays >= (int) max(1, floor($retention / 2)) => self::WARN,
                default => self::OK,
            };

            $age = $ageDays <= 0 ? 'today' : "{$ageDays}d old";

            return self::result($status, 'Backup', "Latest {$age}");
        } catch (\Throwable) {
            return self::result(self::WARN, 'Backup', 'Unknown');
        }
    }

    private static function checkPhase(): array
    {
        try {
            $phase = Phase::current()?->name;
            if ($phase === null) {
                // No election scheduled yet is a normal state, not a fault.
                return self::result(self::OK, 'Election phase', 'Not configured');
            }

            $display = match ($phase) {
                'voting_open' => 'Voting Open',
                'voting_closed' => 'Voting Closed',
                'registration' => 'Registration',
                'registration_closed' => 'Registration Closed',
                default => $phase,
            };

            return self::result(self::OK, 'Election phase', $display);
        } catch (\Throwable) {
            return self::result(self::WARN, 'Election phase', 'Unknown');
        }
    }

    private static function checkMigrations(): array
    {
        try {
            if (! Schema::hasTable('migrations')) {
                return self::result(self::WARN, 'Migrations', 'Unknown');
            }

            $ran = (int) DB::table('migrations')->count();
            $files = count(File::glob(database_path('migrations/*.php')));
            $pending = max(0, $files - $ran);

            return $pending > 0
                ? self::result(self::WARN, 'Migrations', "{$pending} pending")
                : self::result(self::OK, 'Migrations', 'Up to date');
        } catch (\Throwable) {
            return self::result(self::WARN, 'Migrations', 'Unknown');
        }
    }

    /** @return array{status: string, label: string, message: string} */
    private static function result(string $status, string $label, string $message): array
    {
        return ['status' => $status, 'label' => $label, 'message' => $message];
    }

    /** @param  string[]  $statuses */
    private static function worst(array $statuses): string
    {
        if (in_array(self::FAIL, $statuses, true)) {
            return self::FAIL;
        }
        if (in_array(self::WARN, $statuses, true)) {
            return self::WARN;
        }

        return self::OK;
    }
}
