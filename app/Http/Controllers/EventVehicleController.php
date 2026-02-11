<?php

namespace App\Http\Controllers;

use App\Models\EventDriver;
use App\Models\EventVehicle;
use Illuminate\Http\Request;

class EventVehicleController extends Controller
{
    public function store(Request $request, EventDriver $eventDriver)
    {
        $event = $eventDriver->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'vin' => ['nullable', 'string', 'max:255'],
            'plate' => ['nullable', 'string', 'max:255'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:255'],
        ]);

        $vehicle = EventVehicle::create([
            'event_id' => $event->id,
            'driver_id' => $eventDriver->id,
            'vin' => $validated['vin'] ?? null,
            'plate' => $validated['plate'] ?? null,
            'make' => $validated['make'] ?? null,
            'model' => $validated['model'] ?? null,
            'year' => $validated['year'] ?? null,
        ]);

        return response()->json(['vehicle' => $vehicle]);
    }

    public function update(Request $request, EventVehicle $eventVehicle)
    {
        $event = $eventVehicle->event;
        $this->authorize('update', $event);

        $validated = $request->validate([
            'vin' => ['nullable', 'string', 'max:255'],
            'plate' => ['nullable', 'string', 'max:255'],
            'make' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'year' => ['nullable', 'string', 'max:255'],
        ]);

        $eventVehicle->update($validated);

        return response()->json(['vehicle' => $eventVehicle]);
    }

    public function destroy(EventVehicle $eventVehicle)
    {
        $event = $eventVehicle->event;
        $this->authorize('update', $event);

        $eventVehicle->delete();

        return response()->json(['deleted' => true]);
    }
}
