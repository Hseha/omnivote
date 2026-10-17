<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Candidate;
use App\Models\Phase;
use App\Models\Position;
use App\Models\User;
use App\Models\VoteLedger;
use App\Support\CandidateRoleSync;
use App\Support\ElectionTally;
use App\Support\Notifier;
use App\Support\ResultsPublisher;
use App\Support\TermArchive;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Public results + anonymous receipt verification.
 *
 *   GET  /api/results             — per-position tallies (voting_closed only)
 *   POST /api/results/verify      — { receipt_token } → { counted: bool }
 *   POST /api/admin/results/finalize — auto-determine winners for every seat,
 *        certify them, and publish a results announcement.
 */
class ResultsController extends Controller
{
    /** Fixed title of the auto-generated results announcement so recounts
     *  update it in place instead of piling up duplicates. */
    public const RESULTS_ANNOUNCEMENT_TITLE = 'Official Election Results';

    public function index(): JsonResponse
    {
        // Archived winners have served their term — their rows leave the live
        // results entirely (they live on in the Past Terms archive), so tally
        // rows still keyed to them are dropped rather than shown as anonymous.
        $archivedRefs = Candidate::query()
            ->whereNotNull('archived_at')
            ->pluck('candidate_ref');

        $ledger = DB::table('vote_ledger')
            ->select('position_key', 'candidate_ref', DB::raw('COUNT(*) as votes'))
            ->groupBy('position_key', 'candidate_ref')
            ->orderBy('position_key')
            ->orderByDesc('votes')
            ->get();

        $candidates = Candidate::query()
            ->whereIn('candidate_ref', $ledger->pluck('candidate_ref'))
            // The own account is needed to resolve each candidate's scope group
            // (its college and year level), and `->user->name` is read per row.
            ->with('user:id,name,year_level,department,course')
            ->get()
            ->keyBy('candidate_ref');

        $positions = Position::query()
            ->whereIn('slug', $ledger->pluck('position_key')->unique()->values())
            ->get()
            ->keyBy('slug');

        // One result group per contested seat. A global position yields a single
        // group; a scoped position yields one per electorate (e.g. every college
        // per year level for the Year Level Representative).
        //
        // The groups are pushed (never collapsed into a keyed map): two different
        // positions can share a group key (every global seat groups under ''), and
        // keyed merging would silently drop all but the last. A position is kept
        // even when every one of its candidates has been archived, so the public
        // feed shows an empty seat rather than vanishing mid-term.
        $results = collect();

        $ledger
            ->groupBy('position_key')
            ->each(function ($rows, $positionKey) use ($results, $candidates, $positions, $archivedRefs) {
                $position = $positions->get($positionKey);
                $baseLabel = $position?->label ?? str_replace('_', ' ', ucfirst($positionKey));

                $rows
                    ->groupBy(fn ($row) => $position?->groupKeyFor($candidates->get($row->candidate_ref)?->user) ?? '')
                    ->each(function ($groupRows, $groupKey) use ($results, $positionKey, $position, $baseLabel, $candidates, $archivedRefs) {
                        $owner = $candidates->get($groupRows->first()->candidate_ref)?->user;
                        $scopeLabel = $position?->scopeLabelFor($owner);

                        $results->push([
                            'position_key' => $positionKey,
                            'position_label' => $scopeLabel ? "{$baseLabel} — {$scopeLabel}" : $baseLabel,
                            'scope_label' => $scopeLabel,
                            'candidates' => $groupRows
                                ->reject(fn ($row) => $archivedRefs->contains($row->candidate_ref))
                                ->sortByDesc(fn ($row) => (int) $row->votes)
                                ->values()
                                ->map(function ($row) use ($candidates, $positionKey) {
                                    // A ledger row can outlive its candidate
                                    // (withdrawn, deleted) and a position_key can
                                    // outlive its Position row. Look both up
                                    // safely and fall back to the opaque ref.
                                    $candidate = $candidates->get($row->candidate_ref);

                                    return [
                                        'id' => $candidate?->id,
                                        'name' => $candidate?->user?->name ?? $row->candidate_ref,
                                        'position_key' => $positionKey,
                                        'votes' => (int) $row->votes,
                                        'candidate_ref' => $row->candidate_ref,
                                        'election_status' => $candidate?->election_status ?? 'pending',
                                        'winner_rank' => $candidate?->winner_rank,
                                        'certified_winner' => (bool) ($candidate?->certified_winner ?? false),
                                    ];
                                }),
                        ]);
                    });
            });

        return response()->json(['results' => $results->values()]);

        return response()->json(['results' => $results]);
    }

