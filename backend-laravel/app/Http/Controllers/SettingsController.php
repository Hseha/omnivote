<?php

namespace App\Http\Controllers;

use App\Models\Phase;
use App\Support\TermArchive;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Admin settings surface (React Configuration screen).
 *
 *   GET   /api/admin/settings            — every section (frontend cache-warm)
 *   GET   /api/admin/settings/{section}  — a single section
 *   PATCH /api/admin/settings/{section}  — persist one section
 *
 * Values live in the `election_settings` key/value store under an
 * `admin.settings.{section}.{key}` prefix, serialized to strings
 * (booleans become 'true'/'false', scalars their string form).
 */
class SettingsController extends Controller
{
    private const PREFIX = 'admin.settings.';

    private const SECTIONS = [
        'security',
        'voting',
        'branding',
        'backup',
        'notifications',
    ];

    /**
     * Keys an administrator may write per section (security assessment L-4).
     *
     * The endpoint used to iterate `$request->all()` and persist whatever
     * arrived, so any caller with `settings.update` could mint arbitrary
     * `admin.settings.*` rows and store multi-megabyte values.
     *
     * The list below is the union of every key the Settings screen writes and
     * every key read back through AppSettings. To avoid stranding a key that
     * was legitimately stored before this allow-list existed, a key that is
     * ALREADY present in the section is always accepted — so upgrading cannot
     * make an existing setting unwritable, it only blocks *new* unknown keys.
     */
    private const WRITABLE_KEYS = [
        'security' => [
            'maxLoginAttempts', 'passwordMinLength', 'passwordRequireNumber',
            'passwordRequireSpecial', 'passwordRequireUpper', 'sessionTimeout',
            'twoFactorRequired', 'selfRegistrationEnabled', 'loginBackoffEnabled',
        ],
        'voting' => [
            'maxVotesPerVoter', 'allowVoteChange', 'registrationStart',
            'registrationEnd', 'votingStart', 'votingEnd', 'termEndsAt',
            'showResultsAfterClose', 'voteConfirmationRequired',
        ],
        'branding' => [
            'siteName', 'logoUrl', 'primaryColor', 'secondaryColor',
            'faviconUrl', 'headerText', 'footerText',
        ],
        'backup' => [
            'autoBackup', 'backupFrequency', 'backupRetention',
            'backupEncryption', 'remoteStorage',
        ],
        'notifications' => [
            'emailEnabled', 'smsEnabled', 'pushEnabled', 'dailyDigest',
            'emailOnVote', 'emailOnResult', 'emailOnAdminAction',
            'smsOnCritical', 'notifyOnRegistration',
        ],
    ];

    /** Longest single setting value persisted (branding text is the largest). */
    private const MAX_VALUE_LENGTH = 4096;

    /** Most keys accepted in one PATCH, to bound the request/work. */
    private const MAX_KEYS_PER_REQUEST = 64;

    /** Keys that must never be written, whatever else is allowed. */
    private const FORBIDDEN_KEYS = ['__proto__', 'constructor', 'prototype'];

    public function index(): JsonResponse
    {
        // Opening Settings is a natural checkpoint for the term lifecycle: if
        // the configured Term Ends date has passed, close that term now.
        TermArchive::runIfDue();

        $all = [];
        foreach (self::SECTIONS as $section) {
            $all[$section] = $this->readSection($section);
        }

        return response()->json(['settings' => $all]);
    }

    public function show(string $section): JsonResponse
    {
        abort_unless($this->isValidSection($section), 404);

        return response()->json(['settings' => $this->readSection($section)]);
    }

    /**
     * Public branding surface consumed by the React admin shell before/without
     * authentication (login screen, browser title, favicon, accent colors).
     * Only the non-sensitive branding section is exposed here; everything else
     * lives behind the permission-gated /admin/settings endpoints.
     */
    public function branding(): JsonResponse
    {
        $defaults = [
            'siteName' => 'OmniVote',
            'logoUrl' => '',
            'primaryColor' => '#2563eb',
            'secondaryColor' => '#64748b',
            'faviconUrl' => '',
            'headerText' => 'Secure Election Platform',
            'footerText' => 'Powered by OmniVote Administration Console',
        ];

        return response()->json([
            'branding' => array_merge($defaults, array_filter($this->readSection('branding'))),
        ]);
    }

