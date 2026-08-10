<?php

namespace App\Http\Controllers;

use App\Models\AdventurerInductionRequest;
use App\Models\AdventurerQuarterlyReport;
use App\Models\AdventurerYearlyApplication;
use App\Models\Association;
use App\Models\AssociationEvaluator;
use App\Models\AssociationHonorClassSession;
use App\Models\AssociationWorkplanEvent;
use App\Models\AssociationWorkplanPublication;
use App\Models\BankInfo;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\PathfinderAnnualApplication;
use App\Models\PathfinderMonthlyReport;
use App\Models\PaymentConcept;
use App\Models\UnionCarpetaRequirement;
use App\Models\Union;
use App\Models\User;
use App\Support\SuperadminContext;
use App\Support\BankInfoFormatter;
use App\Services\WorkplanPropagationService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AssociationController extends Controller
{
    protected function syncInsurancePaymentConcepts(Association $association, ?int $actorId = null): void
    {
        $amount = (float) ($association->insurance_payment_amount ?? 0);
        $districtIds = $association->districts()->pluck('id');

        if ($districtIds->isEmpty()) {
            return;
        }

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->whereIn('district_id', $districtIds)
            ->get(['id']);

        foreach ($clubs as $club) {
            $concept = PaymentConcept::withTrashed()
                ->where('club_id', $club->id)
                ->where('concept', 'Seguro de membresía')
                ->where('pay_to', 'church_budget')
                ->first();

            if ($amount <= 0) {
                if ($concept) {
                    $concept->update([
                        'amount' => 0,
                        'status' => 'inactive',
                        'reusable' => true,
                    ]);
                }

                continue;
            }

            if ($concept && method_exists($concept, 'trashed') && $concept->trashed()) {
                $concept->restore();
            }

            $concept ??= PaymentConcept::create([
                'club_id' => $club->id,
                'concept' => 'Seguro de membresía',
                'pay_to' => 'church_budget',
                'type' => 'mandatory',
                'status' => 'active',
                'created_by' => $actorId,
                'amount' => $amount,
                'reusable' => true,
            ]);

            $concept->update([
                'amount' => $amount,
                'type' => 'mandatory',
                'status' => 'active',
                'reusable' => true,
            ]);
        }
    }

    protected function normalizeClubType(?string $value): string
    {
        return str_replace(['-', '_', ' '], '', mb_strtolower((string) $value));
    }

    public function index()
    {
        return Inertia::render('SuperAdmin/Associations', [
            'unions' => Union::query()
                ->where('status', '!=', 'deleted')
                ->orderBy('name')
                ->get(['id', 'name', 'status']),
            'associations' => Association::query()
                ->with('union:id,name')
                ->withCount('districts')
                ->orderBy('name')
                ->get(['id', 'union_id', 'name', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'union_id' => ['required', 'exists:unions,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('associations', 'name')->where(function ($query) use ($request) {
                    return $query
                        ->where('union_id', $request->input('union_id'))
                        ->where('status', '!=', 'deleted');
                }),
            ],
        ]);

        Association::create([
            'union_id' => $validated['union_id'],
            'name' => $validated['name'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Asociacion creada correctamente.');
    }

    public function update(Request $request, Association $association)
    {
        $validated = $request->validate([
            'union_id' => ['required', 'exists:unions,id'],
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('associations', 'name')
                    ->ignore($association->id)
                    ->where(function ($query) use ($request) {
                        return $query
                            ->where('union_id', $request->input('union_id'))
                            ->where('status', '!=', 'deleted');
                    }),
            ],
        ]);

        $association->update([
            'union_id' => $validated['union_id'],
            'name' => $validated['name'],
        ]);

        return back()->with('success', 'Asociacion actualizada correctamente.');
    }

    public function deactivate(Association $association)
    {
        $association->update(['status' => 'inactive']);

        return back()->with('success', 'Asociacion desactivada correctamente.');
    }

    public function destroy(Association $association)
    {
        $association->update(['status' => 'deleted']);

        return back()->with('success', 'Asociacion eliminada correctamente.');
    }

    public function programs(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system,status');

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->whereHas('church.district', fn ($query) => $query->where('association_id', $association->id))
            ->with(['church:id,district_id,church_name', 'church.district:id,association_id,name'])
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'evaluation_system', 'church_id', 'church_name', 'district_id', 'user_id', 'director_name', 'status']);

        $payload = [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $association->union?->evaluation_system ?: 'honors',
            ],
            'clubs' => $clubs->map(fn ($club) => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'evaluation_system' => $club->evaluation_system,
                'church_name' => $club->church_name,
                'district_name' => $club->church?->district?->name,
                'director_name' => $club->director_name,
                'status' => $club->status,
            ])->values(),
        ];

        if (($association->union?->evaluation_system ?: 'honors') === 'carpetas') {
            $currentYear = $association->union?->carpetaYears()
                ->with('requirements')
                ->orderByRaw("CASE WHEN status = 'published' THEN 0 ELSE 1 END")
                ->orderByDesc('year')
                ->orderByDesc('id')
                ->first();

            $payload['carpeta_year'] = $currentYear ? [
                'id' => $currentYear->id,
                'year' => $currentYear->year,
                'status' => $currentYear->status,
                'requirements' => $currentYear->requirements->map(fn ($requirement) => [
                    'id' => $requirement->id,
                    'title' => $requirement->title,
                    'description' => $requirement->description,
                    'club_type' => $requirement->club_type,
                    'class_name' => $requirement->class_name,
                    'requirement_type' => $requirement->requirement_type,
                    'validation_mode' => $requirement->validation_mode,
                    'status' => $requirement->status,
                ])->values(),
            ] : null;

            $payload['requirement_catalog'] = $this->buildAssociationRequirementCatalog($currentYear);
            $payload['club_progress_tracker'] = $currentYear
                ? $this->computeAssociationCarpetaClubProgress($clubs, $currentYear->id)
                : [];
        } else {
            $payload['honor_sessions'] = $association->honorClassSessions()
                ->get()
                ->map(fn ($session) => [
                    'id' => $session->id,
                    'club_type' => $session->club_type,
                    'class_name' => $session->class_name,
                    'title' => $session->title,
                    'session_date' => optional($session->session_date)->toDateString(),
                    'location' => $session->location,
                    'notes' => $session->notes,
                    'status' => $session->status,
                ])
                ->values();
        }

        return Inertia::render('Association/Programs', $payload);
    }

    public function forms(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system');

        abort_unless(($association->union?->evaluation_system ?: 'honors') === 'honors', 404);

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->where(function ($query) use ($association) {
                $query
                    ->whereHas('district', fn ($district) => $district->where('association_id', $association->id))
                    ->orWhereHas('church.district', fn ($district) => $district->where('association_id', $association->id));
            })
            ->where('status', 'active')
            ->whereIn('club_type', ['adventurers', 'pathfinders'])
            ->with([
                'church:id,church_name,district_id',
                'church.district:id,name,association_id',
                'district:id,name,association_id',
                'adventurerYearlyApplications',
                'adventurerQuarterlyReports',
                'adventurerInductionRequests',
                'pathfinderAnnualApplications',
                'pathfinderMonthlyReports',
            ])
            ->orderBy('club_name')
            ->get();

        $formTypes = [
            'adventurers' => [
                ['key' => 'adventurer_yearly_application', 'label_es' => 'Solicitud anual', 'label_en' => 'Yearly application'],
                ['key' => 'adventurer_quarterly_report', 'label_es' => 'Reporte trimestral', 'label_en' => 'Quarterly report'],
                ['key' => 'adventurer_induction_request', 'label_es' => 'Solicitud de inducción', 'label_en' => 'Induction request'],
            ],
            'pathfinders' => [
                ['key' => 'pathfinder_annual_application', 'label_es' => 'Solicitud anual', 'label_en' => 'Annual application'],
                ['key' => 'pathfinder_monthly_report', 'label_es' => 'Reporte mensual', 'label_en' => 'Monthly report'],
            ],
        ];

        $submissions = $clubs
            ->flatMap(fn (Club $club) => $this->associationClubFormSubmissions($club))
            ->sortByDesc('submitted_at')
            ->values();

        $latestByClub = $clubs->map(function (Club $club) use ($submissions) {
            $clubSubmissions = $submissions
                ->where('club_id', $club->id)
                ->values();

            return [
                'club_id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'church_name' => $club->church?->church_name ?: $club->church_name,
                'district_name' => $club->district?->name ?: $club->church?->district?->name,
                'latest' => $clubSubmissions
                    ->groupBy('form_type')
                    ->map(fn (Collection $items) => $items->first())
                    ->all(),
            ];
        })->values();

        return Inertia::render('Association/Forms', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
            ],
            'clubs' => $clubs->map(fn (Club $club) => [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'church_name' => $club->church?->church_name ?: $club->church_name,
                'district_name' => $club->district?->name ?: $club->church?->district?->name,
            ])->values(),
            'submissions' => $submissions,
            'latest_by_club' => $latestByClub,
            'form_types' => $formTypes,
        ]);
    }

    public function downloadForm(Request $request, string $formType, int $formId)
    {
        [$submission, $pathColumn, $nameColumn] = $this->resolveAssociationForm($request, $formType, $formId);

        $path = $submission->{$pathColumn};
        abort_unless($path && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->download($path, $submission->{$nameColumn} ?: basename($path));
    }

    public function showForm(Request $request, string $formType, int $formId)
    {
        [$submission, $pathColumn] = $this->resolveAssociationForm($request, $formType, $formId);
        $submission->load('club.church.district', 'club.district');
        if (in_array($formType, ['adventurer_yearly_application', 'pathfinder_annual_application'], true)) {
            $submission->load('signatures');
        }

        $definitions = $this->associationFormDefinitions();
        $definition = $definitions[$formType];

        return Inertia::render('Association/FormShow', [
            'form' => [
                'id' => $submission->id,
                'type' => $formType,
                'title_es' => $definition['title_es'],
                'title_en' => $definition['title_en'],
                'club' => [
                    'id' => $submission->club->id,
                    'name' => $submission->club->club_name,
                    'church_name' => $submission->club->church?->church_name ?: $submission->club->church_name,
                    'district_name' => $submission->club->district?->name ?: $submission->club->church?->district?->name,
                ],
                'submitted_at' => optional($this->associationFormSubmittedAt($submission, $formType))->toIso8601String(),
                'sections' => collect($definition['sections'])->map(fn (array $section) => [
                    'title_es' => $section['title_es'],
                    'title_en' => $section['title_en'],
                    'fields' => collect($section['fields'])->map(fn (array $field) => [
                        ...$field,
                        'value' => $submission->getAttribute($field['key']),
                    ])->values(),
                ])->values(),
                'signatures' => $this->associationFormSignatures($submission, $formType),
                'download_url' => $submission->{$pathColumn}
                    ? route('association.forms.download', ['formType' => $formType, 'formId' => $submission->id])
                    : null,
            ],
        ]);
    }

    protected function associationClubFormSubmissions(Club $club): Collection
    {
        $base = fn ($form, string $type, string $labelEs, string $labelEn, $submittedAt, string $period, ?string $status = null, ?string $path = null) => [
            'id' => $form->id,
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'club_type' => $club->club_type,
            'church_name' => $club->church?->church_name ?: $club->church_name,
            'district_name' => $club->district?->name ?: $club->church?->district?->name,
            'form_type' => $type,
            'form_label_es' => $labelEs,
            'form_label_en' => $labelEn,
            'submitted_at' => optional($submittedAt)->toIso8601String(),
            'period' => $period,
            'status' => $status,
            'view_url' => route('association.forms.show', ['formType' => $type, 'formId' => $form->id]),
            'download_url' => $path ? route('association.forms.download', ['formType' => $type, 'formId' => $form->id]) : null,
        ];

        $items = collect();

        if ($club->club_type === 'adventurers') {
            $items = $items
                ->concat($club->adventurerYearlyApplications->map(fn ($form) => $base(
                    $form,
                    'adventurer_yearly_application',
                    'Solicitud anual',
                    'Yearly application',
                    $form->created_at,
                    (string) $form->application_year,
                    $form->delivery_status,
                    $form->docx_path,
                )))
                ->concat($club->adventurerQuarterlyReports->map(fn ($form) => $base(
                    $form,
                    'adventurer_quarterly_report',
                    'Reporte trimestral',
                    'Quarterly report',
                    $form->submitted_at ?: $form->created_at,
                    $this->quarterlyPeriodLabel($form->reporting_period).' '.$form->reporting_year,
                    $form->submitted_on_time ? 'on_time' : 'late',
                    $form->docx_path,
                )))
                ->concat($club->adventurerInductionRequests->map(fn ($form) => $base(
                    $form,
                    'adventurer_induction_request',
                    'Solicitud de inducción',
                    'Induction request',
                    $form->received_at ?: $form->created_at,
                    optional($form->induction_date)->format('Y-m-d').' · '.$form->induction_place,
                    $form->status,
                    $form->docx_path,
                )));
        }

        if ($club->club_type === 'pathfinders') {
            $items = $items
                ->concat($club->pathfinderAnnualApplications->map(fn ($form) => $base(
                    $form,
                    'pathfinder_annual_application',
                    'Solicitud anual',
                    'Annual application',
                    $form->created_at,
                    (string) $form->application_year,
                    $form->delivery_status,
                    $form->pdf_path,
                )))
                ->concat($club->pathfinderMonthlyReports->map(fn ($form) => $base(
                    $form,
                    'pathfinder_monthly_report',
                    'Reporte mensual',
                    'Monthly report',
                    $form->created_at,
                    $form->report_month.' '.$form->report_year,
                    $form->delivery_status,
                    $form->pdf_path,
                )));
        }

        return $items->sortByDesc('submitted_at')->values();
    }

    protected function quarterlyPeriodLabel(?string $period): string
    {
        return [
            'sep_oct' => 'Sep–Oct',
            'nov_dec' => 'Nov–Dec',
            'jan_feb' => 'Jan–Feb',
            'mar_apr' => 'Mar–Apr',
        ][$period] ?? (string) $period;
    }

    protected function resolveAssociationForm(Request $request, string $formType, int $formId): array
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,evaluation_system');

        abort_unless(($association->union?->evaluation_system ?: 'honors') === 'honors', 404);

        $models = [
            'adventurer_yearly_application' => [AdventurerYearlyApplication::class, 'docx_path', 'docx_file_name'],
            'adventurer_quarterly_report' => [AdventurerQuarterlyReport::class, 'docx_path', 'docx_file_name'],
            'adventurer_induction_request' => [AdventurerInductionRequest::class, 'docx_path', 'docx_file_name'],
            'pathfinder_annual_application' => [PathfinderAnnualApplication::class, 'pdf_path', 'pdf_file_name'],
            'pathfinder_monthly_report' => [PathfinderMonthlyReport::class, 'pdf_path', 'pdf_file_name'],
        ];

        abort_unless(isset($models[$formType]), 404);
        [$modelClass, $pathColumn, $nameColumn] = $models[$formType];

        $submission = $modelClass::query()
            ->whereKey($formId)
            ->whereHas('club', function ($club) use ($association) {
                $club->where(function ($query) use ($association) {
                    $query
                        ->whereHas('district', fn ($district) => $district->where('association_id', $association->id))
                        ->orWhereHas('church.district', fn ($district) => $district->where('association_id', $association->id));
                });
            })
            ->firstOrFail();

        return [$submission, $pathColumn, $nameColumn];
    }

    protected function associationFormSubmittedAt($submission, string $formType)
    {
        return match ($formType) {
            'adventurer_quarterly_report' => $submission->submitted_at ?: $submission->created_at,
            'adventurer_induction_request' => $submission->received_at ?: $submission->created_at,
            default => $submission->created_at,
        };
    }

    protected function associationFormSignatures($submission, string $formType): array
    {
        if (! in_array($formType, ['adventurer_yearly_application', 'pathfinder_annual_application'], true)) {
            return [];
        }

        $roleLabels = [
            'director' => ['es' => 'Director del club', 'en' => 'Club director'],
            'pastor' => ['es' => 'Pastor de la iglesia', 'en' => 'Church pastor'],
            'head_elder' => ['es' => 'Primer anciano', 'en' => 'Head elder'],
            'church_clerk' => ['es' => 'Secretario de iglesia', 'en' => 'Church clerk'],
        ];

        return $submission->signatures
            ->sortBy(fn ($signature) => array_search($signature->role, ['director', 'pastor', 'head_elder', 'church_clerk'], true))
            ->map(fn ($signature) => [
                'id' => $signature->id,
                'role' => $signature->role,
                'role_es' => $roleLabels[$signature->role]['es'] ?? $signature->role,
                'role_en' => $roleLabels[$signature->role]['en'] ?? $signature->role,
                'signer_name' => $signature->signer_name,
                'signer_email' => $signature->signer_email,
                'status' => $signature->status,
                'signed_at' => optional($signature->signed_at)->toIso8601String(),
                'signature_type' => $signature->signature_type,
                'signature_text' => $signature->signature_text,
                'signature_url' => $signature->signature_url,
            ])
            ->values()
            ->all();
    }

    protected function associationFormDefinitions(): array
    {
        $field = fn (string $key, string $es, string $en, string $format = 'text') => compact('key', 'es', 'en', 'format');
        $section = fn (string $titleEs, string $titleEn, array $fields) => [
            'title_es' => $titleEs,
            'title_en' => $titleEn,
            'fields' => $fields,
        ];

        return [
            'adventurer_yearly_application' => [
                'title_es' => 'Solicitud anual de Aventureros',
                'title_en' => 'Adventurer Yearly Application',
                'sections' => [
                    $section('Solicitud', 'Application', [
                        $field('application_year', 'Año de solicitud', 'Application year'),
                        $field('application_date', 'Fecha de solicitud', 'Application date', 'date'),
                        $field('club_name', 'Nombre del club', 'Club name'),
                        $field('sponsoring_church', 'Iglesia patrocinadora', 'Sponsoring church'),
                    ]),
                    $section('Dirección del club', 'Club leadership', [
                        $field('pastor', 'Pastor', 'Pastor'),
                        $field('elected_club_director', 'Director electo', 'Elected club director'),
                        $field('email_address', 'Correo electrónico', 'Email address'),
                        $field('cell_number', 'Teléfono celular', 'Cell number'),
                        $field('home_address', 'Dirección postal', 'Home address'),
                    ]),
                    $section('Participación de la junta', 'Board participation', [
                        $field('other_board_members', 'Otros miembros de junta', 'Other board members', 'list'),
                    ]),
                    $section('Entrega', 'Delivery', [
                        $field('delivery_status', 'Estado', 'Status'),
                        $field('last_sent_to_email', 'Enviado a', 'Sent to'),
                        $field('sent_at', 'Fecha de envío', 'Sent at', 'datetime'),
                    ]),
                ],
            ],
            'adventurer_quarterly_report' => [
                'title_es' => 'Reporte trimestral de Aventureros',
                'title_en' => 'Adventurer Quarterly Report',
                'sections' => [
                    $section('Período y contacto', 'Period and contact', [
                        $field('reporting_year', 'Año', 'Year'),
                        $field('reporting_period', 'Período', 'Period'),
                        $field('due_date', 'Fecha límite', 'Due date', 'date'),
                        $field('submitted_at', 'Fecha de entrega', 'Submitted at', 'datetime'),
                        $field('submitted_on_time', 'Entregado a tiempo', 'Submitted on time', 'boolean'),
                        $field('club_name', 'Nombre del club', 'Club name'),
                        $field('director_name', 'Director', 'Director'),
                        $field('cell_number', 'Teléfono celular', 'Cell number'),
                        $field('email_address', 'Correo electrónico', 'Email address'),
                    ]),
                    $section('Membresía y personal', 'Membership and staff', [
                        $field('membership_boys', 'Niños', 'Boys'),
                        $field('membership_girls', 'Niñas', 'Girls'),
                        $field('membership_total', 'Total de miembros', 'Total members'),
                        $field('staff_males', 'Personal masculino', 'Male staff'),
                        $field('staff_females', 'Personal femenino', 'Female staff'),
                        $field('staff_total', 'Total de personal', 'Total staff'),
                    ]),
                    $section('Actividades', 'Activities', [
                        $field('meetings_held', 'Reuniones realizadas', 'Meetings held'),
                        $field('class_a_uniform_worn', 'Uniforme Clase A', 'Class A uniform worn', 'boolean'),
                        $field('attendance_percentage', 'Porcentaje de asistencia', 'Attendance percentage', 'percentage'),
                        $field('awards_taught', 'Especialidades enseñadas', 'Awards taught'),
                        $field('curriculum_taught', 'Currículo enseñado', 'Curriculum taught', 'boolean'),
                        $field('outreach_activity', 'Actividad misionera', 'Outreach activity', 'longtext'),
                        $field('staff_meetings_held', 'Reuniones de personal', 'Staff meetings held'),
                        $field('news_item', 'Noticias del club', 'Club news', 'longtext'),
                    ]),
                    $section('Puntuación', 'Points', [
                        $field('meetings_points', 'Reuniones', 'Meetings'),
                        $field('uniform_points', 'Uniforme', 'Uniform'),
                        $field('attendance_points', 'Asistencia', 'Attendance'),
                        $field('awards_points', 'Especialidades', 'Awards'),
                        $field('curriculum_points', 'Currículo', 'Curriculum'),
                        $field('outreach_points', 'Actividad misionera', 'Outreach'),
                        $field('staff_meetings_points', 'Reuniones de personal', 'Staff meetings'),
                        $field('promptness_points', 'Puntualidad', 'Promptness'),
                        $field('news_item_points', 'Noticias', 'News item'),
                        $field('total_points', 'Puntuación total', 'Total points'),
                    ]),
                ],
            ],
            'adventurer_induction_request' => [
                'title_es' => 'Solicitud de inducción de Aventureros',
                'title_en' => 'Adventurer Induction Request',
                'sections' => [
                    $section('Solicitud', 'Request', [
                        $field('requested_attendee', 'Persona solicitada', 'Requested attendee'),
                        $field('club_name', 'Nombre del club', 'Club name'),
                        $field('induction_date', 'Fecha de inducción', 'Induction date', 'date'),
                        $field('induction_time', 'Hora de inducción', 'Induction time'),
                        $field('induction_place', 'Lugar', 'Place'),
                        $field('directions', 'Direcciones', 'Directions', 'longtext'),
                    ]),
                    $section('Recepción', 'Receipt', [
                        $field('received_at', 'Recibido', 'Received at', 'datetime'),
                        $field('status', 'Estado', 'Status'),
                        $field('last_sent_to_email', 'Enviado a', 'Sent to'),
                        $field('emailed_at', 'Fecha de envío', 'Emailed at', 'datetime'),
                    ]),
                ],
            ],
            'pathfinder_annual_application' => [
                'title_es' => 'Solicitud anual de Conquistadores',
                'title_en' => 'Pathfinder Annual Application',
                'sections' => [
                    $section('Solicitud', 'Application', [
                        $field('application_year', 'Año de solicitud', 'Application year'),
                        $field('due_date', 'Fecha límite', 'Due date', 'date'),
                        $field('sponsoring_church', 'Iglesia patrocinadora', 'Sponsoring church'),
                        $field('pastor', 'Pastor', 'Pastor'),
                        $field('elected_club_director', 'Director electo', 'Elected club director'),
                    ]),
                    $section('Contacto', 'Contact', [
                        $field('mailing_address', 'Dirección postal', 'Mailing address'),
                        $field('director_phone_number', 'Teléfono del director', 'Director phone number'),
                        $field('home_phone', 'Teléfono residencial', 'Home phone'),
                        $field('cell_phone', 'Teléfono celular', 'Cell phone'),
                        $field('email_address', 'Correo electrónico', 'Email address'),
                    ]),
                    $section('Aprobación de la junta', 'Board approval', [
                        $field('board_approval_date', 'Aprobación de junta', 'Board approval date', 'date'),
                        $field('other_board_members', 'Otros miembros de junta', 'Other board members', 'list'),
                    ]),
                    $section('Entrega', 'Delivery', [
                        $field('delivery_status', 'Estado', 'Status'),
                        $field('last_sent_to_email', 'Enviado a', 'Sent to'),
                        $field('sent_at', 'Fecha de envío', 'Sent at', 'datetime'),
                    ]),
                ],
            ],
            'pathfinder_monthly_report' => [
                'title_es' => 'Reporte mensual de Conquistadores',
                'title_en' => 'Pathfinder Monthly Report',
                'sections' => [
                    $section('Reporte', 'Report', [
                        $field('report_year', 'Año', 'Year'),
                        $field('report_month', 'Mes', 'Month'),
                        $field('full_name', 'Nombre completo', 'Full name'),
                        $field('email', 'Correo electrónico', 'Email'),
                        $field('area', 'Área', 'Area'),
                        $field('church_and_club_name', 'Iglesia y club', 'Church and club'),
                    ]),
                    $section('Participación y actividades', 'Participation and activities', [
                        $field('pathfinders_count', 'Conquistadores', 'Pathfinders'),
                        $field('tlt_count', 'TLT', 'TLT'),
                        $field('staff_count', 'Personal', 'Staff'),
                        $field('meetings_count', 'Reuniones', 'Meetings'),
                        $field('bible_studies_count', 'Estudios bíblicos', 'Bible studies'),
                        $field('baptisms_count', 'Bautismos', 'Baptisms'),
                        $field('campouts_count', 'Campamentos', 'Campouts'),
                        $field('field_trips_count', 'Excursiones', 'Field trips'),
                        $field('honors_completed_count', 'Especialidades completadas', 'Honors completed'),
                        $field('honors_completed_list', 'Lista de especialidades', 'Honors list', 'longtext'),
                        $field('outreach_activities', 'Actividades misioneras', 'Outreach activities', 'longtext'),
                        $field('notable_activities', 'Actividades destacadas', 'Notable activities', 'longtext'),
                        $field('may_share_photos', 'Permiso para compartir fotos', 'May share photos', 'boolean'),
                    ]),
                    $section('Entrega', 'Delivery', [
                        $field('delivery_status', 'Estado', 'Status'),
                        $field('last_sent_to_email', 'Enviado a', 'Sent to'),
                        $field('sent_at', 'Fecha de envío', 'Sent at', 'datetime'),
                    ]),
                ],
            ],
        ];
    }

    public function storeHonorSession(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,evaluation_system');

        if (($association->union?->evaluation_system ?: 'honors') !== 'honors') {
            abort(422, 'Honor planning is only available when the union evaluation system is honors.');
        }

        $validated = $request->validate([
            'club_type' => ['required', 'string', 'max:255'],
            'class_name' => ['required', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'session_date' => ['required', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'status' => ['nullable', Rule::in(['planned', 'open', 'completed'])],
        ]);

        $association->honorClassSessions()->create([
            'club_type' => $validated['club_type'],
            'class_name' => $validated['class_name'],
            'title' => $validated['title'],
            'session_date' => $validated['session_date'],
            'location' => $validated['location'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'status' => $validated['status'] ?? 'planned',
        ]);

        return back()->with('success', 'Honor class session planned successfully.');
    }

    public function destroyHonorSession(Request $request, AssociationHonorClassSession $session)
    {
        $association = $this->resolveScopedAssociation($request);

        if ((int) $session->association_id !== (int) $association->id) {
            abort(403);
        }

        $session->delete();

        return back()->with('success', 'Honor class session removed.');
    }

    public function districtEvaluation(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system');

        $districts = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->withCount('churches')
            ->with(['churches' => fn ($query) => $query
                ->orderBy('church_name')
                ->withCount('clubs')
                ->select('id', 'district_id', 'church_name')])
            ->orderBy('name')
            ->get(['id', 'association_id', 'name', 'pastor_name', 'pastor_email', 'is_evaluator', 'status']);

        $evaluators = AssociationEvaluator::query()
            ->where('association_id', $association->id)
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'notes']);

        $churchOptions = Church::query()
            ->whereHas('district', fn ($query) => $query
                ->where('association_id', $association->id)
                ->where('status', '!=', 'deleted'))
            ->with(['district:id,name'])
            ->withCount('clubs')
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name']);

        return Inertia::render('Association/DistrictEvaluation', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $association->union?->evaluation_system ?: 'honors',
            ],
            'districts' => $districts->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'pastor_name' => $d->pastor_name,
                'pastor_email' => $d->pastor_email,
                'is_evaluator' => (bool) $d->is_evaluator,
                'status' => $d->status,
                'churches_count' => (int) ($d->churches_count ?? 0),
                'churches' => $d->churches->map(fn ($church) => [
                    'id' => $church->id,
                    'church_name' => $church->church_name,
                    'clubs_count' => (int) ($church->clubs_count ?? 0),
                ])->values(),
            ])->values(),
            'church_options' => $churchOptions->map(fn ($church) => [
                'id' => $church->id,
                'district_id' => $church->district_id,
                'district_name' => $church->district?->name,
                'church_name' => $church->church_name,
                'clubs_count' => (int) ($church->clubs_count ?? 0),
            ])->values(),
            'evaluators' => $evaluators->map(fn($e) => [
                'id' => $e->id,
                'name' => $e->name,
                'email' => $e->email,
                'notes' => $e->notes,
            ])->values(),
        ]);
    }

    public function workplan(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union.clubCatalogs');
        $year = (int) $request->input('year', now()->year);
        $publication = AssociationWorkplanPublication::query()
            ->where('association_id', $association->id)
            ->where('year', $year)
            ->first();
        $lastChangedAt = AssociationWorkplanEvent::query()
            ->where('association_id', $association->id)
            ->where('year', $year)
            ->max('updated_at');
        $requiresRepublish = $publication?->status === 'published'
            && $publication?->published_at
            && $lastChangedAt
            && strtotime((string) $lastChangedAt) > strtotime((string) $publication->published_at);

        return Inertia::render('Association/Workplan', [
            'association' => ['id' => $association->id, 'name' => $association->name],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
            ],
            'clubTypeOptions' => $this->workplanClubTypeOptions($association->union),
            'year' => $year,
            'events' => AssociationWorkplanEvent::query()
                ->where('association_id', $association->id)
                ->where('year', $year)
                ->where('status', 'active')
                ->orderBy('date')
                ->orderBy('start_time')
                ->get(),
            'publication' => $publication,
            'requiresRepublish' => $requiresRepublish,
        ]);
    }

    public function storeWorkplanEvent(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union.clubCatalogs');
        $validated = $this->validateWorkplanEvent($request, $association->union, requireYear: true);

        AssociationWorkplanEvent::query()->create([
            ...$validated,
            'association_id' => $association->id,
            'status' => 'active',
            'created_by' => $request->user()?->id,
        ]);

        return back()->with('success', 'Evento creado correctamente.');
    }

    public function updateWorkplanEvent(Request $request, AssociationWorkplanEvent $event)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union.clubCatalogs');
        $this->assertOwnsWorkplanEvent($association, $event);

        $event->update($this->validateWorkplanEvent($request, $association->union));

        return back()->with('success', 'Evento actualizado correctamente.');
    }

    public function destroyWorkplanEvent(Request $request, AssociationWorkplanEvent $event)
    {
        $association = $this->resolveScopedAssociation($request);
        $this->assertOwnsWorkplanEvent($association, $event);

        if (!empty($event->union_workplan_event_id)) {
            abort(422, 'Los eventos heredados de la union no se pueden eliminar desde la asociacion.');
        }

        $event->update(['status' => 'deleted']);

        return back()->with('success', 'Evento eliminado.');
    }

    public function publishWorkplan(Request $request, WorkplanPropagationService $propagationService)
    {
        $association = $this->resolveScopedAssociation($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->publishAssociation($association, (int) $validated['year'], $request->user());

        return back()->with('success', "Calendario publicado a {$result['clubs']} clubes.");
    }

    public function unpublishWorkplan(Request $request, WorkplanPropagationService $propagationService)
    {
        $association = $this->resolveScopedAssociation($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $result = $propagationService->unpublishAssociation($association, (int) $validated['year']);

        return back()->with('success', "Calendario despublicado. Se removieron {$result['club_events']} eventos de clubes.");
    }

    public function syncWorkplanMissing(Request $request, WorkplanPropagationService $propagationService)
    {
        $association = $this->resolveScopedAssociation($request);
        $validated = $request->validate([
            'year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $year = (int) $validated['year'];
        $publication = AssociationWorkplanPublication::query()
            ->where('association_id', $association->id)
            ->where('year', $year)
            ->first();

        if (($publication?->status ?? null) !== 'published') {
            abort(422, 'El calendario debe estar publicado antes de sincronizar eventos faltantes.');
        }

        $result = $propagationService->syncAssociationMissing($association, $year, $request->user());

        return back()->with(
            'success',
            "Sincronizacion completada. {$result['club_events_created']} eventos agregados en {$result['clubs']} clubes."
        );
    }

    public function storeDistrict(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'pastor_name' => ['nullable', 'string', 'max:255'],
            'pastor_email' => ['nullable', 'email', 'max:255'],
            'incoming_church_ids' => ['nullable', 'array'],
            'incoming_church_ids.*' => ['integer', 'distinct'],
        ]);

        $exists = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->whereRaw('lower(name) = ?', [mb_strtolower($validated['name'])])
            ->exists();

        if ($exists) {
            return back()->withErrors(['name' => 'A district with this name already exists.']);
        }

        $district = District::create([
            'association_id' => $association->id,
            'name' => $validated['name'],
            'pastor_name' => $validated['pastor_name'] ?? null,
            'pastor_email' => $validated['pastor_email'] ?? null,
            'status' => 'active',
        ]);

        $this->moveAssociationChurchesToDistrict(
            $association,
            $district,
            $validated['incoming_church_ids'] ?? []
        );

        $this->syncDistrictClubPastors($district);

        return back()->with('success', 'District created.');
    }

    public function updateDistrict(Request $request, District $district)
    {
        $association = $this->resolveScopedAssociation($request);

        if ((int) $district->association_id !== (int) $association->id) {
            abort(403);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'pastor_name' => ['nullable', 'string', 'max:255'],
            'pastor_email' => ['nullable', 'email', 'max:255'],
            'is_evaluator' => ['sometimes', 'boolean'],
            'incoming_church_ids' => ['nullable', 'array'],
            'incoming_church_ids.*' => ['integer', 'distinct'],
        ]);

        $district->update(collect($validated)->except('incoming_church_ids')->all());

        if (array_key_exists('incoming_church_ids', $validated)) {
            $this->moveAssociationChurchesToDistrict(
                $association,
                $district,
                $validated['incoming_church_ids'] ?? []
            );
        }

        if (
            array_key_exists('pastor_name', $validated)
            || array_key_exists('pastor_email', $validated)
            || array_key_exists('incoming_church_ids', $validated)
        ) {
            $this->syncDistrictClubPastors($district->fresh());
        }

        return back()->with('success', 'District updated.');
    }

    public function associationClubs(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,name,evaluation_system');

        $districts = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->orderBy('name')
            ->get(['id', 'name', 'pastor_name', 'status']);

        $districtIds = $districts->pluck('id')->toArray();

        $churches = Church::query()
            ->whereIn('district_id', $districtIds)
            ->orderBy('church_name')
            ->get(['id', 'district_id', 'church_name', 'pastor_name']);

        $clubs = Club::query()
            ->withoutGlobalScopes()
            ->whereIn('district_id', $districtIds)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'status', 'church_id', 'church_name', 'district_id', 'director_name', 'user_id', 'evaluation_system', 'creation_date']);

        $clubIds = $clubs->pluck('id')->toArray();

        $adventurerClubIds = $clubs->where('club_type', 'adventurers')->pluck('id')->toArray();
        $pathfinderClubIds = $clubs->whereIn('club_type', ['pathfinders', 'master_guide'])->pluck('id')->toArray();

        $adventurerMembers = MemberAdventurer::query()
            ->whereIn('club_id', $adventurerClubIds)
            ->where('status', 'active')
            ->get(['id', 'club_id', 'applicant_name', 'birthdate', 'age', 'email_address', 'cell_number', 'insurance_paid', 'insurance_paid_at'])
            ->groupBy('club_id');

        $pathfinderMembers = MemberPathfinder::query()
            ->whereIn('club_id', $pathfinderClubIds)
            ->where('status', 'active')
            ->get(['id', 'club_id', 'applicant_name', 'birthdate', 'email_address', 'cell_number', 'insurance_paid', 'insurance_paid_at'])
            ->groupBy('club_id');

        $evaluationSystem = $association->union?->evaluation_system ?: 'honors';

        $formatMember = fn($m) => [
            'id' => $m->id,
            'name' => $m->applicant_name,
            'birthdate' => $m->birthdate?->toDateString(),
            'age' => $m->age ?? ($m->birthdate ? (int) $m->birthdate->diffInYears(now()) : null),
            'email' => $m->email_address,
            'phone' => $m->cell_number,
            'insurance_paid' => (bool) $m->insurance_paid,
            'insurance_paid_at' => $m->insurance_paid_at?->toDateString(),
        ];
        $insuranceAmount = (float) ($association->insurance_payment_amount ?? 0);

        return Inertia::render('Association/Clubs', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
                'insurance_payment_amount' => $association->insurance_payment_amount,
            ],
            'union' => [
                'id' => $association->union?->id,
                'name' => $association->union?->name,
                'evaluation_system' => $evaluationSystem,
            ],
            'districts' => $districts->map(fn($d) => [
                'id' => $d->id,
                'name' => $d->name,
                'pastor_name' => $d->pastor_name,
            ])->values(),
            'churches' => $churches->map(fn($c) => [
                'id' => $c->id,
                'district_id' => $c->district_id,
                'church_name' => $c->church_name,
                'pastor_name' => $c->pastor_name,
            ])->values(),
            'clubs' => $clubs->map(function ($c) use ($adventurerMembers, $pathfinderMembers, $formatMember, $insuranceAmount) {
                $members = $c->club_type === 'adventurers'
                    ? ($adventurerMembers->get($c->id) ?? collect())
                    : ($pathfinderMembers->get($c->id) ?? collect());
                $activeMemberCount = $members->count();
                $insuredMemberCount = $members->filter(fn ($member) => (bool) $member->insurance_paid)->count();

                return [
                    'id' => $c->id,
                    'club_name' => $c->club_name,
                    'club_type' => $c->club_type,
                    'status' => $c->status,
                    'church_id' => $c->church_id,
                    'church_name' => $c->church_name,
                    'district_id' => $c->district_id,
                    'director_name' => $c->director_name,
                    'has_director' => (bool) $c->user_id,
                    'evaluation_system' => $c->evaluation_system,
                    'creation_date' => $c->creation_date,
                    'insurance_summary' => [
                        'member_count' => $activeMemberCount,
                        'insured_count' => $insuredMemberCount,
                        'expected_amount' => round($activeMemberCount * $insuranceAmount, 2),
                        'paid_amount' => round($insuredMemberCount * $insuranceAmount, 2),
                        'outstanding_amount' => round(max(($activeMemberCount - $insuredMemberCount), 0) * $insuranceAmount, 2),
                    ],
                    'members' => $members->map($formatMember)->values(),
                ];
            })->values(),
        ]);
    }

    protected function moveAssociationChurchesToDistrict(Association $association, District $district, array $churchIds): void
    {
        $churchIds = collect($churchIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($churchIds->isEmpty()) {
            return;
        }

        $churches = Church::query()
            ->whereIn('id', $churchIds)
            ->whereHas('district', fn ($query) => $query
                ->where('association_id', $association->id)
                ->where('status', '!=', 'deleted'))
            ->get(['id', 'district_id', 'church_name']);

        if ($churches->count() !== $churchIds->count()) {
            abort(422, 'One or more selected churches do not belong to this association.');
        }

        foreach ($churches as $church) {
            if ((int) $church->district_id === (int) $district->id) {
                continue;
            }

            $church->update([
                'district_id' => $district->id,
                'conference' => $association->name,
                'pastor_name' => null,
                'pastor_email' => null,
            ]);

            Club::query()
                ->withoutGlobalScopes()
                ->where('church_id', $church->id)
                ->update([
                    'district_id' => $district->id,
                    'church_name' => $church->church_name,
                    'pastor_name' => $district->pastor_name,
                    'conference_name' => $association->name,
                ]);
        }
    }

    protected function syncDistrictClubPastors(District $district): void
    {
        Club::query()
            ->withoutGlobalScopes()
            ->where('district_id', $district->id)
            ->update([
                'pastor_name' => $district->pastor_name,
                'conference_name' => $district->association?->name,
            ]);
    }

    public function storeAssociationClub(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $association->load('union:id,evaluation_system');

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->where('status', '!=', 'deleted')
            ->pluck('id')->toArray();

        $churchIds = Church::query()
            ->whereIn('district_id', $districtIds)
            ->pluck('id')->toArray();

        $validated = $request->validate([
            'church_id'     => ['required', 'integer', Rule::in($churchIds)],
            'club_name'     => ['required', 'string', 'max:255'],
            'club_type'     => ['required', Rule::in(['adventurers', 'pathfinders', 'master_guide'])],
            'creation_date' => ['nullable', 'date'],
        ]);

        $church = Church::findOrFail($validated['church_id']);
        $church->loadMissing('district.association.union:id,evaluation_system');

        $district = $church->district;

        // Enforce one club per type per church
        $duplicate = Club::query()
            ->withoutGlobalScopes()
            ->where('church_id', $church->id)
            ->where('club_type', $validated['club_type'])
            ->exists();

        if ($duplicate) {
            return back()->withErrors(['club_type' => 'This church already has a club of this type.']);
        }

        Club::create([
            'club_name'       => $validated['club_name'],
            'club_type'       => $validated['club_type'],
            'creation_date'   => $validated['creation_date'] ?? null,
            'church_id'       => $church->id,
            'church_name'     => $church->church_name,
            'district_id'     => $district?->id,
            'pastor_name'     => $church->pastor_name,
            'conference_name' => $association->name,
            'evaluation_system' => $district?->association?->union?->evaluation_system ?: 'honors',
            'status'          => 'inactive',
            'user_id'         => null,
            'director_name'   => null,
        ]);
        $this->syncInsurancePaymentConcepts($association, $request->user()?->id);

        return back()->with('success', 'Club created. Assign a director to activate it.');
    }

    public function storeClubDirector(Request $request, int $club)
    {
        $club = Club::withoutGlobalScopes()->findOrFail($club);
        $association = $this->resolveScopedAssociation($request);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->pluck('id')->toArray();

        if (!in_array((int) $club->district_id, $districtIds)) {
            abort(403);
        }

        if ($club->user_id) {
            return back()->withErrors(['email' => 'This club already has a director assigned.']);
        }

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8'],
        ]);

        $church = Church::find($club->church_id);

        $director = User::create([
            'name'        => $validated['name'],
            'email'       => $validated['email'],
            'password'    => Hash::make($validated['password']),
            'profile_type' => 'club_director',
            'scope_type'  => 'club',
            'scope_id'    => $club->id,
            'club_id'     => $club->id,
            'church_id'   => $church?->id,
            'church_name' => $church?->church_name,
            'status'      => 'active',
        ]);

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $director->id, 'club_id' => $club->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $club->update([
            'user_id'      => $director->id,
            'director_name' => $director->name,
            'status'       => 'active',
        ]);

        return back()->with('success', 'Director created and club activated.');
    }

    public function toggleMemberInsurance(Request $request, int $clubId, int $memberId)
    {
        $association = $this->resolveScopedAssociation($request);

        $club = Club::withoutGlobalScopes()->findOrFail($clubId);

        $districtIds = District::query()
            ->where('association_id', $association->id)
            ->pluck('id')->toArray();

        if (!in_array((int) $club->district_id, $districtIds)) {
            abort(403);
        }

        if ($club->club_type === 'adventurers') {
            $member = MemberAdventurer::where('club_id', $club->id)->findOrFail($memberId);
        } else {
            $member = MemberPathfinder::where('club_id', $club->id)->findOrFail($memberId);
        }

        $nowPaid = !$member->insurance_paid;
        $member->update([
            'insurance_paid' => $nowPaid,
            'insurance_paid_at' => $nowPaid ? now() : null,
        ]);

        return back()->with('success', 'Insurance status updated.');
    }

    public function associationSettings(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);
        $bankInfo = BankInfo::query()
            ->where('bankable_type', Association::class)
            ->where('bankable_id', $association->id)
            ->where('pay_to', 'association_budget')
            ->first();

        return Inertia::render('Association/Settings', [
            'association' => [
                'id' => $association->id,
                'name' => $association->name,
                'insurance_payment_amount' => $association->insurance_payment_amount,
                'bank_info' => BankInfoFormatter::payload($bankInfo),
            ],
        ]);
    }

    public function updateAssociationSettings(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $validated = $request->validate([
            'insurance_payment_amount' => ['nullable', 'numeric', 'min:0', 'max:9999.99'],
            'bank_info' => ['nullable', 'array'],
            'bank_info.label' => ['nullable', 'string', 'max:255'],
            'bank_info.bank_name' => ['nullable', 'string', 'max:255'],
            'bank_info.account_holder' => ['nullable', 'string', 'max:255'],
            'bank_info.account_type' => ['nullable', 'string', 'max:80'],
            'bank_info.account_number' => ['nullable', 'string', 'max:255'],
            'bank_info.routing_number' => ['nullable', 'string', 'max:255'],
            'bank_info.zelle_email' => ['nullable', 'email', 'max:255'],
            'bank_info.zelle_phone' => ['nullable', 'string', 'max:40'],
            'bank_info.deposit_instructions' => ['nullable', 'string', 'max:2000'],
            'bank_info.is_active' => ['boolean'],
            'bank_info.accepts_event_deposits' => ['boolean'],
            'bank_info.requires_receipt_upload' => ['boolean'],
        ]);

        $association->update([
            'insurance_payment_amount' => $validated['insurance_payment_amount'] ?? null,
        ]);

        if (array_key_exists('bank_info', $validated)) {
            $bankInfo = $validated['bank_info'] ?? [];
            BankInfo::query()->updateOrCreate(
                [
                    'bankable_type' => Association::class,
                    'bankable_id' => $association->id,
                    'pay_to' => 'association_budget',
                ],
                [
                    ...$bankInfo,
                    'pay_to' => 'association_budget',
                    'label' => $bankInfo['label'] ?? 'Presupuesto de asociación',
                    'accepts_parent_deposits' => false,
                    'accepts_event_deposits' => $bankInfo['accepts_event_deposits'] ?? true,
                    'requires_receipt_upload' => $bankInfo['requires_receipt_upload'] ?? true,
                    'is_active' => $bankInfo['is_active'] ?? true,
                ],
            );
        }

        $this->syncInsurancePaymentConcepts($association->fresh(), $request->user()?->id);

        return back()->with('success', 'Settings saved.');
    }

    public function syncInsuranceConcepts(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $this->syncInsurancePaymentConcepts($association, $request->user()?->id);

        return back()->with('success', 'Concepto de seguro sincronizado en todos los clubes.');
    }

    public function churches(Request $request)
    {
        return redirect()->route('association.districts');
    }

    public function storeChurch(Request $request)
    {
        abort(422, 'Las iglesias ahora se administran desde el portal distrital.');
    }

    public function updateChurch(Request $request, Church $church)
    {
        abort(422, 'Las iglesias ahora se administran desde el portal distrital.');
    }

    public function destroyChurch(Request $request, Church $church)
    {
        abort(422, 'Las iglesias ahora se administran desde el portal distrital.');
    }

    public function storeEvaluator(Request $request)
    {
        $association = $this->resolveScopedAssociation($request);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        AssociationEvaluator::create([
            'association_id' => $association->id,
            'name' => $validated['name'],
            'email' => $validated['email'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ]);

        return back()->with('success', 'Evaluator added.');
    }

    public function destroyEvaluator(Request $request, AssociationEvaluator $evaluator)
    {
        $association = $this->resolveScopedAssociation($request);

        if ((int) $evaluator->association_id !== (int) $association->id) {
            abort(403);
        }

        $evaluator->delete();

        return back()->with('success', 'Evaluator removed.');
    }

    protected function resolveScopedAssociation(Request $request): Association
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();

            if (($context['role'] ?? null) !== 'association_youth_director' || empty($context['association_id'])) {
                abort(403);
            }

            return Association::query()->findOrFail((int) $context['association_id']);
        }

        if ($user->profile_type !== 'association_youth_director' || $user->scope_type !== 'association' || empty($user->scope_id)) {
            abort(403);
        }

        return Association::query()->findOrFail((int) $user->scope_id);
    }

    protected function buildAssociationRequirementCatalog($carpetaYear): array
    {
        $requirements = collect($carpetaYear?->requirements ?? [])
            ->where('status', 'active');

        return collect($this->carpetaClubTypeOptions())->map(function (array $option) use ($requirements) {
            $items = $requirements
                ->filter(fn ($requirement) => $this->normalizeCarpetaClubType($requirement->club_type) === $option['value'])
                ->sortBy([
                    fn ($requirement) => $this->normalizeCarpetaValue($requirement->class_name),
                    fn ($requirement) => $requirement->sort_order ?? 9999,
                    fn ($requirement) => $this->normalizeCarpetaValue($requirement->title),
                ])
                ->values();

            return [
                'club_type' => $option['value'],
                'club_type_label' => $option['label'],
                'requirements_count' => $items->count(),
                'class_groups' => $items
                    ->groupBy(fn ($requirement) => $requirement->class_name ?: 'Sin clase')
                    ->map(function (Collection $group, string $className) {
                        return [
                            'class_name' => $className,
                            'requirements_count' => $group->count(),
                            'requirements' => $group->map(fn ($requirement) => [
                                'id' => $requirement->id,
                                'title' => $requirement->title,
                                'description' => $requirement->description,
                                'requirement_type' => $requirement->requirement_type,
                                'validation_mode' => $requirement->validation_mode,
                                'sort_order' => $requirement->sort_order,
                                'status' => $requirement->status,
                            ])->values(),
                        ];
                    })
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    protected function computeAssociationCarpetaClubProgress(Collection $clubs, int $yearId): array
    {
        $clubRows = $clubs
            ->filter(fn ($club) => $club->status !== 'deleted')
            ->values();

        if ($clubRows->isEmpty()) {
            return collect($this->carpetaClubTypeOptions())->map(fn ($option) => [
                'club_type' => $option['value'],
                'club_type_label' => $option['label'],
                'clubs' => [],
            ])->values()->all();
        }

        $clubIds = $clubRows->pluck('id')->all();
        $pathfinderClubIds = $clubRows
            ->filter(fn ($club) => in_array($this->normalizeCarpetaClubType($club->club_type), ['pathfinders', 'master_guide'], true))
            ->pluck('id')
            ->all();
        $adventurerClubIds = $clubRows
            ->filter(fn ($club) => $this->normalizeCarpetaClubType($club->club_type) === 'adventurers')
            ->pluck('id')
            ->all();

        $pathAssignments = collect();
        if (!empty($pathfinderClubIds)) {
            $pathAssignments = DB::table('class_member_pathfinder as cmp')
                ->join('members as m', 'm.id', '=', 'cmp.member_id')
                ->join('club_classes as cc', 'cc.id', '=', 'cmp.club_class_id')
                ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
                ->whereIn('m.club_id', $pathfinderClubIds)
                ->where('cmp.active', true)
                ->where('m.status', 'active')
                ->select('m.id as member_id', 'm.club_id')
                ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
                ->get();
        }

        $advAssignments = collect();
        if (!empty($adventurerClubIds)) {
            $advAssignmentsRaw = DB::table('class_member_adventurer as cma')
                ->join('club_classes as cc', 'cc.id', '=', 'cma.club_class_id')
                ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
                ->whereIn('cc.club_id', $adventurerClubIds)
                ->where('cma.active', true)
                ->select('cma.members_adventurer_id', 'cc.club_id')
                ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
                ->get();

            $advMemberMap = Member::query()
                ->where('type', 'adventurers')
                ->whereIn('id_data', $advAssignmentsRaw->pluck('members_adventurer_id')->unique()->toArray())
                ->whereIn('club_id', $adventurerClubIds)
                ->where('status', 'active')
                ->get(['id', 'id_data', 'club_id'])
                ->keyBy('id_data');

            $advAssignments = $advAssignmentsRaw->map(function ($assignment) use ($advMemberMap) {
                $member = $advMemberMap->get($assignment->members_adventurer_id);

                return $member
                    ? (object) [
                        'member_id' => $member->id,
                        'club_id' => $assignment->club_id,
                        'class_name' => $assignment->class_name,
                    ]
                    : null;
            })->filter()->values();
        }

        $requirements = UnionCarpetaRequirement::query()
            ->where('union_carpeta_year_id', $yearId)
            ->where('status', 'active')
            ->get(['id', 'club_type', 'class_name']);

        $reqMap = [];
        foreach ($requirements as $requirement) {
            $key = $this->normalizeCarpetaClubType($requirement->club_type) . '|' . $this->normalizeCarpetaValue($requirement->class_name);
            $reqMap[$key][] = (int) $requirement->id;
        }

        $allAssignments = $pathAssignments->concat($advAssignments);
        $allMemberIds = $allAssignments->pluck('member_id')->unique()->values()->toArray();
        $allRequirementIds = $requirements->pluck('id')->map(fn ($id) => (int) $id)->all();

        $evidencesByMember = collect();
        if (!empty($allMemberIds) && !empty($allRequirementIds)) {
            $evidencesByMember = DB::table('parent_carpeta_requirement_evidences')
                ->whereIn('member_id', $allMemberIds)
                ->whereIn('union_carpeta_requirement_id', $allRequirementIds)
                ->where(function ($query) {
                    $query->whereNotNull('file_path')
                        ->orWhereNotNull('text_value')
                        ->orWhere('physical_completed', true);
                })
                ->select('member_id', 'union_carpeta_requirement_id')
                ->distinct()
                ->get()
                ->groupBy('member_id')
                ->map(fn ($group) => $group->pluck('union_carpeta_requirement_id')->map(fn ($id) => (int) $id)->toArray());
        }

        $memberProgressByClub = [];
        foreach ($allAssignments as $assignment) {
            $club = $clubRows->firstWhere('id', $assignment->club_id);
            if (!$club) {
                continue;
            }

            $key = $this->normalizeCarpetaClubType($club->club_type) . '|' . $assignment->class_name;
            $requirementIds = $reqMap[$key] ?? [];
            $requirementCount = count($requirementIds);
            $pct = $requirementCount === 0
                ? null
                : round(count(array_intersect($requirementIds, $evidencesByMember->get($assignment->member_id, []))) / $requirementCount * 100, 1);

            $memberProgressByClub[(int) $assignment->club_id][] = $pct;
        }

        $clubProgress = $clubRows->map(function ($club) use ($memberProgressByClub) {
            $progressValues = array_filter($memberProgressByClub[(int) $club->id] ?? [], fn ($value) => $value !== null);

            return [
                'id' => $club->id,
                'club_name' => $club->club_name,
                'club_type' => $this->normalizeCarpetaClubType($club->club_type),
                'church_name' => $club->church_name,
                'district_name' => $club->church?->district?->name,
                'director_name' => $club->director_name,
                'status' => $club->status,
                'member_count' => count($memberProgressByClub[(int) $club->id] ?? []),
                'progress_pct' => count($progressValues) > 0 ? round(array_sum($progressValues) / count($progressValues), 1) : null,
            ];
        });

        return collect($this->carpetaClubTypeOptions())->map(function (array $option) use ($clubProgress) {
            return [
                'club_type' => $option['value'],
                'club_type_label' => $option['label'],
                'clubs' => $clubProgress
                    ->where('club_type', $option['value'])
                    ->sortBy('club_name')
                    ->values()
                    ->all(),
            ];
        })->values()->all();
    }

    protected function carpetaClubTypeOptions(): array
    {
        return [
            ['value' => 'adventurers', 'label' => 'Aventureros'],
            ['value' => 'pathfinders', 'label' => 'Conquistadores'],
            ['value' => 'master_guide', 'label' => 'Guias Mayores'],
        ];
    }

    protected function normalizeCarpetaValue(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function normalizeCarpetaClubType(?string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $this->normalizeCarpetaValue($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
            'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
            'master guide', 'master guides', 'guia mayor', 'guias mayores', 'guías mayores', 'guia mayores' => 'master_guide',
            default => $normalized,
        };
    }

    protected function validateWorkplanEvent(Request $request, ?Union $union, bool $requireYear = false): array
    {
        return $request->validate([
            'year' => [$requireYear ? 'required' : 'sometimes', 'integer', 'min:2000', 'max:2100'],
            'date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'event_type' => ['required', Rule::in(['general', 'program'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'location' => ['nullable', 'string', 'max:255'],
            'target_club_types' => ['nullable', 'array'],
            'target_club_types.*' => ['string', Rule::in($this->workplanAllowedClubTypes($union))],
            'is_mandatory' => ['boolean'],
        ]);
    }

    protected function workplanAllowedClubTypes(?Union $union): array
    {
        if (! $union?->relationLoaded('clubCatalogs')) {
            $union?->load('clubCatalogs');
        }

        return $union?->clubCatalogs
            ? $union->clubCatalogs
                ->where('status', 'active')
                ->pluck('club_type')
                ->filter()
                ->values()
                ->all()
            : [];
    }

    protected function workplanClubTypeOptions(?Union $union): array
    {
        if (! $union?->relationLoaded('clubCatalogs')) {
            $union?->load('clubCatalogs');
        }

        return $union?->clubCatalogs
            ? $union->clubCatalogs
                ->where('status', 'active')
                ->map(fn ($catalog) => [
                    'value' => $catalog->club_type,
                    'label' => $catalog->name ?: $catalog->club_type,
                    'sort_order' => $catalog->sort_order,
                ])
                ->sortBy([
                    ['sort_order', 'asc'],
                    ['label', 'asc'],
                ])
                ->values()
                ->all()
            : [];
    }

    protected function assertOwnsWorkplanEvent(Association $association, AssociationWorkplanEvent $event): void
    {
        if ((int) $event->association_id !== (int) $association->id) {
            abort(403);
        }
    }
}
