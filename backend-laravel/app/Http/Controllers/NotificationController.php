<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Notification center shared by the student app and the admin console.
 *
 *   GET  /api/admin/notifications         — recent notifications for the caller
 *   POST /api/admin/notifications/read    — mark one/all as read
 *   POST /api/admin/notifications/broadcast — fan out to students
 *   GET  /api/notifications               — same feed for the student app
 *   POST /api/notifications/read          — same read action for the student app
 *
 * Every notification is scoped to the authenticated user, so one account's
 * read/unread state never leaks to another.
 */
class NotificationController extends Controller
{
    public function index(Request $request, int $limit = 25): JsonResponse
    {
        $limit = min(max($limit, 1), 50);

        $notifications = UserNotification::query()
            ->for($request->user()->id)
            ->limit($limit)
            ->get();

        $unread = UserNotification::query()
            ->for($request->user()->id)
            ->unread()
            ->count();

        return response()->json([
            'notifications' => $notifications->map(fn (UserNotification $n) => [
                'id' => $n->id,
                'type' => $n->type,
                'title' => $n->title,
                'body' => $n->body,
                'link' => $n->link,
                'read' => $n->isRead(),
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
            'unread' => $unread,
        ]);
    }

    /** Mark a single notification or every notification as read. */
    public function markRead(Request $request): JsonResponse
    {
        $validated = $request->validate(['id' => ['sometimes', 'integer']]);

        $query = UserNotification::query()->for($request->user()->id)->unread();

        if (isset($validated['id'])) {
            $query->where('id', $validated['id']);
        }

        $updated = $query->update(['read_at' => now()]);

        return response()->json(['updated' => $updated]);
    }

    /**
     * Fan a single notification out to students (admin broadcast composer).
     *
     * Targeting is optional: with no filters it reaches every active student,
     * otherwise it narrows to one year level / department / course.
     */
    public function broadcast(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:20000'],
            'link' => ['nullable', 'string', 'max:500'],
            'type' => ['sometimes', 'string', 'in:info,success,warning,danger'],
            'year_level' => ['nullable', 'string', 'max:64'],
            'department' => ['nullable', 'string', 'max:128'],
            'course' => ['nullable', 'string', 'max:128'],
        ]);

        $recipients = Notifier::notifyStudents(
            $validated['type'] ?? 'info',
            $validated['title'],
            $validated['body'] ?? null,
            $validated['link'] ?? null,
            [
                'year_level' => $validated['year_level'] ?? null,
                'department' => $validated['department'] ?? null,
                'course' => $validated['course'] ?? null,
            ],
        );

        return response()->json([
            'message' => "Notification sent to {$recipients} student(s).",
            'recipients' => $recipients,
        ], 201);
    }
}
