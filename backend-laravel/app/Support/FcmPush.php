<?php

namespace App\Support;

use App\Models\DeviceToken;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Phone push delivery through Firebase Cloud Messaging (HTTP v1).
 *
 * Deliberately dependency-free: the service-account grant and the RS256 JWT
 * are ~20 lines against Google's two public endpoints, which avoids pinning a
 * Firebase SDK onto a stack where none exists. Everything here is best-effort
 * and silent by default, mirroring App\Support\Notifier: a missing Firebase
 * config, a disabled toggle or an FCM outage must never break the flow that
 * triggered the notification (voting, imports, admin actions).
 *
 * Delivery path (see app/Jobs/SendPushToUsers.php — sends run on the queue):
 *   Notifier  →  SendPushToUsers (queued)  →  FcmPush::sendToUsers()
 *
 * Gating: the admin "Push Enabled" setting AND a configured Firebase project
 * must both be on. With either missing this class no-ops and notifications
 * remain in-app only.
 */
class FcmPush
{
    private const OAUTH_URL = 'https://oauth2.googleapis.com/token';

    private const FCM_URL = 'https://fcm.googleapis.com/v1/projects/%s/messages:send';

    private const TOKEN_SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    /** Whether push delivery is configured and enabled by admin settings. */
    public static function enabled(): bool
    {
        if (config('services.firebase.project_id') === null || config('services.firebase.credentials') === null) {
            return false;
        }

        try {
            return AppSettings::notifications('pushEnabled', true) === true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Fan one notification out to every registered, active device of the
     * given users. Returns the number of devices reached (best-effort; a
     * failure on one token never skips the rest).
     *
     * @param  array<int, int>  $userIds
     */
    public static function sendToUsers(array $userIds, string $title, ?string $body = null, ?string $link = null): int
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if ($userIds === [] || ! self::enabled()) {
            return 0;
        }

        try {
            $tokens = DeviceToken::query()
                ->whereIn('user_id', $userIds)
                ->whereHas('user', fn ($q) => $q->where('is_active', true))
                ->get();
        } catch (\Throwable $e) {
            // Token lookup runs against schemas that may predate the
            // device_tokens migration (same guard style as Notifier).
            Log::warning('Push skipped (token lookup failed): '.$e->getMessage());

            return 0;
        }

        $sent = 0;
        foreach ($tokens as $device) {
            if (self::sendToToken($device, $title, $body, $link)) {
                $sent++;
            }
        }

        return $sent;
    }

    /** Deliver to one device; prune the row when FCM says it no longer exists. */
    private static function sendToToken(DeviceToken $device, string $title, ?string $body, ?string $link): bool
    {
        try {
            $response = Http::withToken(self::accessToken())
                ->timeout(10)
                ->post(sprintf(self::FCM_URL, config('services.firebase.project_id')), [
                    'message' => array_filter([
                        'token' => $device->token,
                        'notification' => array_filter([
                            'title' => $title,
                            'body' => $body,
                        ]),
                        // Deep link for tap routing: the same `link` shape the
                        // in-app feed uses (/vote-now, /results, ...).
                        'data' => $link !== null && $link !== '' ? ['link' => $link] : null,
                        'android' => ['priority' => 'high'],
                    ]),
                ]);

            if ($response->successful()) {
                return true;
            }

            if (self::tokenIsGone($response)) {
                $device->delete();

                return false;
            }

            Log::warning('FCM push rejected: HTTP '.$response->status().' '.$response->body());

            return false;
        } catch (\Throwable $e) {
            Log::warning('FCM push failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Whether an FCM error means "this registration token no longer exists"
     * — 404 (entity gone) or the INVALID_ARGUMENT variants that call the
     * token unregistered/malformed. Anything else is transient and the row
     * must be kept for a later retry.
     */
    private static function tokenIsGone(Response $response): bool
    {
        if ($response->status() === 404) {
            return true;
        }

        $message = $response->body();

        return str_contains($message, 'UNREGISTERED')
            || str_contains($message, 'registration-token-not-registered')
            || ($response->status() === 400 && str_contains($message, 'registration token'));
    }

    /**
     * Short-lived OAuth2 access token for the FCM scope, minted from the
     * service account with a signed RS256 JWT grant and cached for 50 minutes
     * so a notification burst costs one token exchange, not one per device.
     */
    private static function accessToken(): string
    {
        $credentials = self::credentials();
        $project = config('services.firebase.project_id');

        return Cache::remember(
            'fcm.access_token.'.$project,
            now()->addMinutes(50),
            function () use ($credentials) {
                $now = time();
                $unsigned = self::base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']))
                    .'.'.self::base64UrlEncode(json_encode([
                        'iss' => $credentials['client_email'],
                        'scope' => self::TOKEN_SCOPE,
                        'aud' => self::OAUTH_URL,
                        'iat' => $now,
                        'exp' => $now + 3600,
                    ]));

                $signature = null;
                if (! openssl_sign($unsigned, $signature, $credentials['private_key'], OPENSSL_ALGO_SHA256)) {
                    throw new \RuntimeException('FCM service-account signing failed (private key unreadable).');
                }

                $assertion = $unsigned.'.'.self::base64UrlEncode($signature);

                $grant = Http::asForm()->timeout(10)->post(self::OAUTH_URL, [
                    'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                    'assertion' => $assertion,
                ]);

                if (! $grant->successful() || $grant->json('access_token') === null) {
                    throw new \RuntimeException('FCM OAuth grant failed: HTTP '.$grant->status().' '.$grant->body());
                }

                return $grant->json('access_token');
            },
        );
    }

    /**
     * The service-account JSON from the FIREBASE_CREDENTIALS setting — either
     * an inline JSON blob or a path to the downloaded file. Throws when it
     * cannot be parsed; callers treat that as "push not configured".
     *
     * @return array{client_email: string, private_key: string}
     */
    private static function credentials(): array
    {
        $raw = (string) config('services.firebase.credentials');

        if ($raw === '') {
            throw new \RuntimeException('FIREBASE_CREDENTIALS is not configured.');
        }

        $json = str_starts_with(ltrim($raw), '{') ? $raw : @file_get_contents($raw);

        $decoded = is_string($json) ? json_decode($json, true) : null;

        if (! is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new \RuntimeException('FIREBASE_CREDENTIALS is not a valid service-account JSON.');
        }

        return [
            'client_email' => (string) $decoded['client_email'],
            'private_key' => (string) $decoded['private_key'],
        ];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
