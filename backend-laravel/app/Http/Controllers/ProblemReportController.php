<?php

namespace App\Http\Controllers;

use App\Support\Notifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /api/reports — student-submitted problem / bug report.
 *
 * Sent from the candidacy apply screen (and potentially elsewhere) so the
 * election staff can investigate root causes. Writes an in-app notification
 * to every active staff member (admin + teacher) via Notifier::toAdmins().
 *
 * No new table: reuses `user_notifications`.
 */
class ProblemReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'screen' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'max:100'],
            'error_code' => ['required', 'string', 'max:100'],
            'error_message' => ['required', 'string', 'max:1000'],
            'context' => ['nullable', 'array'],
            'description' => ['nullable', 'string', 'max:5000'],
            'occurred_at' => ['nullable', 'date'],
        ]);

        $context = $validated['context'] ?? [];
        $context['user_id'] = $request->user()->id;
        $context['user_name'] = $request->user()->name;
        $context['user_email'] = $request->user()->email;

        $title = 'Problem report: '.$validated['screen'].' / '.$validated['action'];

        $bodyParts = [
            'Error: '.$validated['error_message'],
            'Code: '.$validated['error_code'],
            'Context: '.json_encode($context),
        ];
        if (! empty($validated['description'])) {
            $bodyParts[] = 'User details: '.$validated['description'];
        }

        Notifier::toAdmins(
            'notifications.problem_reports',
            'danger',
            $title,
            implode("\n", $bodyParts),
            null,
            false
        );

        return response()->json([
            'message' => 'Report received. Thank you.',
        ], 201);
    }
}