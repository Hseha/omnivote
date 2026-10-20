<?php

namespace App\Console\Commands;

use App\Models\Phase;
use App\Support\ElectionCycleArchive;
use Illuminate\Console\Command;

/**
 * Capture the election cycle whose voting window has closed.
 *
 * Driven by omnivote-archive-cycle.timer (every ~10 min).
 *
 * The archive normally runs the moment the phase transitions to
 * `voting_closed`, which happens on the first request that reads the phase
 * after the deadline. This command is the backstop: it reads the phase on a
 * timer, so a cycle is captured even when nobody opens the console or the
 * student app between the polls closing and the next working day.
 *
 * Always exits 0 — a non-zero oneshot is a failed systemd unit, so every
 * skip reason is printed instead.
 */
class ArchiveClosedElectionCycle extends Command
{
    protected $signature = 'omnivote:archive-closed-cycle';

    protected $description = 'Capture the election cycle whose voting window has closed';

    public function handle(): int
    {
        // Reading the phase performs the reconciliation: once the clock has
        // passed voting_closes_at, Phase::current() flips the active phase
        // and the cycle archive captures the roster, the ballot and the
        // turnout. Reading it is the whole job.
        $phase = Phase::current();

        $cycleId = ElectionCycleArchive::currentCycleId();

        if ($phase?->name === 'voting_closed') {
            $this->line('Voting is closed; cycle '.($cycleId ?? '(none)').' is on record.');
        } else {
            $this->line('Phase is '.($phase?->name ?? 'not configured').' — nothing to capture.');
        }

        return self::SUCCESS;
    }
}
