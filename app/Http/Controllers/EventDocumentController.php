<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\EventDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class EventDocumentController extends Controller
{
    public function index(Event $event)
    {
        $this->authorize('view', $event);

        return response()->json([
            'documents' => $event->documents()->latest()->get(),
        ]);
    }

    public function store(Request $request, Event $event)
    {
        $this->authorize('update', $event);

        $validated = $request->validate([
            'type' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'file' => ['required', 'file', 'max:5120'],
            'meta_json' => ['nullable', 'array'],
        ]);

        $path = $request->file('file')->store('event-documents/' . $event->id, 'public');

        $document = EventDocument::create([
            'event_id' => $event->id,
            'type' => $validated['type'],
            'title' => $validated['title'],
            'path' => $path,
            'uploaded_by_user_id' => $request->user()->id,
            'meta_json' => $validated['meta_json'] ?? null,
        ]);

        return response()->json(['document' => $document]);
    }

    public function destroy(EventDocument $eventDocument)
    {
        $event = $eventDocument->event;
        $this->authorize('update', $event);

        if ($eventDocument->path) {
            Storage::disk('public')->delete($eventDocument->path);
        }

        $eventDocument->delete();

        return response()->json(['deleted' => true]);
    }
}
