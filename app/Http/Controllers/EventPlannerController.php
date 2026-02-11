<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\AiPlannerService;
use Illuminate\Http\Request;
use RuntimeException;

class EventPlannerController extends Controller
{
    public function __construct(private AiPlannerService $planner)
    {
    }

    public function message(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'create_budget_item' => ['nullable', 'boolean'],
        ]);

        try {
            $payload = $this->planner->handleMessage(
                $event,
                $request->user(),
                $validated['message'],
                [
                    'create_budget_item' => $validated['create_budget_item'] ?? null,
                ]
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 429);
        }

        return response()->json($payload);
    }
}
