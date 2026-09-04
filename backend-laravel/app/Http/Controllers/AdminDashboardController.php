<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Admin dashboard + results endpoints for the React SPA.
 */
class AdminDashboardController extends Controller
{
    public function overview(): JsonResponse
    {
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
            'election_phase' => \App\Models\Phase::current()?->name ?? 'registration',
            'announcements' => [],
            'recent_actions' => [],
            'user' => [
                'name' => auth()->user()->name,
                'role' => auth()->user()->role === 'admin' ? 'System Administrator' : 'Teacher',
            ],
        ]);
    }

    public function results(): JsonResponse
    {
        // Reuse the exact same projection as the public results endpoint.
        return app(ResultsController::class)->index();
    }
}