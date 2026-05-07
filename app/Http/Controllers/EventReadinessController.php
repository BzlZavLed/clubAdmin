<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Services\DocumentValidationService;
use App\Services\EventReadinessService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EventReadinessController extends Controller
{
    public function show(Event $event, EventReadinessService $readinessService)
    {
        $this->authorize('view', $event);

        return Inertia::render('EventPlanner/Readiness', [
            'event' => [
                'id' => (int) $event->id,
                'title' => $event->title,
                'event_type' => $event->event_type,
                'scope_type' => $event->scope_type ?: 'club',
                'scope_id' => (int) ($event->scope_id ?: $event->club_id),
                'start_at' => optional($event->start_at)->toDateTimeString(),
                'end_at' => optional($event->end_at)->toDateTimeString(),
                'effective_status' => $event->effective_status,
            ],
            'readiness' => $readinessService->report($event, request()->user()),
        ]);
    }

    public function pdf(Event $event, EventReadinessService $readinessService, DocumentValidationService $documentValidationService)
    {
        $this->authorize('view', $event);

        $readiness = $readinessService->report($event, request()->user());
        $generatedAt = now();
        $validation = $documentValidationService->create(
            documentType: 'event_readiness_report',
            title: 'Preparacion del evento',
            snapshot: [
                'event_id' => (int) $event->id,
                'event_title' => $event->title,
                'event_type' => $event->event_type,
                'scope_type' => $event->scope_type ?: 'club',
                'scope_id' => (int) ($event->scope_id ?: $event->club_id),
                'totals' => $readiness['totals'] ?? [],
                'clubs' => collect($readiness['clubs'] ?? [])->map(fn (array $club) => [
                    'club_id' => $club['club_id'] ?? null,
                    'club_name' => $club['club_name'] ?? null,
                    'status' => $club['status'] ?? null,
                    'status_label' => $club['status_label'] ?? null,
                    'signup_status' => $club['signup_status'] ?? null,
                    'participants' => $club['participants'] ?? [],
                    'tasks' => [
                        'total' => $club['tasks']['total'] ?? 0,
                        'done' => $club['tasks']['done'] ?? 0,
                        'pending' => $club['tasks']['pending'] ?? 0,
                    ],
                    'documents' => $club['documents'] ?? [],
                    'finance' => $club['finance'] ?? [],
                    'blockers' => collect($club['blockers'] ?? [])->map(fn (array $blocker) => [
                        'severity' => $blocker['severity'] ?? null,
                        'type' => $blocker['type'] ?? null,
                        'label' => $blocker['label'] ?? null,
                        'message' => $blocker['message'] ?? null,
                    ])->values()->all(),
                ])->values()->all(),
                'closeout' => $readiness['closeout'] ?? [],
                'financial_report' => $readiness['financial_report'] ?? [],
            ],
            metadata: [
                'Evento' => $event->title,
                'Clubes' => (int) ($readiness['totals']['clubs'] ?? 0),
                'Preparacion completa' => (int) ($readiness['totals']['ready_clubs'] ?? 0),
                'Pendientes' => (int) ($readiness['totals']['pending_clubs'] ?? 0),
                'Atencion critica' => (int) ($readiness['totals']['blocked_clubs'] ?? 0),
            ],
            generatedBy: request()->user(),
            generatedAt: $generatedAt,
        );

        return Pdf::loadView('pdf.event_readiness', [
            'event' => $event,
            'readiness' => $readiness,
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('letter', 'landscape')
            ->download('event-readiness-' . $event->id . '.pdf');
    }

    public function financialPdf(Request $request, Event $event, EventReadinessService $readinessService, DocumentValidationService $documentValidationService)
    {
        $this->authorize('view', $event);

        $readiness = $readinessService->report($event, $request->user());
        $includeTargeted = $request->boolean('include_targeted', true);
        $includeBreakdown = $request->boolean('include_breakdown', true);
        $financialReport = $this->filteredFinancialReport($readiness['financial_report'] ?? [], $includeTargeted);
        $generatedAt = now();
        $validation = $documentValidationService->create(
            documentType: 'event_financial_report',
            title: 'Reporte financiero del evento',
            snapshot: [
                'event_id' => (int) $event->id,
                'event_title' => $event->title,
                'event_type' => $event->event_type,
                'scope_type' => $event->scope_type ?: 'club',
                'scope_id' => (int) ($event->scope_id ?: $event->club_id),
                'include_targeted_clubs' => $includeTargeted,
                'include_participant_breakdown' => $includeBreakdown,
                'financial_report' => $financialReport,
            ],
            metadata: [
                'Evento' => $event->title,
                'Filtro' => $includeTargeted ? 'Incluye clubes targeted sin pagos' : 'Solo clubes con pagos',
                'Desglose' => $includeBreakdown ? 'Incluye miembros/staff' : 'Solo listado general por club',
                'Clubes' => (int) ($financialReport['totals']['clubs'] ?? 0),
                'Participantes' => (int) ($financialReport['totals']['participants'] ?? 0),
                'Pagado' => '$' . number_format((float) ($financialReport['totals']['paid_amount'] ?? 0), 2),
            ],
            generatedBy: $request->user(),
            generatedAt: $generatedAt,
        );

        return Pdf::loadView('pdf.event_financial_report', [
            'event' => $event,
            'financialReport' => $financialReport,
            'includeTargeted' => $includeTargeted,
            'includeBreakdown' => $includeBreakdown,
            'generatedAt' => $generatedAt->format('Y-m-d H:i'),
            'qrCodeDataUri' => $validation['qr_code_data_uri'],
        ])->setPaper('letter', 'landscape')
            ->download('event-financial-report-' . $event->id . '.pdf');
    }

    protected function filteredFinancialReport(array $financialReport, bool $includeTargeted): array
    {
        $components = $financialReport['components'] ?? [];
        $clubs = collect($financialReport['clubs'] ?? [])
            ->when(!$includeTargeted, fn ($rows) => $rows->filter(fn (array $club) => (float) ($club['paid_amount'] ?? 0) > 0))
            ->values();
        $clubIds = $clubs->pluck('club_id')->map(fn ($id) => (int) $id)->all();
        $participants = collect($financialReport['participants'] ?? [])
            ->when(!$includeTargeted, fn ($rows) => $rows->filter(fn (array $participant) => in_array((int) ($participant['club_id'] ?? 0), $clubIds, true)))
            ->values();

        return [
            'components' => $components,
            'totals' => [
                'clubs' => $clubs->count(),
                'participants' => $participants->count(),
                'expected_amount' => round((float) $clubs->sum(fn (array $club) => (float) ($club['expected_amount'] ?? 0)), 2),
                'paid_amount' => round((float) $clubs->sum(fn (array $club) => (float) ($club['paid_amount'] ?? 0)), 2),
                'required_paid_amount' => round((float) $clubs->sum(fn (array $club) => (float) ($club['required_paid_amount'] ?? 0)), 2),
                'optional_paid_amount' => round((float) $clubs->sum(fn (array $club) => (float) ($club['optional_paid_amount'] ?? 0)), 2),
                'pending_settlement_amount' => round((float) $clubs->sum(fn (array $club) => (float) ($club['pending_settlement_amount'] ?? 0)), 2),
            ],
            'clubs' => $clubs->all(),
            'participants' => $participants->all(),
        ];
    }
}
