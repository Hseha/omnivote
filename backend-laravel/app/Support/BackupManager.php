<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Portable snapshot backup/restore for the election data.
 *
 * All business data (users, positions, candidates, phases, the settings store,
 * vote ledger, ballot drafts, registrar imports, announcements, audit trail)
 * is captured into a single JSON snapshot under storage/app/backups. Restore
 * truncates those tables and replays the snapshot inside one transaction so a
 * failure rolls everything back — a partial restore is never left behind.
 *
 * Files are named `omnivote-<timestamp>.omsnapshot` and, when the Backup
 * Encryption setting is on, their contents are AES-256-CBC encrypted with a key
 * derived from APP_KEY (never stored alongside the file).
 */
class BackupManager
{
    /** Format tag, bumped on structural change. */
    private const FORMAT = 'omnivote-snapshot-v1';

    /** Business tables included in a snapshot, in restore (dependency) order. */
    public const TABLES = [
        'users',
        'phases',
        'positions',
        'candidates',
        'election_settings',
        'registrar_imports',
        'vote_ledger',
        'ballot_drafts',
        'announcements',
        'audit_logs',
        'personal_access_tokens',
    ];

    /**
     * Business tables for a snapshot, in restore (dependency) order. Filtered
     * to those that actually exist in the current schema at run time so older
     * installs (e.g. missing audit_logs) still back up cleanly.
     */
    public static function tables(): array
    {
        return array_values(array_filter(
            self::TABLES,
            fn (string $table) => Schema::hasTable($table),
        ));
    }

    public static function directory(): string
    {
        return storage_path('app/backups');
    }

    public static function ensureDirectory(): void
    {
        File::ensureDirectoryExists(self::directory());
    }

    /** Human label for one snapshot file (from its timestamp). */
    public static function label(string $filename): string
    {
        if (preg_match('/omnivote-(\d{14})\.omsnapshot/', $filename, $m)) {
            return Carbon::createFromFormat('YmdHis', $m[1])->toDateTimeString();
        }

        return $filename;
    }

    /** All snapshot files, newest first, each with size + payload summary. */
    public static function list(): array
    {
        self::ensureDirectory();

        return collect(File::glob(self::directory().'/omnivote-*.omsnapshot'))
            ->map(fn (string $path) => [
                'filename' => basename($path),
                'label' => self::label(basename($path)),
                'size' => File::size($path),
                'size_label' => self::humanSize(File::size($path)),
                'created_at' => self::label(basename($path)),
                'encrypted' => str_starts_with(ltrim((string) File::get($path)), 'ENC|'),
            ])
            ->sortByDesc(fn (array $b) => $b['filename'])
            ->values()
            ->all();
    }

    /**
     * Capture every business table into a fresh snapshot. Returns file info.
     *
     * @param  bool  $encrypted  honor the Backup Encryption setting or not
     */
    public static function create(bool $encrypted): array
    {
        self::ensureDirectory();

        $name = 'omnivote-'.now()->format('YmdHis').'.omsnapshot';

        $data = [
            'format' => self::FORMAT,
            'created_at' => now()->toIso8601String(),
            'app' => config('app.name'),
            'tables' => [],
        ];

        foreach (self::tables() as $table) {
            $data['tables'][$table] = DB::table($table)->get()->all();
        }

        $payload = json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $payload = $encrypted ? self::encrypt($payload) : $payload;

        File::put(self::directory().'/'.$name, $payload);

        $backup = ['filename' => $name, 'label' => self::label($name), 'created_at' => self::label($name)];
        $backup['size'] = File::size(self::directory().'/'.$name);
        $backup['size_label'] = self::humanSize($backup['size']);
        $backup['encrypted'] = $encrypted;

        return $backup;
    }

