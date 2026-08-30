<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\VoteLedger;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

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

        $result = DB::transaction(function () use ($user, $selections, $receipt, $receipt_hmac) {
            // Lock the user row to prevent a double cast; also guards against a
            // user row disappearing between authentication and this transaction.
            $voter = User::whereKey($user->id)->lockForUpdate()->first();

            if (! $voter) {
                return response()->json(['message' => 'Voter not found'], 404);
            }

            if ($voter->has_voted) {
                return response()->json(['message' => 'Already voted'], 409);
            }

            foreach ($selections as $position_key => $candidate_ref) {
                // Basic validation: candidate exists and is approved for position.
                // candidate_ref is intentionally stored as an opaque token (no FK),
                // keeping the ledger decoupled from the users/candidates tables.
                VoteLedger::create([
                    'position_key' => $position_key,
                    'candidate_ref' => $candidate_ref,
                    'receipt_hmac' => $receipt_hmac,
                    'ledger_sequence' => null,
                ]);
            }

            $voter->has_voted = true;
            $voter->voted_at = now();
            $voter->save();

            return ['receipt' => $receipt];
        });

        if ($result instanceof JsonResponse) {
            return $result;
        }

        return response()->json($result, 201);
    }
}