    public function update(Request $request, string $section): JsonResponse
    {
        abort_unless($this->isValidSection($section), 404);

        if ($section === 'voting') {
            $problem = $this->validateVotingWindow($request);
            if ($problem !== null) {
                return response()->json(['message' => $problem], 422);
            }
        }

        // Key allow-list + value caps (security assessment L-4). Previously every
        // field in the body was persisted verbatim, so a caller could create
        // arbitrary `admin.settings.*` rows and bloat the table with huge values.
        $payload = $request->all();

        if (count($payload) > self::MAX_KEYS_PER_REQUEST) {
            return response()->json([
                'message' => 'Too many settings in one request.',
            ], 422);
        }

        $existing = array_keys($this->readSection($section));
        $allowed = self::WRITABLE_KEYS[$section] ?? [];
        $accepted = [];

        foreach ($payload as $key => $value) {
            $key = (string) $key;

            $problem = $this->settingsKeyProblem($key, $allowed, $existing);
            if ($problem !== null) {
                return response()->json(['message' => $problem], 422);
            }

            $encoded = $this->encode($value);

            if ($encoded !== null && strlen($encoded) > self::MAX_VALUE_LENGTH) {
                return response()->json([
                    'message' => "Setting '{$key}' is too long (max ".self::MAX_VALUE_LENGTH.' characters).',
                ], 422);
            }

            $accepted[$key] = $encoded;
        }

        foreach ($accepted as $key => $encoded) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $this->prefix($section).$key],
                ['value' => $encoded, 'updated_at' => now()],
            );
        }

        if ($section === 'voting') {
            $this->syncElectionTimeline($request);
        }

        return response()->json([
            'message' => ucfirst($section).' settings saved.',
            'settings' => $this->readSection($section),
        ]);
    }

    /**
     * Returns a human-readable reason the key may not be written, or null.
     *
     * A key is acceptable when it is in the section's allow-list OR was already
     * stored for that section. The second clause is what keeps this change
     * backwards compatible: a setting persisted by an older build stays
     * editable, and only genuinely new keys are refused.
     */
    private function settingsKeyProblem(string $key, array $allowed, array $existing): ?string
    {
        if ($key === '') {
            return 'Setting name must not be empty.';
        }

        if (in_array($key, self::FORBIDDEN_KEYS, true)) {
            return "Setting '{$key}' is not allowed.";
        }

        if (strlen($key) > 64 || ! preg_match('/^[A-Za-z0-9_.-]+$/', $key)) {
            return "Setting '{$key}' contains unsupported characters.";
        }

        if (in_array($key, $allowed, true) || in_array($key, $existing, true)) {
            return null;
        }

        return "Setting '{$key}' is not a recognised option.";
    }

    /**
     * Ordering + range sanity checks for the Voting Windows section. Returns a
     * human-friendly problem description (→ 422) or null when the window is
     * valid. A value is optional; a pair is only validated when both sides are
     * actually provided.
     */
    private function validateVotingWindow(Request $request): ?string
    {
        $fields = [
            'registrationStart' => 'Registration Opens',
            'registrationEnd' => 'Registration Closes',
            'votingStart' => 'Voting Opens',
            'votingEnd' => 'Voting Closes',
            'termEndsAt' => 'Term Ends',
        ];

        // A malformed instant used to be silently persisted: parseDateTime()
        // swallows the error, the comparison checks then saw `null` and passed,
        // and the junk string sat in election_settings forever. TermArchive
        // re-parses the same way, so the panel just displayed "Not set" with
        // nothing to indicate the save had been accepted. Refuse it up front
        // instead — a typo should be an error, not a mystery.
        $dates = [];
        foreach ($fields as $field => $label) {
            $raw = $request->input($field);
            $dates[$field] = ($raw === null || (is_string($raw) && trim($raw) === ''))
                ? null
                : self::parseDateTime($raw);

            if ($dates[$field] === null && $raw !== null && ! (is_string($raw) && trim($raw) === '')) {
                return "{$label} is not a valid date and time.";
            }
        }

        $registrationOpens = $dates['registrationStart'];
        $registrationCloses = $dates['registrationEnd'];
        $votingOpens = $dates['votingStart'];
        $votingCloses = $dates['votingEnd'];
        $termEndsAt = $dates['termEndsAt'];

        if ($registrationOpens && $registrationCloses && $registrationCloses->lte($registrationOpens)) {
            return 'Registration Closes must be after Registration Opens.';
        }
        if ($registrationCloses && $votingOpens && $votingOpens->lt($registrationCloses)) {
            return 'Voting Opens must be after Registration Closes.';
        }
        if ($votingOpens && $votingCloses && $votingCloses->lte($votingOpens)) {
            return 'Voting Closes must be after Voting Opens.';
        }
        if ($registrationOpens && $votingCloses && $votingCloses->lte($registrationOpens)) {
            return 'Voting Closes must be after Registration Opens.';
        }
        if ($termEndsAt && $votingCloses && $termEndsAt->lt($votingCloses)) {
            return 'Term Ends must be after Voting Closes.';
        }
        if ($termEndsAt && $registrationOpens && $termEndsAt->lt($registrationOpens)) {
            return 'Term Ends must be after Registration Opens.';
        }

        $maxVotes = $request->input('maxVotesPerVoter');
        if ($maxVotes !== null && $maxVotes !== '' && ((int) $maxVotes < 1 || (int) $maxVotes > 20)) {
            return 'Maximum Votes Per Voter must be between 1 and 20.';
        }

        return null;
    }

    private static function parseDateTime(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Mirrors the "Settings → Voting Windows" screen into the canonical
     * election timeline used by the rest of the app (status endpoint, Election
     * Setup, dashboard phase) and then reconciles the active phase with the
     * current time, so the dashboard badge, phase-gated student routes, and
     * results visibility all follow the configured window.
     */
    private function syncElectionTimeline(Request $request): void
    {
        $timeline = [
            'registration_opens_at' => $request->input('registrationStart'),
            'registration_closes_at' => $request->input('registrationEnd'),
            'voting_opens_at' => $request->input('votingStart'),
            'voting_closes_at' => $request->input('votingEnd'),
        ];

        // Everything cleared → the schedule is unset, so there is no phase at
        // all (clients show "Not Configured" rather than a stale phase).
        if (! array_filter($timeline)) {
            foreach ($timeline as $key => $value) {
                DB::table('election_settings')->updateOrInsert(
                    ['key' => $key],
                    ['value' => $this->encode($value), 'updated_at' => now()],
                );
            }
            DB::table('phases')->update(['is_active' => false]);

            return;
        }

        foreach ($timeline as $key => $value) {
            DB::table('election_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $this->encode($value), 'updated_at' => now()],
            );
        }

        $phase = Phase::derivedName($timeline);
        if ($phase !== null) {
            try {
                Phase::setCurrent($phase);
            } catch (ModelNotFoundException) {
                // Known phase rows always exist; ignore if one was removed.
            }
        }
    }

    private function readSection(string $section): array
    {
        $rows = DB::table('election_settings')
            ->where('key', 'like', $this->prefix($section).'%')
            ->pluck('value', 'key');

        $values = [];
        foreach ($rows as $key => $value) {
            $values[substr($key, strlen($this->prefix($section)))] = $this->decode($value);
        }

        return $values;
    }

    private function isValidSection(string $section): bool
    {
        return in_array($section, self::SECTIONS, true);
    }

    private function prefix(string $section): string
    {
        return self::PREFIX.$section.'.';
    }

    private function encode(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function decode(?string $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if ($value === 'true') {
            return true;
        }
        if ($value === 'false') {
            return false;
        }
        if (is_numeric($value)) {
            return $value + 0;
        }

        return $value;
    }
}
