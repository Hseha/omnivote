<?php

namespace App\Http\Controllers;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin notification center (React shell bell + dashboard activity feed).
 *
 *   GET  /api/admin/notifications        — recent notifications for the caller
 *   POST /api/admin/notifications/read   — mark one/all as read
 *
 * Every notification is scoped to the authenticated user, so one admin's
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
}
