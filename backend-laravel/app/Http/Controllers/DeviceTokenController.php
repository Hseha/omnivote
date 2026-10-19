<?php

namespace App\Http\Controllers;

use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * FCM device-token registration for the Flutter client.
 *
 *   POST   /api/device-tokens — register/refresh the install's token
 *   DELETE /api/device-tokens — drop it (called on logout)
 *
 * The token is keyed by value rather than by user: a device that logs into a
 * different account reassigns its row, so a signed-out phone can never keep
 * receiving the previous account's pushes. Scoped to the caller — an account
 * can only touch its own tokens.
 */
class DeviceTokenController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
            'platform' => ['sometimes', 'string', 'in:android,ios,web'],
        ]);

        DeviceToken::updateOrCreate(
            ['token' => $validated['token']],
            [
                'user_id' => $request->user()->id,
                'platform' => $validated['platform'] ?? 'android',
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['status' => 'registered']);
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'max:512'],
        ]);

        DeviceToken::query()
            ->where('token', $validated['token'])
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['status' => 'removed']);
    }
}
