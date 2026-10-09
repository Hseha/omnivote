<?php

namespace App\Http\Controllers;

use App\Http\Requests\StudentRegistrationRequest;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\AppSettings;
use App\Support\Notifier;
use App\Support\RegistrarCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Student self-registration.
 *
 * SECURITY (assessment M-5): this endpoint used to accept a `student_id` that
 * merely appeared in the registrar eligibility feed, plus any unused email, and
 * then provision a usable account and hand back a bearer token. Anyone who knew
 * a classmate's ID could therefore claim that identity with their own email and
 * vote as them.
 *
 * Two independent gates now apply:
 *
 *   1. A registrar-issued activation code, released out of band. Knowing the
 *      student ID — which is printed on class lists and ID cards — is no longer
 *      sufficient to claim the account.
 *   2. A Settings → Security toggle (`selfRegistrationEnabled`), OFF by
 *      default. Deployed schools that provision every account through the CSV
 *      import can leave the endpoint switched off entirely; the registrar flow
 *      remains the supported path.
 *
 * The code is burned on success, so it cannot be replayed to claim the identity
 * a second time.
 */
class RegistrationController extends Controller
{
    /** Per-IP budget for the whole endpoint, successful or not. */
    private const REGISTER_IP_MAX_ATTEMPTS = 10;

    /** How long that budget is held. */
    private const REGISTER_IP_DECAY_SECONDS = 3600;

    public function register(StudentRegistrationRequest $request): JsonResponse
    {
        // Gate 2: self-registration must be explicitly enabled. Default-off means
        // an install that never asks for it is not exposed at all.
        if (! (bool) AppSettings::security('selfRegistrationEnabled', false)) {
            return response()->json([
                'message' => 'Self-registration is disabled. Please ask the registrar to issue your account.',
            ], 403);
        }

        // Self-signup is gated to the registration phase (route middleware);
        // throttle by IP so a single device cannot flood the registry with
        // synthetic accounts (limit: 10 registrations / hour per IP).
        $key = 'register-ip:'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, self::REGISTER_IP_MAX_ATTEMPTS)) {
            $retry = RateLimiter::availableIn($key);

            return response()->json(['message' => 'Too many registrations. Try again later.'], 429)
                ->header('Retry-After', (string) $retry);
        }

        $validated = $request->validated();

        // Gate 1: the registrar-issued activation code must match the row.
        $import = RegistrarImport::where('student_id', $validated['student_id'])->first();

        $generic = response()->json([
            'message' => 'Registration could not be completed. Check your activation code, or ask the registrar to provision your account.',
        ], 422);

        if (! $import || ! RegistrarCode::matches($import, $validated['activation_code'] ?? null)) {
            // A FAILED attempt must count against the same budget as a
            // successful one. The activation code is the only thing separating
            // "knows a classmate's student ID" from "claims their identity", and
            // the code space is finite (32^8). If only successes were counted, an
            // attacker could guess all day for free and the limit quoted in the
            // comment above would be fictional. Note that the check happens after
            // `tooManyAttempts`, so the 429 arrives before the comparison and no
            // oracle is exposed by the response shape.
            RateLimiter::hit($key, self::REGISTER_IP_DECAY_SECONDS);

            return $generic;
        }

        $user = DB::transaction(function () use ($validated, $import) {
            $user = User::create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'student_id' => $validated['student_id'],
                'password' => Hash::make($validated['password']),
                'role' => 'student',
            ]);

            // Single-use: burn the code so the same slip cannot be replayed.
            RegistrarCode::redeem($import);

            return $user;
        });

        RateLimiter::hit($key, self::REGISTER_IP_DECAY_SECONDS);

        // Welcome the new account: the app opens straight into the dashboard
        // with a bell entry waiting, which doubles as an onboarding hint.
        Notifier::notifyUser(
            $user->id,
            'success',
            'Welcome to OmniVote',
            'Your account is ready. Election news and reminders will appear here.',
            '/dashboard',
        );

        Notifier::toAdmins(
            'notifyOnRegistration',
            'info',
            'New student registered',
            "{$validated['name']} (student {$validated['student_id']}, {$validated['email']}) signed up.",
            '/users',
        );

        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json(['token' => $token, 'student' => $user], 201);
    }
}
