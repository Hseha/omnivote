<?php

namespace App\Support;

use App\Jobs\SendPushToUsers;
use App\Mail\AdminNotificationMail;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Writes in-app notifications (and, when the matching notification setting is
 * enabled, an admin email) for platform events — and fans the same payload
 * out to registered phones as FCM push (queued, best-effort; see
 * App\Jobs\SendPushToUsers).
 *
 * Every call is best-effort: a failed insert, a failed mail send or a failed
 * push dispatch is logged and swallowed so notification delivery can never
 * break the flow that triggered it (registration, voting, imports, admin
 * actions).
 */
class Notifier
{
    /**
     * Notify the election staff about an event, honoring the related
     * notification setting for the email channel. The in-app row is always
     * written.
     *
     * Recipients are `config('permissions.staff_roles')` (admin + teacher), NOT
     * every panel role — the seated SSG President has a governance console but
     * is not staff and must not be paged with election-administration events.
     *
     * @param  string  $setting  admin.settings.notifications.* key gating email
     * @param  string  $type  info / success / warning / danger
     * @param  string  $title  short headline
     * @param  string|null  $body  supporting detail
     * @param  string|null  $link  frontend route the notification points to
     * @param  bool  $emailDefault  storing default when the setting was never saved
     */
    public static function toAdmins(
        string $setting,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        bool $emailDefault = true,
    ): void {
        $recipients = User::query()
            ->where(function ($q) {
                $q->whereIn('role', config('permissions.staff_roles', ['admin', 'teacher']));
            })
            ->where('is_active', true)
            ->get();

        foreach ($recipients as $user) {
            self::store($user->id, $type, $title, $body, $link);
        }

        if (! self::emailEnabled($setting, $emailDefault) || $recipients->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipients)->send(new AdminNotificationMail($type, $title, $body, $link));
        } catch (\Throwable $e) {
            Log::warning('Notification email failed: '.$e->getMessage());
        }
    }

    /**
     * Write an in-app notification to every active administrator (no email).
     *
     * Used by operational alerting (e.g. the server health check) where only
     * the admin role should be paged — not every panel user. Best-effort like
     * the rest of this class.
     *
     * @return int number of administrators notified
     */
    public static function toAdminUsersInApp(
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
    ): int {
        try {
            $admins = User::query()
                ->where('role', 'admin')
                ->where('is_active', true)
                ->get();

            foreach ($admins as $admin) {
                self::store($admin->id, $type, $title, $body, $link);
            }

            return $admins->count();
        } catch (\Throwable $e) {
            Log::warning('Admin notification failed: '.$e->getMessage());

            return 0;
        }
    }

    /** Write an in-app row for a single recipient (no email). */
    public static function notifyUser(
        int $userId,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
    ): void {
        self::store($userId, $type, $title, $body, $link);
    }

    /**
     * Write the same in-app notification to every active student, optionally
     * narrowed to one year level / department / course (admin broadcast and
     * election-lifecycle fan-out).
     *
     * Rows are inserted in chunks so a school-sized roster never builds one
     * giant insert statement. Best-effort like the rest of this class: a failed
     * chunk is logged and the loop continues rather than aborting the caller.
     *
     * @param  array{year_level?: ?string, department?: ?string, course?: ?string}  $filters
     * @return int number of students notified
     */
    public static function notifyStudents(
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
        array $filters = [],
    ): int {
        // The whole routine is best-effort: even the recipient query is wrapped,
        // because callers (e.g. phase reconciliation) can run against schemas
        // that do not carry the notification tables and must not fail.
        try {
            $query = User::query()
                ->voters()
                ->where('is_active', true);

            foreach (['year_level', 'department', 'course'] as $field) {
                $value = $filters[$field] ?? null;
                if ($value !== null && $value !== '') {
                    $query->where($field, $value);
                }
            }

            $now = now();
            $count = 0;

            $query->select('id')->chunkById(500, function ($students) use ($type, $title, $body, $link, $now, &$count) {
                $rows = [];
                foreach ($students as $student) {
                    $rows[] = [
                        'user_id' => $student->id,
                        'type' => $type,
                        'title' => $title,
                        'body' => $body,
                        'link' => $link,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows === []) {
                    return;
                }

                UserNotification::insert($rows);
                $count += count($rows);

                // Same payload as phone push: every registered device of this
                // chunk's recipients rings once the queued job runs.
                self::queuePush($students->pluck('id')->all(), $title, $body, $link);
            });

            return $count;
        } catch (\Throwable $e) {
            Log::warning('Notification broadcast failed: '.$e->getMessage());

            return 0;
        }
    }

    /**
     * Write the same in-app notification to an explicit list of user ids and
     * queue the matching phone push, in chunks (used by the timed vote
     * reminder, whose recipient list is already narrowed to non-voters).
     *
     * Best-effort like the rest of this class: a failed chunk is logged and
     * the loop continues.
     *
     * @param  array<int, int>  $userIds
     * @return int number of recipients notified
     */
    public static function notifyUserList(
        array $userIds,
        string $type,
        string $title,
        ?string $body = null,
        ?string $link = null,
    ): int {
        $count = 0;
        $now = now();

        foreach (array_chunk($userIds, 500) as $chunk) {
            try {
                $rows = [];
                foreach ($chunk as $userId) {
                    $rows[] = [
                        'user_id' => $userId,
                        'type' => $type,
                        'title' => $title,
                        'body' => $body,
                        'link' => $link,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                UserNotification::insert($rows);
                $count += count($rows);

                self::queuePush($chunk, $title, $body, $link);
            } catch (\Throwable $e) {
                Log::warning('Notification fan-out failed: '.$e->getMessage());
            }
        }

        return $count;
    }

    /**
     * Send a confirmation email to a single user (e.g. the voter who just cast
     * a ballot). Gated by the named setting; failures never propagate.
     */
    public static function emailUser(
        int $userId,
        string $setting,
        string $title,
        ?string $body = null,
        ?string $link = null,
        bool $emailDefault = true,
    ): void {
        if (! self::emailEnabled($setting, $emailDefault)) {
            return;
        }

        try {
            $user = User::find($userId);
            if ($user && $user->email) {
                Mail::to($user)->send(new AdminNotificationMail('info', $title, $body, $link));
            }
        } catch (\Throwable $e) {
            Log::warning('Notification email failed: '.$e->getMessage());
        }
    }

    private static function store(
        int $userId,
        string $type,
        string $title,
        ?string $body,
        ?string $link,
    ): void {
        try {
            UserNotification::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'link' => $link,
            ]);

            // Phone push rides along with every in-app row: the queued job
            // no-ops for users with no registered device (or when FCM/push is
            // not configured), so this stays safe for staff accounts too.
            self::queuePush([$userId], $title, $body, $link);
        } catch (\Throwable $e) {
            Log::warning('Notification insert failed: '.$e->getMessage());
        }
    }

    /**
     * Dispatch the queued FCM job for a recipient batch. Isolated and
     * best-effort: a queue hiccup must never undo a successfully written
     * in-app row.
     *
     * @param  array<int, int>  $userIds
     */
    private static function queuePush(array $userIds, string $title, ?string $body, ?string $link): void
    {
        if ($userIds === []) {
            return;
        }

        try {
            SendPushToUsers::dispatch($userIds, $title, $body, $link);
        } catch (\Throwable $e) {
            Log::warning('Push dispatch failed: '.$e->getMessage());
        }
    }

    /** Whether the given email-gating setting is enabled (master email toggle must also be on). */
    private static function emailEnabled(string $setting, bool $emailDefault): bool
    {
        try {
            $master = AppSettings::notifications('emailEnabled', true);
            $gate = AppSettings::notifications($setting, $emailDefault);

            return $master === true && $gate === true;
        } catch (\Throwable) {
            return false;
        }
    }
}