    /**
     * POST /api/admin/results/finalize — auto-determine winners for every
     * seat using the sealed ledger. Exactly one elected slot is produced per
     * seat; equal-vote groups that cross the seat boundary are all flagged
     * `tied` for an explicit admin tie-break. Elected candidates are
     * certified automatically and a results announcement is (re)published.
     *
     * Idempotent: a recount re-runs the same tally from the ledger and
     * overwrites the snapshot, so re-finalizing is always safe.
     */
    public function finalize(Request $request): JsonResponse
    {
        if (Phase::current()?->name !== 'voting_closed') {
            return response()->json([
                'message' => 'Results can only be finalized after voting has closed.',
            ], 409);
        }

        $tallies = DB::table('vote_ledger')
            ->select('position_key', 'candidate_ref', DB::raw('COUNT(*) as votes'))
            ->groupBy('position_key', 'candidate_ref')
            ->get()
            ->groupBy('position_key');

        $slugs = $tallies->keys();
        $positions = Position::query()
            ->whereIn('slug', $slugs)
            ->get()
            ->keyBy('slug');

        $candidates = Candidate::query()
            ->whereIn('candidate_ref', $tallies->flatten(1)->pluck('candidate_ref'))
            ->where('approval_status', 'approved')
            ->with(['position:id,slug,label', 'user:id,year_level,department,course'])
            ->get()
            ->keyBy('candidate_ref');

        // Reset every candidate to pending first so a recount never leaves
        // stale winners behind. Certifications survive the reset — they are
        // re-affirmed below for whoever the recount actually elects.
        Candidate::query()
            ->whereIn('position_id', $positions->pluck('id'))
            ->update([
                'election_status' => 'pending',
                'winner_rank' => null,
                'vote_total' => 0,
            ]);

        $summary = ['elected' => 0, 'tied' => 0];

        foreach ($tallies as $slug => $rows) {
            $position = $positions->get($slug);
            if (! $position) {
                continue;
            }

            $seatCount = max(1, (int) $position->seat_count);

            // One contest per electorate. A global seat is a single group; a
            // scoped seat (department, year level, or both) yields one group per
            // electorate, and each group elects its own `seat_count` winners.
            $groups = $rows
                ->filter(fn ($row) => $candidates->has($row->candidate_ref))
                ->groupBy(fn ($row) => $position->groupKeyFor($candidates->get($row->candidate_ref)?->user));

            foreach ($groups as $groupRows) {
                $rowsForSeat = $groupRows
                    ->map(fn ($row) => [
                        'candidate_ref' => $row->candidate_ref,
                        'votes' => (int) $row->votes,
                    ])
                    ->values()
                    ->all();

                $verdict = ElectionTally::resolve($rowsForSeat, $seatCount);

                // Apply verdict to candidate rows (ordered by votes so ranks are
                // awarded to the actual leaders of THIS electorate).
                $byVotes = collect($rowsForSeat)
                    ->sortByDesc('votes')
                    ->pluck('candidate_ref');

                foreach ($byVotes as $ref) {
                    if (array_key_exists($ref, $verdict['elected']) && $candidates->has($ref)) {
                        $candidates->get($ref)->forceFill([
                            'election_status' => 'elected',
                            'winner_rank' => $verdict['elected'][$ref],
                            'vote_total' => $this->votesForRef($rowsForSeat, $ref),
                        ])->save();
                        $summary['elected']++;
                    }
                }

                foreach ($verdict['tied'] as $ref) {
                    if (! $candidates->has($ref)) {
                        continue;
                    }
                    $candidates->get($ref)->forceFill([
                        'election_status' => 'tied',
                        'vote_total' => $this->votesForRef($rowsForSeat, $ref),
                    ])->save();
                    $summary['tied']++;
                }
            }
        }

        // Elected candidates are auto-certified so the single-seat SSG
        // President grant works without any further manual step.
        Candidate::query()
            ->where('election_status', 'elected')
            ->where('certified_winner', false)
            ->update([
                'certified_winner' => true,
                'certified_at' => now(),
            ]);

        // Publication: raise the results_published flag (unblocking the SSG
        // grant) and automatically hand the single seat to the elected winner
        // of the configured Presidency position.
        ResultsPublisher::publish($request->user());

        // Keep the derived candidate role in step with the decided race.
        // Re-deriving per account (rather than a blanket demote) keeps a
        // recount correct: elected/tie-break candidates keep the role, everyone
        // the tally decided against reverts to student.
        CandidateRoleSync::syncMany(
            Candidate::query()
                ->whereIn('position_id', $positions->pluck('id'))
                ->pluck('user_id')
        );

        $announcement = $this->refreshResultsAnnouncement($request->user());

        Notifier::toAdmins(
            'emailOnResult',
            'success',
            'Election results published',
            $summary['elected'].' candidate(s) elected, '.$summary['tied'].' tied. Winners are certified.',
            '/results',
        );

        // Fan the result out to the student body and to each candidate whose
        // race was tallied (only refs present in the ledger are loaded, so a
        // candidate with no votes is not messaged).
        Notifier::notifyStudents(
            'success',
            'Election results published',
            'The official results are in. Tap to view them.',
            '/results',
        );

        foreach ($candidates as $candidate) {
            if (! $candidate->user_id) {
                continue;
            }

            $position = $candidate->position?->label ?? 'your position';

            match ($candidate->election_status) {
                'elected' => Notifier::notifyUser(
                    $candidate->user_id,
                    'success',
                    'You won!',
                    "Congratulations — you were elected for {$position}.",
                    '/results',
                ),
                'tied' => Notifier::notifyUser(
                    $candidate->user_id,
                    'warning',
                    'Your race is tied',
                    "Your {$position} race ended in a tie and is awaiting a decision.",
                    '/results',
                ),
                default => Notifier::notifyUser(
                    $candidate->user_id,
                    'info',
                    'Election results published',
                    "The {$position} race has been decided. Tap to see the results.",
                    '/results',
                ),
            };
        }

        return response()->json([
            'message' => 'Results finalized. Winners are certified.',
            'summary' => $summary,
            'announcement' => $announcement ? [
                'id' => $announcement->id,
                'title' => $announcement->title,
                'published_at' => $announcement->published_at?->toIso8601String(),
            ] : null,
        ]);
    }

