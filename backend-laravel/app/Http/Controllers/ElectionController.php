<?php

namespace App\Http\Controllers;

use App\Http\Requests\ElectionConfigRequest;
use App\Models\Candidate;
use App\Models\Phase;
use App\Models\Position;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\AuditLog;
use App\Support\TermArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

/**
 * Election lifecycle + configuration surface.
 *
 *   GET  /api/election/status          — public phase for both clients
 *   GET  /api/registration/me          — student registration/turnout card
 *   GET  /api/admin/election/config    — admin setup screen state
 *   PUT  /api/admin/election/config    — persist phase/title/dates/positions
 */
class ElectionController extends Controller
{
    public function status(): JsonResponse
    {
        $phase = Phase::current();
        $settings = $this->settings();

        return response()->json([
            'phase' => $phase?->name,
            'phase_label' => $phase?->name === 'registration_closed'
                ? 'Registration Closed'
                : ($phase?->name === 'voting_open'
                ? 'Voting Open'
                : ($phase?->name === 'voting_closed' ? 'Voting Closed' : ($phase?->name === 'registration' ? 'Registration' : 'Not Configured'))),
            'server_time' => now()->toIso8601String(),
            'registration_opens_at' => $settings['registration_opens_at'],
            'registration_closes_at' => $settings['registration_closes_at'],
            'voting_opens_at' => $settings['voting_opens_at'],
            'voting_closes_at' => $settings['voting_closes_at'],
            'registration_open' => $phase?->name === 'registration',
        ]);
    }

    public function registrationMe(Request $request): JsonResponse
    {
        $user = $request->user();

        // Turnout scoping (security assessment L-3).
        //
        // This endpoint previously returned election-wide roster counters to
        // every authenticated student: `registered_students`,
        // `total_students` and a live `actual_ballots_cast`. During the voting
        // window that running count is election-night tactical information
        // (turnout suppression, bandwagon effects), and the roster totals tell a
        // caller how many accounts exist to attack or lock out.
        //
        // A student now sees only their OWN card. The election-wide aggregates
        // are released once polls close, at which point results are public
        // anyway. The keys stay present (null while polls are open) because the
        // Flutter Turnout model tolerates a missing/null count, and the admin
        // dashboard is unaffected — it reads AdminDashboardController::overview.
        $pollsClosed = Phase::current()?->name === 'voting_closed';

        return response()->json([
            'registration_date' => $user->created_at?->toIso8601String(),
            // Eligibility is derived from actual account state rather than a
            // hardcoded literal: an active student account is the only gate the
            // backend actually enforces (there is no grade/block predicate).
            'eligibility_status' => $user->is_active
                ? 'Eligible Voter'
                : 'Ineligible - Account Disabled',
            'turnout' => [
                // The caller's own ballot state — never an election-wide figure.
                'has_voted' => (bool) $user->has_voted,
                'voted_at' => $user->voted_at?->toIso8601String(),
                // Null while polls are open, published after they close.
                'registered_students' => $pollsClosed ? User::where('role', 'student')->count() : null,
                'total_students' => $pollsClosed
                    ? (RegistrarImport::count() ?: User::where('role', 'student')->count())
                    : null,
                'actual_ballots_cast' => $pollsClosed ? User::where('has_voted', true)->count() : null,
            ],
        ]);
    }

