<?php

namespace App\Support;

use App\Mail\AdminNotificationMail;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Writes in-app notifications (and, when the matching notification setting is
 * enabled, an admin email) for platform events.
 *
 * Every call is best-effort: a failed insert or a failed mail send is logged
 * and swallowed so notification delivery can never break the flow that
 * triggered it (registration, voting, imports, admin actions).
 */
class Notifier
{
    /**
     * Notify the admins about an event, honoring the related notification
     * setting for the email channel. The in-app row is always written.
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
                $q->whereIn('role', config('permissions.panel_roles', []));
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
     * @return int  number of students notified
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
                ->where('role', 'student')
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
            });

            return $count;
        } catch (\Throwable $e) {
            Log::warning('Notification broadcast failed: '.$e->getMessage());

            return 0;
        }
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
        } catch (\Throwable $e) {
            Log::warning('Notification insert failed: '.$e->getMessage());
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