    /**
     * Rebuild (create or update in place) the auto-generated results
     * announcement from the current certified/tied candidates state. Called
     * after finalize and after every tie-break so the student app always sees
     * the live decision.
     */
    public function refreshResultsAnnouncement(?User $user = null)
    {
        $winners = Candidate::query()
            ->where('election_status', 'elected')
            ->whereNull('archived_at')
            ->with(['user:id,name,year_level,department,course', 'position'])
            ->orderBy('position_id')
            ->get();

        $tied = Candidate::query()
            ->where('election_status', 'tied')
            ->whereNull('archived_at')
            ->with(['user:id,name,year_level,department,course', 'position'])
            ->orderBy('position_id')
            ->get();

        if ($winners->isEmpty() && $tied->isEmpty()) {
            return null;
        }

        $lines = collect([]);
        $positions = collect([]);

        foreach ($winners as $winner) {
            $positions->push([
                'label' => $this->positionAnnouncementLabel($winner),
                'name' => $winner->user?->name ?: $winner->candidate_ref,
                'rank' => $winner->winner_rank,
            ]);
        }

        foreach ($tied as $candidate) {
            $positions->push([
                'label' => $this->positionAnnouncementLabel($candidate),
                'name' => $candidate->user?->name ?: $candidate->candidate_ref,
                'tie' => true,
            ]);
        }

        foreach ($positions->groupBy('label') as $label => $entry) {
            $winnersText = $entry->filter(fn ($p) => empty($p['tie']))
                ->sortBy('rank')
                ->pluck('name')
                ->implode(', ');
            $tieText = $entry->filter(fn ($p) => ! empty($p['tie']))
                ->pluck('name')
                ->implode(', ');

            $parts = [];
            if ($winnersText) {
                $parts[] = $winnersText;
            }
            if ($tieText) {
                $parts[] = "TIE for a seat: {$tieText} (awaiting decision)";
            }
            if ($parts) {
                $lines->push("• {$label}: ".implode(' — ', $parts));
            }
        }

        if ($lines->isEmpty()) {
            return null;
        }

        $body = "The election results are in.\n\n".$lines->implode("\n");

        $announcement = Announcement::where('title', self::RESULTS_ANNOUNCEMENT_TITLE)->first();

        if (! $announcement) {
            $announcement = Announcement::create([
                'user_id' => $user?->id ?? 1,
                'title' => self::RESULTS_ANNOUNCEMENT_TITLE,
                'body' => $body,
                'published_at' => now(),
            ]);
        } else {
            $announcement->update([
                'body' => $body,
                'published_at' => $announcement->published_at ?? now(),
            ]);
        }

        return $announcement;
    }

