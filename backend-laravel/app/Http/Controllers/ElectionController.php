<?php

namespace App\Http\Controllers;

use App\Http\Requests\ElectionConfigRequest;
use App\Models\Phase;
use App\Models\Position;
use App\Models\RegistrarImport;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
