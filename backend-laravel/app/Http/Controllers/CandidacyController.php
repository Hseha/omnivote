<?php

namespace App\Http\Controllers;

use App\Models\Candidate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Student-facing candidacy-status endpoint (Flutter dropdown status on the
 * Apply for Candidacy flow): `none | pending | approved | rejected`.
 */
class CandidacyController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        $candidate = Candidate::where('user_id', $request->user()->id)->first();

        if (! $candidate) {
            return response()->json(['status' => 'none']);
        }

        return response()->json([
            'status' => $candidate->approval_status,
            'candidate' => [
                'id' => $candidate->id,
                'position_id' => $candidate->position_id,
                'slogan' => $candidate->slogan,
                'party_name' => $candidate->party_name,
                'platform_statement' => $candidate->platform_statement,
                'photo_path' => $candidate->photo_path,
                'approval_status' => $candidate->approval_status,
                'created_at' => $candidate->created_at,
            ],
        ]);
    }
}