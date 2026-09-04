<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
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
            'phase' => $phase?->name ?? 'registration',
            'phase_label' => $phase?->name === 'voting_open'
                ? 'Voting Open'
                : ($phase?->name === 'voting_closed' ? 'Voting Closed' : ($phase?->name === 'registration' ? 'Registration' : $phase?->name)),
            'server_time' => now()->toIso8601String(),
            'voting_opens_at' => $settings['voting_opens_at'],
            'voting_closes_at' => $settings['voting_closes_at'],
            'registration_open' => ($phase?->name ?? 'registration') === 'registration',
        ]);
    }

    public function registrationMe(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'registration_date' => $user->created_at?->toIso8601String(),
            'eligibility_status' => 'Eligible Voter',
            'turnout' => [
                'registered_students' => User::where('role', 'student')->count(),
                'total_students' => RegistrarImport::count() ?: User::where('role', 'student')->count(),
                'actual_ballots_cast' => User::where('has_voted', true)->count(),
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
                'phase' => $phase?->name ?? 'registration',
                'registration_opens_at' => $settings['voting_opens_at'],
                'voting_closes_at' => $settings['voting_closes_at'],
                'positions' => $positions,
            ],
        ]);
    }

    public function updateConfig(\App\Http\Requests\ElectionConfigRequest $request): JsonResponse
    {
        $validated = $request->validated();

        if (isset($validated['phase'])) {
            Phase::setCurrent($validated['phase']);
        }

        $this->putSetting('title', $validated['title'] ?? null);
        $this->putSetting(
            'voting_opens_at',
            $validated['registration_opens_at'] ?? $validated['voting_opens_at'] ?? null,
        );
        $this->putSetting('voting_closes_at', $validated['voting_closes_at'] ?? null);

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