<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventPlaceOption;
use Illuminate\Http\Request;

class EventPlaceOptionController extends Controller
{
    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'place_id' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:255'],
            'rating' => ['nullable', 'numeric'],
            'user_ratings_total' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', 'max:50'],
            'meta_json' => ['nullable', 'array'],
        ]);

        $status = $validated['status'] ?? 'tentative';

        $option = null;
        \DB::transaction(function () use ($event, $validated, $status, &$option) {
            if (in_array($status, ['tentative', 'confirmed'], true)) {
                EventPlaceOption::where('event_id', $event->id)
                    ->whereNull('deleted_at')
                    ->update(['status' => 'rejected']);
            }

            $option = EventPlaceOption::create([
                'event_id' => $event->id,
                'place_id' => $validated['place_id'] ?? null,
                'name' => $validated['name'],
                'address' => $validated['address'] ?? null,
                'phone' => $validated['phone'] ?? null,
                'rating' => $validated['rating'] ?? null,
                'user_ratings_total' => $validated['user_ratings_total'] ?? null,
                'status' => $status,
                'meta_json' => $validated['meta_json'] ?? null,
            ]);
        });

        return response()->json(['place_option' => $option]);
    }

    public function update(Request $request, EventPlaceOption $eventPlaceOption)
    {
        $event = $eventPlaceOption->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'status' => ['required', 'string', 'max:50'],
        ]);

        $status = $validated['status'];

        \DB::transaction(function () use ($event, $eventPlaceOption, $status) {
            if (in_array($status, ['tentative', 'confirmed'], true)) {
                EventPlaceOption::where('event_id', $event->id)
                    ->whereNull('deleted_at')
                    ->update(['status' => 'rejected']);
            }

            $eventPlaceOption->update([
                'status' => $status,
            ]);
        });

        return response()->json(['place_option' => $eventPlaceOption]);
    }
}
