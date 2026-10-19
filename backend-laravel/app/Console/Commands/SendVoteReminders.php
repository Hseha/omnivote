<?php

namespace App\Console\Commands;

use App\Models\Phase;
use App\Models\User;
use App\Models\VoteReminder;
use App\Support\Notifier;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Timed voter reminders driven by omnivote-reminders.timer (every ~10 min).
 *
 * Stage coverage — where each reminder comes from:
 *   - "Voting is now open"  → Phase::announce() at the phase transition
 *     (in-app + push via Notifier::notifyStudents; fires once, no ledger row
 *     needed), so this command deliberately does NOT duplicate it.
 *   - "Closing in 1 hour"   → this command, during the final hour before
 *     `voting_closes_at`, to active voters with `has_voted = false`. Each
 *     voter is messaged once: the (user_id, stage) unique row in
 *     vote_reminders is written before notifying, so a timer run that
 *     overlaps or repeats can never double-ping.
 *
 * Always exits 0 (a non-zero oneshot is a failed systemd unit — every skip
 * reason is printed instead), and `--dry-run` reports the recipient count
 * without writing anything.
 */
class SendVoteReminders extends Command
{
    public const STAGE_CLOSING_SOON = 'closing_soon';

    protected $signature = 'omnivote:send-vote-reminders {--dry-run : Report recipients without sending}';

    protected $description = 'Remind students who have not voted during the final hour before the polls close';

    public function handle(): int
    {
        $closesAt = $this->pollsCloseAt();
        $now = now();

        if ($closesAt === null) {
            $this->line('No voting close time configured — nothing to do.');

            return self::SUCCESS;
        }

        // Only the final hour. Before it: too early (the "open" notice and a
        // calm pre-deadline nudge are elsewhere). At/after it: polls closed —
        // that transition already went out through Phase::announce.
        if ($now->lt($closesAt->copy()->subHour()) || $now->gte($closesAt)) {
            $this->line('Outside the closing-reminder window (opens '
                .$closesAt->copy()->subHour()->format('Y-m-d H:i')
                .' | closes '.$closesAt->format('Y-m-d H:i').').');

            return self::SUCCESS;
        }

        $alreadySent = VoteReminder::query()
            ->where('stage', self::STAGE_CLOSING_SOON)
            ->pluck('user_id');

        $recipients = User::query()
            ->voters()
            ->where('is_active', true)
            ->where('has_voted', false)
            ->whereNotIn('id', $alreadySent)
            ->pluck('id')
            ->all();

        if ($recipients === []) {
            $this->line('Every eligible voter has been reminded (or already voted).');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info('[dry-run] '.count($recipients).' voter(s) would be reminded.');

            return self::SUCCESS;
        }

        // Ledger first: once a voter is marked, they are never messaged for
        // this stage again even if the notify step below partially fails.
        $nowStamp = $now;
        VoteReminder::insertOrIgnore(
            array_map(fn (int $userId) => [
                'user_id' => $userId,
                'stage' => self::STAGE_CLOSING_SOON,
                'sent_at' => $nowStamp,
                'created_at' => $nowStamp,
                'updated_at' => $nowStamp,
            ], $recipients),
        );

        $notified = Notifier::notifyUserList(
            $recipients,
            'warning',
            'Voting closes in 1 hour',
            'You have not cast your ballot yet — the polls close at '
                .$closesAt->format('g:i A').'.',
            '/vote-now',
        );

        $this->info("Reminded {$notified} voter(s) before the "
            .$closesAt->format('g:i A').' close.');

        return self::SUCCESS;
    }

    /** The configured poll-close boundary, or null when no window exists. */
    private function pollsCloseAt(): ?Carbon
    {
        $raw = Phase::timeline()['voting_closes_at'] ?? null;

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            return Carbon::parse($raw);
        } catch (\Throwable) {
            return null;
        }
    }
}