    /**
     * Replay a snapshot into the database inside one transaction. The snapshot
     * is validated before any write; FK constraints are suspended for the
     * replay and core tables are cleared (children first) before refilling.
     *
     * @throws \RuntimeException on invalid/missing file or driver mismatch
     */
    public static function restore(string $filename): array
    {
        self::ensureDirectory();

        $path = self::directory().'/'.$filename;
        if (! File::exists($path)) {
            throw new \RuntimeException('Backup file not found.');
        }
        if (! preg_match('/^omnivote-\d{14}\.omsnapshot$/', $filename)) {
            throw new \RuntimeException('Invalid backup filename.');
        }

        $raw = ltrim((string) File::get($path), "\xEF\xBB\xBF");
        if (str_starts_with($raw, 'ENC|')) {
            $raw = self::decrypt($raw);
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            // json_decode reports malformed JSON as a JsonException, which is
            // not a RuntimeException. Normalise it to the documented contract
            // so a caller restoring a truncated/corrupt snapshot answers with a
            // validation error (422) instead of an unhandled 500.
            throw new \RuntimeException('Unsupported or corrupted backup file.');
        }

        if (($data['format'] ?? null) !== self::FORMAT || ! is_array($data['tables'] ?? null)) {
            throw new \RuntimeException('Unsupported or corrupted backup file.');
        }

        $restored = [];

        DB::transaction(function () use ($data, &$restored) {
            $driver = DB::getDriverName();

            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=0');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys=OFF');
            }

            // Clear in reverse dependency order (children before parents).
            foreach (array_reverse(self::tables()) as $table) {
                DB::table($table)->delete();
            }

            // Refill in dependency order (parents before children).
            foreach (self::tables() as $table) {
                $rows = $data['tables'][$table] ?? [];
                if (! empty($rows)) {
                    DB::table($table)->insert(array_map(fn ($r) => (array) $r, $rows));
                }
                $restored[$table] = count($rows);
            }

            if ($driver === 'mysql') {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            } elseif ($driver === 'sqlite') {
                DB::statement('PRAGMA foreign_keys=ON');
            }
        });

        return $restored;
    }

    public static function delete(string $filename): bool
    {
        if (! preg_match('/^omnivote-\d{14}\.omsnapshot$/', $filename)) {
            return false;
        }

        $path = self::directory().'/'.$filename;
        if (! File::exists($path)) {
            return false;
        }

        return File::delete($path);
    }

    /** Enforce the retention setting: drop snapshots older than $days. */
    public static function prune(int $days): array
    {
        if ($days < 1) {
            return [];
        }

        $cutoff = now()->subDays($days);

        $removed = [];
        foreach (self::list() as $backup) {
            $created = File::lastModified(self::directory().'/'.$backup['filename']);
            if ($created < $cutoff->timestamp) {
                if (self::delete($backup['filename'])) {
                    $removed[] = $backup['filename'];
                }
            }
        }

        return $removed;
    }

    public static function humanSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return number_format($bytes / 1024 / 1024, 2).' MB';
        }
        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    private static function encrypt(string $plaintext): string
    {
        $key = self::cipherKey();
        $iv = random_bytes(12);
        $tag = '';

        // AES-256-GCM authenticates the ciphertext, so a tampered or truncated
        // snapshot fails to decrypt instead of being silently restored.
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new \RuntimeException('Failed to encrypt backup.');
        }

        return 'ENC|'.base64_encode($iv.$tag.$ciphertext);
    }

    private static function decrypt(string $payload): string
    {
        $key = self::cipherKey();
        $blob = base64_decode(substr($payload, 4), true);

        // 12-byte IV + 16-byte GCM tag + at least 1 byte of ciphertext.
        if ($blob === false || strlen($blob) < 29) {
            throw new \RuntimeException('Encrypted backup is corrupted.');
        }

        $iv = substr($blob, 0, 12);
        $tag = substr($blob, 12, 16);
        $ciphertext = substr($blob, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new \RuntimeException('Failed to decrypt backup (APP_KEY may have changed or the file was tampered with).');
        }

        return $plaintext;
    }

    /** 32-byte key derived from APP_KEY so no secret is stored in the file. */
    private static function cipherKey(): string
    {
        return substr(hash('sha256', (string) config('app.key'), true), 0, 32);
    }
}
