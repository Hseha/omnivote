<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class StudentAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // rate limit by IP
        $key = 'login:'.$request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            $retry = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429)->header('Retry-After', $retry);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 900);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->role !== 'student') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'student' => $user,
        ]);
    }

    public function logout(Request $request)
    {
        $token = $request->user()->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $request->user()]);
    }
}