    /**
     * Position label for the results announcement. Scoped seats are suffixed
     * with the electorate so twenty Year Level Representative winners read as
     * twenty distinct races instead of one line of names.
     */
    private function positionAnnouncementLabel(Candidate $candidate): string
    {
        $base = $candidate->position?->label ?: $candidate->position?->slug ?: 'Position';
        $scope = $candidate->position?->scopeLabelFor($candidate->user);

        return $scope ? "{$base} ({$scope})" : $base;
    }

    private function votesForRef(array $rows, string $ref): int
    {
        foreach ($rows as $row) {
            if ($row['candidate_ref'] === $ref) {
                return (int) $row['votes'];
            }
        }

        return 0;
    }

    public function verify(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receipt_token' => ['required', 'string', 'max:128'],
        ]);

        $hmac = hash_hmac('sha256', $validated['receipt_token'], (string) config('app.key'));
        $counted = VoteLedger::where('receipt_hmac', $hmac)->exists();

        return response()->json(['counted' => $counted]);
    }

    /**
     * GET /api/admin/results/archive — archived winners grouped by the term
     * (school year) they served. Rows carry the same projection as the live
     * results so the admin can look up who won any past year.
     */
    public function archivedWinners(): JsonResponse
    {
        $winners = Candidate::query()
            ->where('certified_winner', true)
            ->whereNotNull('archived_at')
            ->with(['user:id,name,email,student_id', 'position:id,slug,label,tier'])
            ->orderByDesc('archived_at')
            ->get();

        $terms = $winners->groupBy('term_label')->map(function ($rows, $label) {
            return [
                'term_label' => $label ?: 'Unknown Term',
                'archived_at' => $rows->first()?->archived_at?->toIso8601String(),
                'winners' => $rows->map(fn (Candidate $c) => [
                    'id' => $c->id,
                    'name' => $c->user?->name ?? $c->candidate_ref,
                    'email' => $c->user?->email,
                    'student_id' => $c->user?->student_id,
                    'position' => $c->position?->label ?: $c->position?->slug,
                    'tier' => $c->position?->tier,
                    'party' => $c->party_name,
                    'election_status' => $c->election_status,
                    'winner_rank' => $c->winner_rank,
                    'vote_total' => (int) $c->vote_total,
                    'archived_at' => $c->archived_at?->toIso8601String(),
                ])->values(),
            ];
        })->values();

        return response()->json([
            'terms' => $terms,
            'term_ends_at' => TermArchive::termEndsAt()?->toIso8601String(),
            'term_due' => TermArchive::due(),
        ]);
    }

    /**
     * POST /api/admin/results/archive-term — end the current term now,
     * archiving every certified winner under the derived school-year label so
     * they move out of the live results/roster into the Past Terms archive.
     */
    public function archiveTerm(Request $request): JsonResponse
    {
        // Capture the certified winners before they are archived: once their
        // candidacy carries an `archived_at` it is no longer live, so their
        // derived candidate role reverts to student.
        $archivedUserIds = Candidate::query()
            ->where('certified_winner', true)
            ->when(Schema::hasColumn('candidates', 'archived_at'), fn ($q) => $q->whereNull('archived_at'))
            ->pluck('user_id');

        $count = TermArchive::archiveNow();

        CandidateRoleSync::syncMany($archivedUserIds);

        return response()->json([
            'message' => $count > 0
                ? "{$count} winner(s) archived for ".TermArchive::labelFor().'.'
                : 'No certified winners to archive (they may already be archived).',
            'archived' => $count,
            'term_label' => TermArchive::labelFor(),
            'term_ends_at' => TermArchive::termEndsAt()?->toIso8601String(),
        ]);
    }
}
