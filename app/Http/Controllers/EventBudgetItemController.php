<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventBudgetItem;
use Illuminate\Http\Request;

class EventBudgetItemController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'budget_items' => $event->budgetItems()->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $item = EventBudgetItem::create([
            'event_id' => $event->id,
            'category' => $validated['category'],
            'description' => $validated['description'],
            'qty' => $validated['qty'] ?? 1,
            'unit_cost' => $validated['unit_cost'] ?? 0,
            'funding_source' => $validated['funding_source'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return response()->json(['budget_item' => $item]);
    }

    public function update(Request $request, EventBudgetItem $eventBudgetItem)
    {
        $event = $eventBudgetItem->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'category' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric'],
            'unit_cost' => ['nullable', 'numeric'],
            'funding_source' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        $eventBudgetItem->update($validated);

        return response()->json(['budget_item' => $eventBudgetItem]);
    }

    public function destroy(EventBudgetItem $eventBudgetItem)
    {
        $event = $eventBudgetItem->event;
        $this->authorize('update', $event);

        $eventBudgetItem->delete();

        return response()->json(['deleted' => true]);
    }
}
