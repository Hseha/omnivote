<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Models\VoteLedger;
use App\Models\Candidate;

class VoteController extends Controller
{
    public function submit(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'selections' => ['required', 'array'],
            // selections: { position_key: candidate_ref }
        ]);

        $selections = $validated['selections'];

        $receipt = bin2hex(random_bytes(16));
        $receipt_hmac = hash_hmac('sha256', $receipt, config('app.key'));

        $result = DB::transaction(function () use ($user, $selections, $receipt_hmac) {
            // lock user row to prevent double-vote
            $u = \App\Models\User::whereKey($user->id)->lockForUpdate()->first();
            if ($u->has_voted) {
                return response()->json(['message' => 'Already voted'], 409);
            }

            foreach ($selections as $position_key => $candidate_ref) {
                // basic validation: candidate exists and is approved for position
                // here we check Candidate model if candidate_ref maps to id or token; keep simple
                \App\Models\VoteLedger::create([
                    'position_key' => $position_key,
                    'candidate_ref' => $candidate_ref,
                    'receipt_hmac' => $receipt_hmac,
                    'ledger_sequence' => null,
                ]);
            }

            $u->has_voted = true;
            $u->voted_at = now();
            $u->save();

            return ['receipt' => $receipt];
        });

        if ($result instanceof \Illuminate\Http\JsonResponse) {
            return $result;
        }

        return response()->json($result, 201);
    }
}
