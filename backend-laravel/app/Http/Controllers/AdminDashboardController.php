<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\Candidate;
use App\Models\Phase;
use App\Models\User;
use App\Models\UserNotification;
use App\Support\TermArchive;
use Illuminate\Http\JsonResponse;

/**
 * Admin dashboard + results endpoints for the React SPA.
 */
class AdminDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
        // Auto-archive the previous term the moment its Term Ends date passes,
        // so the winner "validity" window closes without a manual step.
        TermArchive::runIfDue();

        $totalVoters = User::where('role', 'student')->count();
        $votesCast = User::where('has_voted', true)->count();
        $approvedCandidates = Candidate::where('approval_status', 'approved')->count();

        return response()->json([
            'stats' => [
                'total_voters' => $totalVoters,
                'votes_cast' => $votesCast,
                'turnout_rate' => $totalVoters > 0 ? round(($votesCast / $totalVoters) * 100, 1) : 0,
                'approved_candidates' => $approvedCandidates,
            ],
            'election_phase' => Phase::current()?->name,
            'announcements' => Announcement::query()
                ->published()
                ->with('author:id,name,avatar_url')
                ->latest('published_at')
                ->limit(5)
                ->get()
                ->map(fn (Announcement $a) => [
                    'id' => $a->id,
                    'title' => $a->title,
                    'body' => $a->body,
                    'published_at' => $a->published_at?->toIso8601String(),
                    'author' => $a->author ? [
                        'name' => $a->author->name,
                        'avatar_url' => $a->author->avatar_url,
                    ] : null,
                ]),
            'recent_actions' => UserNotification::query()
                ->for(auth()->id())
                ->limit(8)
                ->get()
                ->map(fn (UserNotification $n) => [
                    'type' => $n->type,
                    'title' => $n->title,
                    'body' => $n->body,
                    'link' => $n->link,
                    'created_at' => $n->created_at?->toIso8601String(),
                    'read' => $n->isRead(),
                ]),
            'user' => [
                'name' => auth()->user()->name,
                'role' => match (auth()->user()->role) {
                    'admin' => 'System Administrator',
                    'ssg_president' => 'SSG President',
                    default => 'Teacher',
                },
            ],
        ]);
    }

    public function results(): JsonResponse
    {
        TermArchive::runIfDue();

        // Reuse the exact same projection as the public results endpoint.
        return app(ResultsController::class)->index();
    }
}
