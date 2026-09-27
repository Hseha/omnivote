<?php

namespace App\Console\Commands;

use App\Support\AppSettings;
use App\Support\BackupManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create a snapshot right now (or invoked by the scheduler on the configured
 * auto-backup interval). Honors the stored Backup Encryption and retention
 * settings, then removes expired snapshots.
 */
class CreateBackup extends Command
{
    protected $signature = 'omnivote:backup {--encrypt=auto : Force "yes" or "no", otherwise the saved setting}';

    protected $description = 'Create an election data snapshot and prune expired backups';

    public function handle(): int
    {
        $encrypt = match ($this->option('encrypt')) {
            'yes' => true,
            'no' => false,
            default => (bool) AppSettings::backup('backupEncryption', true),
        };

        $backup = BackupManager::create($encrypt);

        $retention = (int) AppSettings::backup('backupRetention', 30);
        $pruned = BackupManager::prune($retention);

        $this->info("=> created {$backup['filename']} ({$backup['size_label']})");
        if ($pruned) {
            $this->info('=> pruned expired backups: '.implode(', ', $pruned));
        } else {
            $this->info("=> no expired backups (retention {$retention}d)");
        }

        if (Schema::hasTable('audit_logs')) {
            DB::table('audit_logs')->insert([
                'user_id' => null,
                'action' => 'backup_created',
                'entity_type' => 'backup',
                'entity_id' => null,
                'details' => 'Scheduled backup '.$backup['filename']
                    .($pruned ? '; pruned '.implode(', ', $pruned) : ''),
                'ip_address' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return self::SUCCESS;
    }
}
