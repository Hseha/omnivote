<?php

namespace App\Support;

use App\Models\ElectionCandidateRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Log;

/**
 * One-time snapshot of every candidate in the current cycle, taken just
 * before a clean-slate reset removes the non-winner live rows. Winners stay
 * in the `candidates` table (Past Terms); this record keeps the *full*
 * roster — every party, winner and non-winner alike — reviewable forever.
 *
 * Idempotent per `cycle_id`: re-running a reset never duplicates history.
 */
class ElectionRecordArchive
{
    /**
     * Snapshot every current candidate into `election_candidate_records`.
     *
     * @return int number of records written (0 when schema/columns are missing
     *         or this cycle was already snapshotted)
     */
    public static function snapshot(string $termLabel, string $cycleId): int
    {
        try {
            if (! Schema::hasTable('candidates')
                || ! Schema::hasTable('election_candidate_records')) {
                return 0;
            }

            // Idempotent: a cycle is snapshotted once.
            if (ElectionCandidateRecord::query()
                ->where('cycle_id', $cycleId)
                ->exists()) {
                return 0;
            }

            $rows = \App\Models\Candidate::query()
                ->with(['user:id,name,email,student_id,department,year_level',
                        'position:id,slug,label,tier,sort_order'])
                ->get();

            $stamp = now()->toDateTimeString();

            // The opaque reference the ballot uses. Frozen onto the record so
            // the final tally can be folded back in when the administrator
            // declares the winners. Guarded: an install that predates the
            // column still archives the roster.
            $hasCandidateRef = Schema::hasColumn('election_candidate_records', 'candidate_ref');

            foreach ($rows as $candidate) {
                $user = $candidate->user;
                $position = $candidate->position;

                $attributes = [
                    'term_label' => $termLabel,
                    'cycle_id' => $cycleId,
                    'user_name' => $user?->name ?? null,
                    'user_email' => $user?->email ?? null,
                    'student_id' => $user?->student_id ?? null,
                    'department' => $user?->department ?? null,
                    'year_level' => $user?->year_level ?? null,
                    'position_slug' => $position?->slug ?? null,
                    'position_label' => $position?->label ?? null,
                    'position_tier' => $position?->tier ?? null,
                    'position_sort_order' => $position?->sort_order ?? null,
                    'party_name' => $candidate->party_name ?? null,
                    'approval_status' => $candidate->approval_status ?? null,
                    'election_status' => $candidate->election_status ?? null,
                    'vote_total' => (int) ($candidate->vote_total ?? 0),
                    'winner_rank' => $candidate->winner_rank ?? null,
                    'certified_winner' => (bool) ($candidate->certified_winner ?? false),
                    'slogan' => $candidate->slogan ?? null,
                    'snapshot_at' => $stamp,
                ];

                if ($hasCandidateRef) {
                    $attributes['candidate_ref'] = $candidate->candidate_ref ?? null;
                }

                ElectionCandidateRecord::create($attributes);
            }

            return $rows->count();
        } catch (\Throwable $e) {
            Log::warning('ElectionRecordArchive::snapshot skipped: '.$e->getMessage());

            return 0;
        }
    }
}
