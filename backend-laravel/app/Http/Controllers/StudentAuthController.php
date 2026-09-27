<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\HandlesLoginThrottling;
use App\Models\RegistrarImport;
use App\Models\User;
use App\Support\Notifier;
use App\Support\RegistrarCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class StudentAuthController extends Controller
{
    use HandlesLoginThrottling;

    protected function loginLimiterPrefix(): string
    {
        return 'login';
    }

    public function login(Request $request)
    {
        $request->validate([
            // Registrar-imported students sign in with a plain name-derived
            // handle (e.g. john.michael.valles), so this is deliberately not
            // validated as a strict email address.
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required'],
        ]);

        // Layered throttling: per-account first (brute-force), then per-IP
        // (credential stuffing). One account's failures never block others
        // on the same network.
        if ($throttled = $this->loginThrottled($request)) {
            return $throttled;
        }

        $user = User::where('email', $request->email)->first();

        // Account backoff. The response is deliberately indistinguishable from a
        // wrong password (401 "Invalid credentials" + Retry-After) so this path
        // cannot be used to confirm that a handle exists — see
        // HandlesLoginThrottling::backoffResponse() (assessment M-3).
        if ($user && $user->isLocked()) {
            return $this->backoffResponse($user->backoffSecondsRemaining());
        }

        if (! $user || ! Hash::check($request->password, $user->password)) {
            if ($user) {
                $user->recordFailedLogin();
            }
            $this->recordLoginFailure($request);

            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        if ($user->role !== 'student') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Administrators can disable an account (AdminUserController::updateStatus).
        // Correct credentials for a disabled account must not mint a mobile token.
        if (! $user->is_active) {
            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        $user->clearLockout();
        $this->clearLoginThrottle($request);
        $token = $user->createToken('mobile')->plainTextToken;

        return response()->json([
            'token' => $token,
            'student' => $user,
            // Registrar-provisioned accounts start with a random temporary
            // password and must replace it before using the app.
            'must_change_password' => (bool) $user->must_change_password,
        ]);
    }

    /**
     * POST /api/auth/password/change
     *
     * Sets a permanent password for the signed-in student (used to clear the
     * registrar-issued temporary credential). Every other token is revoked so
     * a password change logs other devices out.
     */
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            return response()->json(['message' => 'Your current password is incorrect.'], 422);
        }

        $user->forceFill([
            'password' => $request->password,
            'must_change_password' => false,
        ])->save();

        $current = $user->currentAccessToken();
        $user->tokens()
            ->when($current, fn ($query) => $query->where('id', '!=', $current->id))
            ->delete();

        return response()->json(['message' => 'Password updated.']);
    }

    /**
     * POST /api/auth/password/reset-with-code
     *
     * Self-service account recovery for students (security assessment M-3).
     *
     * Why a code and not an emailed link: a student's login handle is a
     * name-derived slug (`john.michael.valles`), not a mailbox, so the Password
     * broker has nowhere to send a link. The registrar-issued activation code is
     * the only channel that can verify the student in person, and redeeming it
     * here lets a locked-out voter regain access WITHOUT an administrator
     * touching the account — which is what made the old lockout a denial-of-
     * service lever.
     *
     * The response is deliberately identical whether or not the student_id/code
     * pair matched, so this endpoint is not a registry enumeration oracle.
     */
    public function resetWithCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'student_id' => ['required', 'string', 'max:64'],
            'code' => ['required', 'string', 'max:16'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $generic = response()->json([
            'message' => 'If that student ID and code are valid, the password has been reset.',
        ]);

        $import = RegistrarImport::where('student_id', $validated['student_id'])->first();

        if (! $import || ! RegistrarCode::matches($import, $validated['code'])) {
            // Still record the attempt against the IP limiter so codes cannot be
            // brute-forced at 32^8 by spraying this endpoint.
            $this->recordLoginFailure($request);

            return $generic;
        }

        $user = User::where('student_id', $import->student_id)->where('role', 'student')->first();

        if (! $user || ! $user->is_active) {
            return $generic;
        }

        DB::transaction(function () use ($user, $validated, $import): void {
            $user->forceFill([
                'password' => $validated['password'],
                // The student chose their own password, so the registrar-issued
                // temporary credential no longer needs rotating.
                'must_change_password' => false,
            ])->save();

            // A password reset ends every existing session, and clears the
            // failed-login backoff so the account is immediately usable.
            $user->tokens()->delete();
            $user->clearLockout();

            RegistrarCode::redeem($import);
        });

        Notifier::emailUser(
            $user->id,
            'emailOnAdminAction',
            'Your OmniVote password was reset',
            "Your password was reset with a registrar activation code. If this was not you, contact the registrar immediately.",
            '/login',
        );

        return $generic;
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
        $user = $request->user();

        // An account disabled after sign-in must fail its next authenticated call
        // so the app can clear the stored token and return to the login screen.
        if ($user && ! $user->is_active) {
            return response()->json(['message' => 'Your account has been disabled.'], 403);
        }

        return response()->json(['user' => $user]);
    }
}
