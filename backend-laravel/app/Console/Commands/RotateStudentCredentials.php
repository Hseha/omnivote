<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\TemporaryPassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

/**
 * Replace the credentials of every student account still carrying a
 * registrar-issued temporary password (security assessment C-1).
 *
 * Why this exists: the import used to provision accounts with the **student ID**
 * as the password and a name-derived login handle. Both halves are public class-
 * list data, so anyone holding the list could sign in as a voter. The import now
 * generates a CSPRNG credential, but accounts provisioned before that fix still
 * have the guessable value until they are rotated.
 *
 * The new credentials are written to a private CSV (0600, outside the public
 * directory) for the registrar to distribute. The plaintext is never stored in
 * the database — only the hash, same as everywhere else.
 *
 * Usage:
 *   php artisan security:rotate-student-credentials --dry-run
 *   php artisan security:rotate-student-credentials
 */
class RotateStudentCredentials extends Command
{
    protected $signature = 'security:rotate-student-credentials
        {--dry-run : Report what would change without touching any account}
        {--all : Also rotate accounts that already use a permanent password}
        {--output= : Absolute path for the credentials CSV}';

    protected $description = "Rotate student accounts still flagged must_change_password to a random credential (assessment C-1)";

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeSettled = (bool) $this->option('all');

        $query = User::query()->where('role', 'student');

        if (! $includeSettled) {
            // Default: only the accounts still holding a temporary credential.
            $query->where('must_change_password', true);
        }

        $targets = $query->orderBy('id')->get();

        if ($targets->isEmpty()) {
            $this->info('No student accounts matched — nothing to rotate.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s %d student account(s).',
            $dryRun ? 'Would rotate' : 'Rotating',
            $targets->count(),
        ));

        if ($dryRun) {
            foreach ($targets->take(10) as $user) {
                $this->line(sprintf(
                    '  - #%d %s <%s> (student_id %s)',
                    $user->id,
                    $user->name,
                    $user->email,
                    $user->student_id ?? '—',
                ));
            }
            if ($targets->count() > 10) {
                $this->line(sprintf('  … and %d more', $targets->count() - 10));
            }
            $this->comment('Dry run: no account was modified.');

            return self::SUCCESS;
        }

        $path = $this->resolveOutputPath($targets->count());
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");

            return self::FAILURE;
        }

        fputcsv($handle, ['student_id', 'name', 'login_handle', 'temporary_password', 'year_level', 'block_number', 'department']);

        $rotated = 0;
        $revoked = 0;

        foreach ($targets as $user) {
            $temporary = TemporaryPassword::generate();

            $user->forceFill([
                'password' => Hash::make($temporary),
                'must_change_password' => true,
                // A rotated credential invalidates any session the previous
                // password may have been used to obtain.
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            // Existing mobile tokens were minted from the old credential.
            $revoked += $user->tokens()->delete();
            $rotated++;

            fputcsv($handle, [
                $user->student_id ?? '',
                $user->name,
                $user->email,
                $temporary,
                $user->year_level ?? '',
                $user->block_number ?? '',
                $user->department ?? '',
            ]);
        }

        fclose($handle);
        chmod($path, 0600);

        if (Schema::hasTable('audit_logs')) {
            // NB: the audit_logs column is `details` (see BackupController::audit
            // and AdminUserController::audit). Using `description` here would
            // throw once the command actually ran (dry-run never reaches it).
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'student_credentials_rotated',
                'entity_type' => 'user',
                'entity_id' => null,
                'details' => "Rotated {$rotated} student credential(s) to random values",
                'ip_address' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->info("=> rotated {$rotated} account(s), revoked {$revoked} active token(s)");
        $this->info("=> credentials written to {$path} (mode 0600)");
        $this->comment('Distribute these out-of-band, then delete the file. The plaintext is not stored in the database.');

        return self::SUCCESS;
    }

    /**
     * Write into storage/app/private (never the public/ web root) with a
     * timestamped name so a re-run cannot silently clobber an undelivered file.
     */
    private function resolveOutputPath(int $count): string
    {
        $option = $this->option('output');

        if (is_string($option) && $option !== '') {
            return $option;
        }

        $dir = storage_path('app/private');

        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }

        return $dir.sprintf(
            '/student-credentials-%s.csv',
            now()->format('Ymd-His'),
        );
    }
}
