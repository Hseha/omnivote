<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Keeps the derived `candidate` role in step with a user's live candidacy.
 *
 * A `candidate` is simply a student who currently holds an approved, in-force
 * candidacy: the role is *derived* state, never assigned by hand. This helper
 * re-derives it after every event that can change it (approval, rejection,
 * withdrawal, tie resolution, reset, term archive) so the two can never drift.
 *
 * Only voter accounts are managed. Staff (`admin`, `teacher`) and the appointed
 * `ssg_president` seat are never touched — a candidate who wins the SSG seat is
 * promoted out of the voter roles and this helper deliberately leaves them
 * alone.
 */
class CandidateRoleSync
{
    /**
     * Re-derive the candidate/student role for one account.
     *
     * Promotes a student to `candidate` when they hold an approved candidacy
     * that has not been archived, and reverts a candidate back to `student`
     * otherwise (rejected, withdrawn, archived, or reset). Idempotent.
     */
    public static function sync(?User $user): void
    {
        if (! $user || ! in_array($user->role, User::VOTER_ROLES, true)) {
            return;
        }

        $target = self::hasLiveCandidacy($user) ? 'candidate' : 'student';

        if ($user->role !== $target) {
            $user->role = $target;
            $user->save();
        }
    }

    /** Re-derive the role for an account referenced only by id (skips nulls). */
    public static function syncId(?int $userId): void
    {
        if ($userId === null) {
            return;
        }

        $user = User::find($userId);

        if ($user) {
            self::sync($user);
        }
    }

    /**
     * Re-derive the role for many accounts at once (e.g. every candidate in a
     * position after a tally or tie-break).
     *
     * @param  iterable<int|null>  $userIds
     */
    public static function syncMany(iterable $userIds): void
    {
        $ids = collect($userIds)->filter()->unique()->values();

        if ($ids->isEmpty()) {
            return;
        }

        User::query()->whereIn('id', $ids)->get()->each(fn (User $u) => self::sync($u));
    }

    /**
     * Whether the account holds a live candidacy.
     *
     * A candidacy is live while it is approved and not archived. Once results
     * are published the race is decided, so only candidates still in the
     * running — the elected and those awaiting a tie-break — keep the role;
     * everyone the tally decided against falls back to `student`.
     */
    private static function hasLiveCandidacy(User $user): bool
    {
        $query = Candidate::query()
            ->where('user_id', $user->id)
            ->where('approval_status', 'approved');

        // Some minimal test schemas omit the archival column; a deployment that
        // has not run the term-archive migration should still behave sensibly.
        if (Schema::hasColumn('candidates', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        if (self::resultsPublished() && Schema::hasColumn('candidates', 'election_status')) {
            $query->whereIn('election_status', ['elected', 'tied']);
        }

        return $query->exists();
    }

    /** Whether the results have been published (see ResultsPublisher). */
    private static function resultsPublished(): bool
    {
        try {
            return (bool) DB::table('election_settings')
                ->where('key', 'results_published')
                ->value('value');
        } catch (\Throwable) {
            // Minimal test schemas may omit the settings table entirely.
            return false;
        }
    }
}
