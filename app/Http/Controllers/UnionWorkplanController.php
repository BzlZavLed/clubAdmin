<?php

namespace App\Http\Controllers;

use App\Models\Union;
use App\Models\UnionWorkplanEvent;
use App\Models\UnionWorkplanPublication;
use App\Services\DocumentValidationService;
use App\Services\WorkplanPropagationService;
use App\Support\SuperadminContext;
use Barryvdh\DomPDF\Facade\Pdf;
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
            'publication' => UnionWorkplanPublication::query()
                ->where('union_id', $union->id)
                ->where('year', $year)
                ->first(),
        ]);
    }

    public function pdf(Request $request, DocumentValidationService $documentValidationService)
    {
        $union = $this->resolveScopedUnion($request);
        $year  = (int) $request->input('year', now()->year);
        abort_if($year < 2000 || $year > 2100, 422, 'Invalid year.');

        $events = UnionWorkplanEvent::where('union_id', $union->id)
            ->where('year', $year)
            ->where('status', 'active')
            ->orderBy('date')
            ->orderBy('start_time')
            ->get();

        $generatedAt = now();
        $validation = $documentValidationService->create(
            documentType: 'union_workplan',
            title: 'Plan de trabajo de unión',
            snapshot: [
                'union_id' => $union->id,
                'union_name' => $union->name,
                'year' => $year,
                'events' => $events->map(fn (UnionWorkplanEvent $event) => [
                    'id' => $event->id,
                    'date' => optional($event->date)->format('Y-m-d'),
                    'end_date' => optional($event->end_date)->format('Y-m-d'),
                    'start_time' => $event->start_time,
                    'end_time' => $event->end_time,
                    'event_type' => $event->event_type,
                    'title' => $event->title,
                    'location' => $event->location,
                    'target_club_types' => $event->target_club_types,
                    'is_mandatory' => (bool) $event->is_mandatory,
                ])->all(),
            ],
            metadata: [
                'Union' => $union->name,
                'Documento' => 'Plan de trabajo de unión',
                'Año' => (string) $year,
                'Eventos' => (string) $events->count(),
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        $pdf = Pdf::loadView('pdf.union_workplan', [
            'union' => $union,
            'year' => $year,
            'events' => $events,
            'generatedAt' => $generatedAt,
            'validationUrl' => $validation['url'],
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('a4', 'portrait');

        $filename = 'union-workplan-' . $union->id . '-' . $year . '.pdf';

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
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

    public function publish(Request $request, WorkplanPropagationService $propagationService)
    {
        $union = $this->resolveScopedUnion($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->publishUnion($union, (int) $validated['year'], $request->user());

        return back()->with('success', "Calendario publicado a {$result['associations']} asociaciones.");
    }

    public function unpublish(Request $request, WorkplanPropagationService $propagationService)
    {
        $union = $this->resolveScopedUnion($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->unpublishUnion($union, (int) $validated['year']);

        return back()->with('success', "Calendario despublicado. Se removieron {$result['club_events']} eventos de clubes.");
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
