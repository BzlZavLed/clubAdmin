<?php

namespace App\Http\Controllers;

use App\Models\Union;
use App\Models\UnionWorkplanEvent;
use App\Support\SuperadminContext;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class UnionWorkplanController extends Controller
{
    public function index(Request $request)
    {
        $union = $this->resolveScopedUnion($request);
        $year  = (int) $request->input('year', now()->year);

        $events = UnionWorkplanEvent::where('union_id', $union->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->orderBy('date')
            ->get();

        return Inertia::render('Union/Workplan', [
            'union'       => ['id' => $union->id, 'name' => $union->name],
            'year'        => $year,
            'events'      => $events,
        ]);
    }

    public function store(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        $validated = $request->validate([
            'year'               => ['required', 'integer', 'min:2000', 'max:2100'],
            'date'               => ['required', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:date'],
            'start_time'         => ['nullable', 'date_format:H:i'],
            'end_time'           => ['nullable', 'date_format:H:i'],
            'event_type'         => ['required', Rule::in(['general', 'program'])],
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'location'           => ['nullable', 'string', 'max:255'],
            'target_club_types'  => ['nullable', 'array'],
            'target_club_types.*'=> ['string', Rule::in(['pathfinders', 'adventurers', 'master_guide'])],
            'is_mandatory'       => ['boolean'],
        ]);

        $event = UnionWorkplanEvent::create([
            ...$validated,
            'union_id'   => $union->id,
            'status'     => 'active',
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Evento creado correctamente.');
    }

    public function update(Request $request, UnionWorkplanEvent $event)
    {
        $union = $this->resolveScopedUnion($request);
        $this->assertOwns($union, $event);

        $validated = $request->validate([
            'date'               => ['required', 'date'],
            'end_date'           => ['nullable', 'date', 'after_or_equal:date'],
            'start_time'         => ['nullable', 'date_format:H:i'],
            'end_time'           => ['nullable', 'date_format:H:i'],
            'event_type'         => ['required', Rule::in(['general', 'program'])],
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string'],
            'location'           => ['nullable', 'string', 'max:255'],
            'target_club_types'  => ['nullable', 'array'],
            'target_club_types.*'=> ['string', Rule::in(['pathfinders', 'adventurers', 'master_guide'])],
            'is_mandatory'       => ['boolean'],
        ]);

        $event->update($validated);

        return back()->with('success', 'Evento actualizado correctamente.');
    }

    public function destroy(Request $request, UnionWorkplanEvent $event)
    {
        $union = $this->resolveScopedUnion($request);
        $this->assertOwns($union, $event);

        $event->update(['status' => 'deleted']);

        return back()->with('success', 'Evento eliminado.');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    protected function resolveScopedUnion(Request $request): Union
    {
        $user = $request->user();
        if (!$user) abort(401);

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();
            if (($context['role'] ?? null) !== 'union_youth_director' || empty($context['union_id'])) {
                abort(403);
            }
            return Union::findOrFail((int) $context['union_id']);
        }

        if ($user->profile_type !== 'union_youth_director' || $user->scope_type !== 'union' || empty($user->scope_id)) {
            abort(403);
        }

        return Union::findOrFail((int) $user->scope_id);
    }

    protected function assertOwns(Union $union, UnionWorkplanEvent $event): void
    {
        if ((int) $event->union_id !== (int) $union->id) abort(403);
    }
}
