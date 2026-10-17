<?php

namespace App\Support;

use App\Models\Candidate;
use App\Models\Position;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Publishes the election results and seats the SSG President.
 *
 * "Publishing" is the moment the outcome becomes official: the
 * `results_published` flag that gates the SSG role is raised, and the elected
 * winner of the configured Presidency seat is handed the single-seat role.
 *
 * Idempotent for recounts: re-running with the same winner leaves the incumbent
 * and their setup invite untouched; a recount that elects someone else seats the
 * new winner and demotes the previous holder (SsgPresidentGrant::grant).
 * A tied, absent, or unconfigured seat is left for a manual admin grant.
 */
class ResultsPublisher
{
    /**
     * @return array{granted: ?User, reason: ?string, previous: Collection<int, User>}
     */
    public static function publish(?User $actor = null): array
    {
        // Raise the flag first so the seat becomes awardable regardless of
        // whether a winner can be resolved automatically.
        if (Schema::hasTable('election_settings')) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => 'results_published'],
                ['value' => '1', 'created_at' => now(), 'updated_at' => now()],
            );
        }

        $slug = (string) config('election.ssg_president_position_slug', 'president');

        $position = Position::query()->where('slug', $slug)->first();

        if (! $position) {
            return ['granted' => null, 'reason' => 'position_missing', 'previous' => collect()];
        }

        $query = Candidate::query()
            ->where('position_id', $position->id)
            ->where('certified_winner', true)
            ->where('election_status', 'elected')
            ->with('user');

        if (Schema::hasColumn('candidates', 'archived_at')) {
            $query->whereNull('archived_at');
        }

        $winners = $query->get()->filter(fn (Candidate $c) => $c->user !== null);

        if ($winners->count() !== 1) {
            return [
                'granted' => null,
                'reason' => $winners->isEmpty() ? 'no_winner' : 'ambiguous_winner',
                'previous' => collect(),
            ];
        }

        $winner = $winners->first()->user;

        // Recount idempotency: the incumbent already holds the seat, so leave
        // them (and their outstanding setup invite) exactly as they are.
        if ($winner->role === 'ssg_president') {
            return ['granted' => $winner, 'reason' => 'already_seated', 'previous' => collect()];
        }

        $previous = User::query()
            ->where('role', 'ssg_president')
            ->where('id', '!=', $winner->id)
            ->get();

        SsgPresidentGrant::grant($winner, $actor);

        return ['granted' => $winner, 'reason' => null, 'previous' => $previous];
    }
}
