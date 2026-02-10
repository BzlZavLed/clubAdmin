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
        ]);

        try {
            $payload = $this->planner->handleMessage($event, $request->user(), $validated['message']);
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 429);
        }

        return response()->json($payload);
    }
}
