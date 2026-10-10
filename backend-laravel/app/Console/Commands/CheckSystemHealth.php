<?php

namespace App\Console\Commands;

use App\Support\Notifier;
use App\Support\SystemHealth;
use Illuminate\Console\Command;

/**
 * Server health probe driven by omnivote-health.timer.
 *
 * Runs every check, caches the snapshot for the admin dashboard, prints a
 * report, and pages the administrators in-app only when the overall status
 * changes (see SystemHealth::transition). Always exits 0 — systemd marks a
 * non-zero oneshot as a failed unit, and a failing check already surfaces
 * through the snapshot/notification, so a non-zero exit would just add noise.
 */
class CheckSystemHealth extends Command
{
    protected $signature = 'omnivote:health';

    protected $description = 'Check database, disk, storage, queue, backups, phase and migrations; alert admins when the overall state changes';

    public function handle(): int
    {
        $snapshot = SystemHealth::run();
        $previous = SystemHealth::stored();

        SystemHealth::persist($snapshot);

        foreach ($snapshot['checks'] as $check) {
            $this->line(sprintf(
                '[%-4s] %-16s %s',
                strtoupper($check['status']),
                $check['label'],
                $check['message'],
            ));
        }
        $this->line('Overall: '.strtoupper($snapshot['overall']));

        $alert = SystemHealth::transition($previous, $snapshot);
        if ($alert !== null) {
            $recipients = Notifier::toAdminUsersInApp(
                $alert['type'],
                $alert['title'],
                $alert['body'],
                '/dashboard',
            );
            $this->warn("=> {$alert['title']} ({$recipients} admin(s) notified)");
        }

        return self::SUCCESS;
    }
}