    public function config(Request $request): JsonResponse
    {
        $phase = Phase::current();
        $settings = $this->settings();

        $positions = Position::query()
            ->withCount(['candidates' => fn ($q) => $q->where('approval_status', 'approved')])
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'title' => $p->label,
                'label' => $p->label,
                'tier' => $p->tier,
                'seat_count' => $p->seat_count,
                'active' => $p->is_active,
                // The admin edit form reads these to prefill its scope selects;
                // omitting them made every position open as 'global', so saving
                // an unrelated edit (e.g. the title) silently reset a scoped
                // seat — reopening the provincial tier and the YLR seat.
                'scope_type' => $p->scope_type ?? Position::SCOPE_GLOBAL,
                'scope_value' => $p->scope_value,
                'scope_type_2' => $p->scope_type_2,
                'scope_value_2' => $p->scope_value_2,
                'candidates' => "{$p->candidates_count} candidates approved",
            ]);

        return response()->json([
            'config' => [
                'title' => $settings['title'],
                'phase' => $phase?->name,
                // Election Setup kept the single "Registration / Start" field; map
                // it to the true registration-open date when one exists.
                'registration_opens_at' => $settings['registration_opens_at'] ?? $settings['voting_opens_at'],
                'registration_closes_at' => $settings['registration_closes_at'],
                'voting_opens_at' => $settings['voting_opens_at'],
                'voting_closes_at' => $settings['voting_closes_at'],
                'positions' => $positions,
            ],
        ]);
    }

    public function updateConfig(ElectionConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // The phase is purely time-driven from the Settings → Voting Windows
        // timeline (`Phase::current()` derives it on every read); a manual
        // write here would fight that derivation, so the 'phase' input from
        // the legacy UI is intentionally ignored.

        $this->putSetting('title', $validated['title'] ?? null);

        // The election timeline lives exclusively in Settings → Voting Windows
        // (`SettingsController::syncElectionTimeline`). Do not write the
        // schedule from this screen: a save here must never clobber the
        // Settings-configured window.
        // (Date keys are accepted by ElectionConfigRequest but intentionally
        // not persisted here; Election Setup UI no longer sends them.)

        foreach ($validated['positions'] ?? [] as $pos) {
            $position = Position::where('slug', (string) ($pos['slug'] ?? $pos['id'] ?? ''))->first();
            if (! $position) {
                continue;
            }

            $position->is_active = $pos['active'] ?? $position->is_active;
            $position->seat_count = $pos['seat_count'] ?? $position->seat_count;
            $position->save();
        }

        return response()->json([
            'message' => 'Configuration saved.',
            'config' => $this->config($request)->getData(true)['config'],
        ]);
    }

    /**
     * POST /api/admin/election/reset — comprehensive election reset.
     *
     * Requires an explicit `confirm: "reset_election"` body field so the
     * action cannot be triggered by a stray request.
     *
     * What gets reset (the cycle is wiped for a fresh start):
     *   - Election timeline (all four date keys cleared → no phase derivable)
     *   - Active phase deactivated (dashboard shows "Not Configured")
     *   - Non-winner candidates reset to pending (applications cleared)
     *   - All student turnout flags reset (has_voted = false)
     *   - Anonymous vote ledger + ballot drafts cleared
     *
     * What is PRESERVED (winners become officers, never deleted):
     *   - Certified winners are archived via TermArchive → Past Terms roster
     *   - Their certified_winner, vote_total, winner_rank, certified_at stay intact
     *
     * Schema-robustness: every optional column/table write is guarded by
     * Schema::hasColumn/hasTable so a host that skipped a migration gets a
     * clean slate instead of a 500 mid-transaction. Guards never widen
     * access — permission (`election.reset`) and confirm validation above are
     * unchanged.
     *
     * Admin only (`permission:election.reset`).
     */
    public function reset(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'confirm' => ['required', Rule::in(['reset_election'])],
        ]);

        DB::transaction(function () use ($request) {
            // 1. Archive certified winners so they become preserved officers
            //    (Past Terms roster) before anything else is wiped.
            //    No-op on schemas lacking the archive columns (see TermArchive).
            TermArchive::archiveNow();

            // 2. Clear the election timeline so no phase can be derived.
            if (Schema::hasTable('election_settings')) {
                foreach (['registration_opens_at', 'registration_closes_at', 'voting_opens_at', 'voting_closes_at'] as $key) {
                    DB::table('election_settings')->updateOrInsert(
                        ['key' => $key],
                        ['value' => null, 'updated_at' => now()],
                    );
                }
            }

            // 3. Deactivate every phase row so Phase::current() returns null.
            if (Schema::hasTable('phases')) {
                Phase::query()->update(['is_active' => false]);
            }

            // 4. Reset only non-winner candidates (pending/rejected applications).
            //    Winners stay archived as officers — they are never touched here.
            //    `election_status` is NOT NULL DEFAULT 'pending' in production
            //    (2026_09_20_000004), so it must reset to 'pending' — writing
            //    null 500s on MySQL strict mode (the dashboard "Server Error").
            //    Every optional column is guarded so an install that skipped a
            //    migration still lands the clean slate instead of 500ing.
            if (Schema::hasTable('candidates')) {
                $candidateQuery = Candidate::query();
                if (Schema::hasColumn('candidates', 'certified_winner')) {
                    $candidateQuery->where('certified_winner', false);
                }

                $candidateUpdate = ['updated_at' => now()];
                if (Schema::hasColumn('candidates', 'approval_status')) {
                    $candidateUpdate['approval_status'] = 'pending';
                }
                if (Schema::hasColumn('candidates', 'certified_at')) {
                    $candidateUpdate['certified_at'] = null;
                }
                if (Schema::hasColumn('candidates', 'winner_rank')) {
                    $candidateUpdate['winner_rank'] = null;
                }
                if (Schema::hasColumn('candidates', 'vote_total')) {
                    $candidateUpdate['vote_total'] = 0;
                }
                if (Schema::hasColumn('candidates', 'election_status')) {
                    $candidateUpdate['election_status'] = 'pending';
                }
                if (Schema::hasColumn('candidates', 'rejection_count')) {
                    $candidateUpdate['rejection_count'] = 0;
                }
                if (Schema::hasColumn('candidates', 'reapply_blocked_until')) {
                    $candidateUpdate['reapply_blocked_until'] = null;
                }

                $candidateQuery->update($candidateUpdate);
            }

            // 5. Reset every student's turnout flag so they can vote again.
            $userUpdate = ['updated_at' => now()];
            if (Schema::hasColumn('users', 'has_voted')) {
                $userUpdate['has_voted'] = false;
            }
            if (Schema::hasColumn('users', 'voted_at')) {
                $userUpdate['voted_at'] = null;
            }
            User::where('role', 'student')->update($userUpdate);

            // 6. Clear the anonymous vote ledger. TRUNCATE can 500 on some
            //    engines/permissions inside a transaction (implicit-commit,
            //    FK checks, missing DROP rights), so a plain delete is used —
            //    same end state, same table, no surprises.
            if (Schema::hasTable('vote_ledger')) {
                DB::table('vote_ledger')->delete();
            }

            // 7. Clear pre-submission ballot drafts (user-scoped, pre-vote
            //    artifacts). Without this a student keeps a stale
            //    status='submitted' row after reset and the next cycle's
            //    My Ballot screen misreads. Anonymous ledger secrecy (H-1)
            //    is unaffected — drafts hold no receipts post-submit.
            if (Schema::hasTable('ballot_drafts')) {
                DB::table('ballot_drafts')->delete();
            }
        });

        // Record the reset in the audit trail.
        AuditLog::record(
            $request,
            'election_reset',
            'Admin reset the election cycle: schedule cleared, phase deactivated, non-winner candidates reset, votes cleared, vote ledger truncated. Certified winners were archived as officers (Past Terms).',
            'election',
            null,
        );

        return response()->json([
            'message' => 'Election cycle reset to a clean slate. Non-winner candidates and votes were cleared. Certified winners were archived as officers (Past Terms) and are preserved.',
        ]);
    }

    private function settings(): array
    {
        $rows = DB::table('election_settings')->pluck('value', 'key');

        return [
            'title' => $rows['title'] ?? 'Student Council General Election',
            'registration_opens_at' => $rows['registration_opens_at'] ?? null,
            'registration_closes_at' => $rows['registration_closes_at'] ?? null,
            'voting_opens_at' => $rows['voting_opens_at'] ?? null,
            'voting_closes_at' => $rows['voting_closes_at'] ?? null,
        ];
    }

    private function putSetting(string $key, ?string $value): void
    {
        DB::table('election_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()],
        );
    }
}
