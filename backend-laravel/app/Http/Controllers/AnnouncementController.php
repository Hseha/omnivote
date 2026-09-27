<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    public function publicIndex(): JsonResponse
    {
        return response()->json([
            'data' => Announcement::query()
                ->published()
                ->with('author:id,name,avatar_url')
                ->latest('published_at')
                // Bounded feed: the public endpoint is unauthenticated, so it
                // must never serialize an ever-growing table in one response.
                ->limit(100)
                ->get(),
        ]);
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Announcement::query()
                ->with('author:id,name,avatar_url')
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'body' => ['required', 'string', 'max:20000'],
            'published' => ['sometimes', 'boolean'],
        ]);

        $announcement = Announcement::create([
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'body' => $validated['body'],
            'published_at' => ($validated['published'] ?? true) ? now() : null,
        ]);

        return response()->json(['data' => $announcement->load('author:id,name,avatar_url')], 201);
    }

    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:200'],
            'body' => ['sometimes', 'string', 'max:20000'],
            'published' => ['sometimes', 'boolean'],
        ]);

        $user = $request->user();
        if ($announcement->user_id !== $user->id) {
            return response()->json(['message' => 'You may only edit your own announcements.'], 403);
        }

        $announcement->fill($validated);
        if (array_key_exists('published', $validated)) {
            $announcement->published_at = $validated['published']
                ? ($announcement->published_at ?? now())
                : null;
        }
        $announcement->save();

        return response()->json(['data' => $announcement->fresh()->load('author:id,name,avatar_url')]);
    }

    public function destroy(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();
        if ($announcement->user_id !== $user->id) {
            return response()->json(['message' => 'You may only delete your own announcements.'], 403);
        }

        $announcement->delete();

        return response()->json(['message' => 'Announcement deleted.']);
    }
}
