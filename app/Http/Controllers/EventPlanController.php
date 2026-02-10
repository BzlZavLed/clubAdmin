<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPlan;
use Illuminate\Http\Request;

class EventPlanController extends Controller
{
    public function update(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'plan_json' => ['required', 'array'],
            'missing_items_json' => ['nullable', 'array'],
            'ai_summary' => ['nullable', 'string'],
        ]);

        $plan = $event->plan ?? EventPlan::create([
            'event_id' => $event->id,
            'schema_version' => 1,
            'plan_json' => ['sections' => []],
            'missing_items_json' => [],
            'conversation_json' => [],
        ]);

        $plan->update([
            'plan_json' => $validated['plan_json'],
            'missing_items_json' => $validated['missing_items_json'] ?? $plan->missing_items_json,
            'ai_summary' => $validated['ai_summary'] ?? $plan->ai_summary,
        ]);

        return response()->json([
            'eventPlan' => $plan,
        ]);
    }
}
