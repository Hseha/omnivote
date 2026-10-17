<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\SsgPresidentInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seating and unseating of the single-seat `ssg_president` role.
 *
 * Extracted from AdminUserController so the same handoff (single-seat demotion,
 * session revocation, one-time password-setup invite, in-app notification) is
 * reused by both the manual admin grant and the automatic grant that runs when
 * the election results are published (see ResultsPublisher).
 *
 * SECURITY: the seat is handed over without ever transmitting a password. A
 * promoted President receives a one-time, expiring, single-use setup link via
 * their in-app feed and chooses their own password; only the SHA-256 digest of
 * the token is stored.
 */
class SsgPresidentGrant
{
    /** Whether the account owns a certified election winner record. */
    public static function isCertifiedWinner(User $user): bool
    {
        return Candidate::query()
            ->where('user_id', $user->id)
            ->where('certified_winner', true)
            ->exists();
    }

    /** Whether the results have been published (the seat may be awarded). */
    public static function resultsPublished(): bool
    {
        return (bool) DB::table('election_settings')
            ->where('key', 'results_published')
            ->value('value');
    }

    /**
     * Seat `$user` as SSG President: demote any current holder back to student,
     * flip the role, revoke the outgoing holder's live sessions (the incoming
     * President keeps their own session so the mobile app does not force a
     * re-login), and issue a fresh one-time setup invite.
     *
     * The caller is responsible for eligibility (certified winner + published
     * results); this method only performs the handover.
     */
    public static function grant(User $user, ?User $actor = null): User
    {
        $previous = User::query()
            ->where('role', 'ssg_president')
            ->where('id', '!=', $user->id)
            ->get();

        DB::transaction(function () use ($user, $previous) {
            // Single-seat role: demote any previous holder to student first.
            foreach ($previous as $holder) {
                $holder->role = 'student';
                $holder->ssg_setup_pending = false;
                $holder->save();
                $holder->tokens()->delete();

                // The seat is gone, so the old holder's outstanding setup link
                // must die with it — a stale invite can never be redeemed to
                // re-take the account after a new President is seated.
                SsgPresidentInvite::query()
                    ->where('user_id', $holder->id)
                    ->whereNull('used_at')
                    ->update(['used_at' => now()]);
            }

            $user->role = 'ssg_president';
            // Seed a provisional console username so there is a "temporary
            // username" to change at setup. The account still cannot reach the
            // console until setup completes (EnsureSsgSetupComplete); only the
            // real credential the President chooses is permanent.
            if (blank($user->username)) {
                $user->username = self::provisionalUsername($user);
            }
            // The account works (same sign-in) but its SSG governance features
            // stay locked until the President sets their own credential via the
            // one-time invite — see SsgPresidentInvite and SsgSetupController.
            $user->ssg_setup_pending = true;
            $user->save();

            // The winner's live session is intentionally NOT revoked. The role
            // is read from the database on every request, so the existing token
            // immediately carries the new role; deleting it would only force a
            // re-login and surface as "your session expired" in the app. The
            // President keeps using the mobile app as a student throughout, and
            // activates the governance console separately via the setup link.
            // (A *previous* holder is still revoked above — they lost the seat.)
        });

        // --- Secure credential handoff -------------------------------------
        // No password is generated, emailed, or returned anywhere: a promoted
        // SSG President receives a one-time, expiring, single-use setup link
        // through their in-app notification feed. Because most student handles
        // are name-derived slugs with no deliverable mailbox, in-app is the
        // only channel that actually reaches them.
        $setupToken = SsgPresidentInvite::issue($user, $actor?->id);

        Notifier::notifyUser(
            $user->id,
            'info',
            'You are now the SSG President',
            "Set up your SSG President console by opening the link below and choosing your own username "
                ."and password (temporary username: {$user->username}). "
                ."For your security no password is ever sent to you. "
                ."This link expires in ".SsgPresidentInvite::TTL_MINUTES." minutes and can be used once.",
            '/ssg/setup?token='.$setupToken,
        );

        return $user;
    }

    /**
     * A unique, name-derived placeholder username for a freshly seated
     * President. It only has to be a starting point for the setup screen: the
     * President replaces it with their permanent handle, and the uniqueness
     * check at setup time excludes their own row.
     */
    private static function provisionalUsername(User $user): string
    {
        $base = mb_strtolower(Str::slug((string) $user->name, '.'));
        $base = $base !== '' ? $base : 'ssg.president';

        $candidate = $base;
        $suffix = 1;

        while (User::query()
            ->where('id', '!=', $user->id)
            ->where(function ($query) use ($candidate): void {
                $query->where('username', $candidate)
                    ->orWhereRaw('LOWER(email) = ?', [$candidate]);
            })
            ->exists()) {
            $candidate = $base.'.'.$suffix;
            $suffix++;
        }

        return $candidate;
    }
}
