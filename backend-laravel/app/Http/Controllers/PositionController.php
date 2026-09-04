<?php

namespace App\Http\Controllers;

use App\Models\Position;
use Illuminate\Http\JsonResponse;

class PositionController extends Controller
{
    /**
     * List active ballot positions (both tiers) for the Flutter candidate/
     * ballot flows.
     */
    public function index(): JsonResponse
    {
        $positions = Position::query()
            ->where('is_active', true)
            ->orderBy('tier')
            ->orderBy('sort_order')
            ->get()
            ->map(fn (Position $p) => [
                'id' => $p->id,
                'slug' => $p->slug,
                'label' => $p->label,
                'name' => $p->label,
                'tier' => $p->tier,
                'seat_count' => $p->seat_count,
                'description' => $p->label,
                'is_active' => $p->is_active,
            ]);

        return response()->json($positions);
    }
}