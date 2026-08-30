<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AdminAuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        // rate limit by IP for admin logins
        $key = 'admin-login:'.$request->ip();
        if (\Illuminate\Support\Facades\RateLimiter::tooManyAttempts($key, 5)) {
            $retry = \Illuminate\Support\Facades\RateLimiter::availableIn($key);
            return response()->json(['message' => 'Too many attempts. Try again later.'], 429)->header('Retry-After', $retry);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            \Illuminate\Support\Facades\RateLimiter::hit($key, 900);
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if (! in_array($user->role, ['admin', 'teacher'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        Auth::login($user);
        $request->session()->regenerate();

        \Illuminate\Support\Facades\RateLimiter::clear($key);
        $token = $user->createToken('admin-session')->plainTextToken;

        return response()->json([
            'user' => $user,
            'token' => $token,
        ]);
    }

    public function me(Request $request)
    {
        return response()->json(['user' => Auth::user()]);
    }

    public function logout(Request $request)
    {
        $user = Auth::user();

        if ($user) {
            $user->tokens()->where('name', 'admin-session')->delete();
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
