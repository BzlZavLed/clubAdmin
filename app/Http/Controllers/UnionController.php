<?php

namespace App\Http\Controllers;

use App\Models\Association;
use App\Models\Club;
use App\Models\District;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\RepAssistanceAdv;
use App\Models\ParentCarpetaRequirementEvidence;
use App\Models\UnionCarpetaYear;
use App\Models\UnionCarpetaRequirement;
use App\Models\ClubTypeCatalog;
use App\Models\UnionClubCatalog;
use App\Models\UnionClassCatalog;
use App\Models\Union;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Support\SuperadminContext;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UnionController extends Controller
{
    public function index()
    {
        return Inertia::render('SuperAdmin/Unions', [
            'unions' => Union::query()
                ->withCount('associations')
                ->orderBy('name')
                ->get(['id', 'name', 'evaluation_system', 'status']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('unions', 'name')->where(fn ($query) => $query->where('status', '!=', 'deleted')),
            ],
            'evaluation_system' => ['required', Rule::in(['honors', 'carpetas'])],
        ]);

        Union::create([
            'name' => $validated['name'],
            'evaluation_system' => $validated['evaluation_system'],
            'status' => 'active',
        ]);

        return back()->with('success', 'Union creada correctamente.');
    }

    public function update(Request $request, Union $union)
    {
        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('unions', 'name')
                    ->ignore($union->id)
                    ->where(fn ($query) => $query->where('status', '!=', 'deleted')),
            ],
            'evaluation_system' => ['required', Rule::in(['honors', 'carpetas'])],
        ]);

        $union->update([
            'name' => $validated['name'],
            'evaluation_system' => $validated['evaluation_system'],
        ]);

        return back()->with('success', 'Union actualizada correctamente.');
    }

    public function builder(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        return Inertia::render('Union/CarpetaBuilder', [
            'union' => [
                'id' => $union->id,
                'name' => $union->name,
                'evaluation_system' => $union->evaluation_system,
                'status' => $union->status,
            ],
            'years' => $union->carpetaYears()
                ->with(['requirements' => fn ($query) => $query->select(
                    'id',
                    'union_carpeta_year_id',
                    'title',
                    'description',
                    'club_type',
                    'class_name',
                    'requirement_type',
                    'validation_mode',
                    'allowed_evidence_types',
                    'evidence_instructions',
                    'sort_order',
                    'status'
                )])
                ->get(['id', 'union_id', 'year', 'status', 'published_at', 'created_at', 'updated_at']),
            'clubCatalogs' => $this->catalogPayload($union),
        ]);
    }

    public function catalog(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        return Inertia::render('Union/ClubCatalog', [
            'union' => [
                'id' => $union->id,
                'name' => $union->name,
                'evaluation_system' => $union->evaluation_system,
                'status' => $union->status,
            ],
            'clubCatalogs' => $this->catalogPayload($union),
            'clubTypeOptions' => $this->clubTypeOptions(),
        ]);
    }

    public function storeClubCatalog(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        $validated = $request->validate([
            'club_type' => [
                'required',
                'string',
                Rule::exists('club_type_catalogs', 'code')->where(fn ($query) => $query->where('status', 'active')),
                Rule::unique('union_club_catalogs', 'club_type')->where(fn ($query) => $query->where('union_id', $union->id)),
            ],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $clubType = ClubTypeCatalog::query()
            ->where('code', $validated['club_type'])
            ->where('status', 'active')
            ->firstOrFail();

        $union->clubCatalogs()->create([
            'name' => $clubType->name,
            'club_type' => $clubType->code,
            'sort_order' => $validated['sort_order'] ?? ((int) $union->clubCatalogs()->max('sort_order') + 1),
            'status' => 'active',
        ]);

        return back()->with('success', 'Tipo de club agregado al catálogo.');
    }

    public function storeClassCatalog(Request $request, UnionClubCatalog $clubCatalog)
    {
        $union = $this->resolveScopedUnion($request);
        $this->assertOwnsClubCatalog($union, $clubCatalog);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('union_class_catalogs', 'name')->where(fn ($query) => $query->where('union_club_catalog_id', $clubCatalog->id)),
            ],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $clubCatalog->classCatalogs()->create([
            'name' => $validated['name'],
            'sort_order' => $validated['sort_order'] ?? ((int) $clubCatalog->classCatalogs()->max('sort_order') + 1),
            'status' => 'active',
        ]);

        return back()->with('success', 'Clase agregada al catálogo.');
    }

    public function updateScopedEvaluationSystem(Request $request)
    {
        $union = $this->resolveScopedUnion($request);
        $validated = $request->validate([
            'evaluation_system' => ['required', Rule::in(['honors', 'carpetas'])],
        ]);

        $union->update([
            'evaluation_system' => $validated['evaluation_system'],
        ]);

        return back()->with('success', 'Sistema de evaluación actualizado.');
    }

    public function storeCarpetaYear(Request $request)
    {
        $union = $this->resolveScopedUnion($request);
        if ($union->evaluation_system !== 'carpetas') {
            abort(422, 'Switch the union to carpetas before creating annual carpeta cycles.');
        }

        $validated = $request->validate([
            'year' => [
                'required',
                'integer',
                'min:2000',
                'max:2100',
                Rule::unique('union_carpeta_years', 'year')->where(fn ($query) => $query->where('union_id', $union->id)),
            ],
        ]);

        $year = $union->carpetaYears()->create([
            'year' => (int) $validated['year'],
            'status' => 'draft',
        ]);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Ciclo anual de carpeta creado.',
                'year' => $year->load('requirements'),
            ]);
        }

        return back()->with('success', 'Ciclo anual de carpeta creado.');
    }

    public function storeCarpetaRequirement(Request $request, UnionCarpetaYear $carpetaYear)
    {
        $union = $this->resolveScopedUnion($request);
        $this->assertOwnsCarpetaYear($union, $carpetaYear);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'club_catalog_id' => ['required', 'integer'],
            'class_catalog_id' => ['required', 'integer'],
            'requirement_type' => ['required', Rule::in(['speciality', 'event', 'class', 'presentation', 'other'])],
            'validation_mode' => ['required', Rule::in(['electronic', 'physical', 'hybrid'])],
            'allowed_evidence_types' => ['nullable', 'array'],
            'allowed_evidence_types.*' => ['string', Rule::in(['photo', 'file', 'text', 'video_link', 'external_link', 'physical_only'])],
            'evidence_instructions' => ['nullable', 'string'],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $clubCatalog = $union->clubCatalogs()
            ->where('id', (int) $validated['club_catalog_id'])
            ->first();
        if (!$clubCatalog) {
            abort(422, 'Selected club catalog does not belong to this union.');
        }

        $classCatalog = $clubCatalog->classCatalogs()
            ->where('id', (int) $validated['class_catalog_id'])
            ->first();
        if (!$classCatalog) {
            abort(422, 'Selected class catalog does not belong to the selected club catalog.');
        }

        $requirement = $carpetaYear->requirements()->create([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'club_type' => $clubCatalog->club_type ?: $clubCatalog->name,
            'class_name' => $classCatalog->name,
            'requirement_type' => $validated['requirement_type'],
            'validation_mode' => $validated['validation_mode'],
            'allowed_evidence_types' => array_values($validated['allowed_evidence_types'] ?? []),
            'evidence_instructions' => $validated['evidence_instructions'] ?? null,
            'sort_order' => $validated['sort_order'] ?? ((int) $carpetaYear->requirements()->max('sort_order') + 1),
            'status' => 'active',
        ]);

        return response()->json([
            'message' => 'Requisito de carpeta creado.',
            'requirement' => $requirement,
        ], 201);
    }

    public function destroyCarpetaRequirement(Request $request, UnionCarpetaRequirement $requirement)
    {
        $union = $this->resolveScopedUnion($request);
        $requirement->load('carpetaYear');

        $this->assertOwnsCarpetaYear($union, $requirement->carpetaYear);

        $evidenceCount = ParentCarpetaRequirementEvidence::query()
            ->where('union_carpeta_requirement_id', $requirement->id)
            ->count();

        if ($evidenceCount > 0) {
            return response()->json([
                'message' => 'No se puede eliminar este requisito porque ya tiene evidencias registradas por clubes o miembros.',
                'evidence_count' => $evidenceCount,
            ], 422);
        }

        $requirement->delete();

        return response()->json([
            'message' => 'Requisito eliminado correctamente.',
        ]);
    }

    public function publishCarpetaYear(Request $request, UnionCarpetaYear $carpetaYear)
    {
        $union = $this->resolveScopedUnion($request);
        $this->assertOwnsCarpetaYear($union, $carpetaYear);

        if ($union->evaluation_system !== 'carpetas') {
            abort(422, 'Only carpeta unions can publish carpeta cycles.');
        }

        $carpetaYear->update([
            'status' => 'published',
            'published_at' => now(),
        ]);

        return back()->with('success', 'Ciclo anual publicado.');
    }

    public function archiveCarpetaYear(Request $request, UnionCarpetaYear $carpetaYear)
    {
        $union = $this->resolveScopedUnion($request);
        $this->assertOwnsCarpetaYear($union, $carpetaYear);

        $carpetaYear->update([
            'status' => 'archived',
        ]);

        return back()->with('success', 'Ciclo anual archivado.');
    }

    public function deactivate(Union $union)
    {
        $union->update(['status' => 'inactive']);

        return back()->with('success', 'Union desactivada correctamente.');
    }

    public function destroy(Union $union)
    {
        $union->update(['status' => 'deleted']);

        return back()->with('success', 'Union eliminada correctamente.');
    }

    protected function resolveScopedUnion(Request $request): Union
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        if ($user->profile_type === 'superadmin') {
            $context = SuperadminContext::fromSession();

            if (($context['role'] ?? null) !== 'union_youth_director' || empty($context['union_id'])) {
                abort(403);
            }

            return Union::query()->findOrFail((int) $context['union_id']);
        }

        if ($user->profile_type !== 'union_youth_director' || $user->scope_type !== 'union' || empty($user->scope_id)) {
            abort(403);
        }

        return Union::query()->findOrFail((int) $user->scope_id);
    }

    protected function assertOwnsCarpetaYear(Union $union, UnionCarpetaYear $carpetaYear): void
    {
        if ((int) $carpetaYear->union_id !== (int) $union->id) {
            abort(403);
        }
    }

    protected function assertOwnsClubCatalog(Union $union, UnionClubCatalog $clubCatalog): void
    {
        if ((int) $clubCatalog->union_id !== (int) $union->id) {
            abort(403);
        }
    }

    // ─── Attendance Report ───────────────────────────────────────────────────

    public function attendanceReport(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        $month    = (int) $request->input('month', now()->month);
        $year     = (int) $request->input('year', now()->year);
        $level    = $request->input('level', 'union');

        $payload = [
            'union'            => ['id' => $union->id, 'name' => $union->name],
            'month'            => $month,
            'year'             => $year,
            'level'            => $level,
            'breadcrumb'       => [],
            'rows'             => [],
            'sessions'         => [],
            'current_entity'   => null,
            'parent_entity'    => null,
            'grandparent_entity' => null,
        ];

        switch ($level) {
            case 'club':
                $clubId     = (int) $request->input('club_id');
                $districtId = (int) $request->input('district_id');
                $assocId    = (int) $request->input('association_id');

                $club = Club::withoutGlobalScopes()
                    ->whereHas('district.association', fn($q) => $q->where('union_id', $union->id))
                    ->findOrFail($clubId);

                $payload['current_entity']    = ['id' => $club->id, 'name' => $club->club_name, 'club_type' => $club->club_type];
                $payload['parent_entity']     = $districtId ? District::find($districtId, ['id', 'name']) : null;
                $payload['grandparent_entity']= $assocId    ? Association::find($assocId, ['id', 'name']) : null;
                $payload['sessions']          = $this->computeClubSessions($club->id, $month, $year);
                $payload['breadcrumb']        = $this->buildAttendanceBreadcrumb($union, $payload['grandparent_entity'], $payload['parent_entity'], $club, $level);
                break;

            case 'district':
                $districtId = (int) $request->input('district_id');
                $assocId    = (int) $request->input('association_id');

                $district    = District::whereHas('association', fn($q) => $q->where('union_id', $union->id))->findOrFail($districtId);
                $association = $assocId ? Association::find($assocId, ['id', 'name']) : $district->association;

                $clubIds = Club::withoutGlobalScopes()->where('district_id', $district->id)->pluck('id')->toArray();

                $payload['current_entity'] = ['id' => $district->id, 'name' => $district->name];
                $payload['parent_entity']  = $association ? ['id' => $association->id, 'name' => $association->name] : null;
                $payload['rows']           = $this->computeClubAttendance($clubIds, $month, $year);
                $payload['breadcrumb']     = $this->buildAttendanceBreadcrumb($union, $association, $district, null, $level);
                break;

            case 'association':
                $assocId = (int) $request->input('association_id');

                $association = Association::where('union_id', $union->id)->findOrFail($assocId);
                $districtIds = District::where('association_id', $association->id)->pluck('id')->toArray();
                $districts   = District::whereIn('id', $districtIds)->get(['id', 'name']);

                $clubsByDistrict = Club::withoutGlobalScopes()
                    ->whereIn('district_id', $districtIds)
                    ->get(['id', 'district_id', 'club_type'])
                    ->groupBy('district_id');

                $allClubIds = Club::withoutGlobalScopes()->whereIn('district_id', $districtIds)->pluck('id')->toArray();
                $clubData   = collect($this->computeClubAttendance($allClubIds, $month, $year))->keyBy('club_id');

                $rows = $districts->map(function ($d) use ($clubsByDistrict, $clubData) {
                    $dClubIds   = ($clubsByDistrict->get($d->id) ?? collect())->pluck('id');
                    $dClubStats = $dClubIds->map(fn($id) => $clubData->get($id))->filter();
                    $withSessions = $dClubStats->filter(fn($c) => $c['session_count'] > 0);
                    $avg = $withSessions->count() > 0 ? $withSessions->avg('avg_attendance_pct') : null;
                    return [
                        'id'              => $d->id,
                        'name'            => $d->name,
                        'total_clubs'     => $dClubIds->count(),
                        'clubs_reporting' => $withSessions->count(),
                        'avg_attendance_pct' => $avg !== null ? round($avg, 1) : null,
                    ];
                })->values()->all();

                $payload['current_entity'] = ['id' => $association->id, 'name' => $association->name];
                $payload['rows']           = $rows;
                $payload['breadcrumb']     = $this->buildAttendanceBreadcrumb($union, $association, null, null, $level);
                break;

            default: // union
                $associations   = Association::where('union_id', $union->id)->get(['id', 'name']);
                $allDistrictIds = District::whereIn('association_id', $associations->pluck('id')->toArray())->get(['id', 'association_id']);
                $allClubsFlat   = Club::withoutGlobalScopes()
                    ->whereIn('district_id', $allDistrictIds->pluck('id')->toArray())
                    ->get(['id', 'district_id', 'club_type']);

                $distToAssoc = $allDistrictIds->keyBy('id');
                $clubData    = collect($this->computeClubAttendance($allClubsFlat->pluck('id')->toArray(), $month, $year))->keyBy('club_id');

                $clubsByAssoc = $allClubsFlat->groupBy(fn($c) => $distToAssoc->get($c->district_id)?->association_id);

                $rows = $associations->map(function ($a) use ($clubsByAssoc, $clubData) {
                    $aClubIds   = ($clubsByAssoc->get($a->id) ?? collect())->pluck('id');
                    $aClubStats = $aClubIds->map(fn($id) => $clubData->get($id))->filter();
                    $withSessions = $aClubStats->filter(fn($c) => $c['session_count'] > 0);
                    $avg = $withSessions->count() > 0 ? $withSessions->avg('avg_attendance_pct') : null;
                    return [
                        'id'              => $a->id,
                        'name'            => $a->name,
                        'total_clubs'     => $aClubIds->count(),
                        'clubs_reporting' => $withSessions->count(),
                        'avg_attendance_pct' => $avg !== null ? round($avg, 1) : null,
                    ];
                })->values()->all();

                $payload['rows']       = $rows;
                $payload['breadcrumb'] = $this->buildAttendanceBreadcrumb($union, null, null, null, $level);
                break;
        }

        return Inertia::render('Union/AttendanceReport', $payload);
    }

    public function attendanceReportCsv(Request $request): StreamedResponse
    {
        $union  = $this->resolveScopedUnion($request);
        $month  = (int) $request->input('month', now()->month);
        $year   = (int) $request->input('year', now()->year);
        $level  = $request->input('level', 'union');

        $headers = [];
        $rows    = [];

        switch ($level) {
            case 'club':
                $sessions = $this->computeClubSessions((int) $request->input('club_id'), $month, $year);
                $headers  = ['Fecha', 'Inscritos', 'Presentes', 'Asistencia %'];
                $rows     = array_map(fn($s) => [$s['date'], $s['enrolled'], $s['present'], $s['attendance_pct'] !== null ? $s['attendance_pct'] . '%' : '—'], $sessions);
                break;

            case 'district':
                $clubIds = Club::withoutGlobalScopes()->where('district_id', (int) $request->input('district_id'))->pluck('id')->toArray();
                $data    = $this->computeClubAttendance($clubIds, $month, $year);
                $headers = ['Club', 'Tipo', 'Inscritos', 'Sesiones', 'Asistencia Promedio %'];
                $rows    = array_map(fn($c) => [$c['club_name'], $c['club_type'], $c['enrolled'], $c['session_count'], $c['avg_attendance_pct'] !== null ? $c['avg_attendance_pct'] . '%' : '—'], $data);
                break;

            case 'association':
                $districtIds = District::where('association_id', (int) $request->input('association_id'))->pluck('id')->toArray();
                $clubIds     = Club::withoutGlobalScopes()->whereIn('district_id', $districtIds)->pluck('id')->toArray();
                $clubData    = collect($this->computeClubAttendance($clubIds, $month, $year));
                $districts   = District::whereIn('id', $districtIds)->get(['id', 'name'])->keyBy('id');
                $clubsByDistrict = Club::withoutGlobalScopes()->whereIn('district_id', $districtIds)->get(['id', 'district_id'])->groupBy('district_id');
                $headers = ['Distrito', 'Clubes totales', 'Clubes con reporte', 'Asistencia Promedio %'];
                $rows = $districts->map(function ($d) use ($clubsByDistrict, $clubData) {
                    $ids   = ($clubsByDistrict->get($d->id) ?? collect())->pluck('id');
                    $stats = $clubData->filter(fn($c) => $ids->contains($c['club_id']));
                    $w     = $stats->filter(fn($c) => $c['session_count'] > 0);
                    return [$d->name, $ids->count(), $w->count(), $w->count() > 0 ? round($w->avg('avg_attendance_pct'), 1) . '%' : '—'];
                })->values()->all();
                break;

            default:
                $assocs   = Association::where('union_id', $union->id)->get(['id', 'name']);
                $distIds  = District::whereIn('association_id', $assocs->pluck('id')->toArray())->get(['id', 'association_id']);
                $allClubs = Club::withoutGlobalScopes()->whereIn('district_id', $distIds->pluck('id')->toArray())->get(['id', 'district_id']);
                $clubData = collect($this->computeClubAttendance($allClubs->pluck('id')->toArray(), $month, $year));
                $distToAssoc = $distIds->keyBy('id');
                $clubsByAssoc = $allClubs->groupBy(fn($c) => $distToAssoc->get($c->district_id)?->association_id);
                $headers = ['Asociación', 'Clubes totales', 'Clubes con reporte', 'Asistencia Promedio %'];
                $rows = $assocs->map(function ($a) use ($clubsByAssoc, $clubData) {
                    $ids   = ($clubsByAssoc->get($a->id) ?? collect())->pluck('id');
                    $stats = $clubData->filter(fn($c) => $ids->contains($c['club_id']));
                    $w     = $stats->filter(fn($c) => $c['session_count'] > 0);
                    return [$a->name, $ids->count(), $w->count(), $w->count() > 0 ? round($w->avg('avg_attendance_pct'), 1) . '%' : '—'];
                })->values()->all();
                break;
        }

        $filename = "asistencia-{$level}-{$year}-{$month}.csv";
        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            if ($headers) fputcsv($out, $headers);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    protected function computeClubAttendance(array $clubIds, int $month, int $year): array
    {
        if (empty($clubIds)) return [];

        $clubs = Club::withoutGlobalScopes()->whereIn('id', $clubIds)->get(['id', 'club_name', 'club_type', 'district_id']);

        // Sessions for the month
        $sessions = DB::table('rep_assistance_adv')
            ->whereIn('club_id', $clubIds)
            ->where('month', $month)
            ->where('year', $year)
            ->select('id', 'club_id')
            ->get();

        $sessionIds        = $sessions->pluck('id')->toArray();
        $sessionCountByClub = $sessions->groupBy('club_id')->map(fn($g) => $g->count());

        // Present counts per session
        $presentBySession = collect();
        if (!empty($sessionIds)) {
            $presentBySession = DB::table('rep_assistance_adv_merits')
                ->whereIn('report_id', $sessionIds)
                ->where('asistencia', true)
                ->select('report_id', DB::raw('COUNT(*) as present_count'))
                ->groupBy('report_id')
                ->pluck('present_count', 'report_id');
        }

        // Enrolled members per club (active adventurers)
        $enrolledByClub = MemberAdventurer::whereIn('club_id', $clubIds)
            ->where('status', 'active')
            ->select('club_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('club_id')
            ->pluck('cnt', 'club_id');

        // Build per-session rates grouped by club
        $ratesByClub = [];
        foreach ($sessions as $s) {
            $enrolled = (int) ($enrolledByClub->get($s->club_id) ?? 0);
            if ($enrolled === 0) continue;
            $present = (int) ($presentBySession->get($s->id) ?? 0);
            $ratesByClub[$s->club_id][] = round($present / $enrolled * 100, 1);
        }

        return $clubs->map(function ($c) use ($sessionCountByClub, $enrolledByClub, $ratesByClub) {
            $sessionCount = (int) ($sessionCountByClub->get($c->id) ?? 0);
            $enrolled     = (int) ($enrolledByClub->get($c->id) ?? 0);
            $rates        = $ratesByClub[$c->id] ?? [];
            $avg          = count($rates) > 0 ? round(array_sum($rates) / count($rates), 1) : null;
            return [
                'club_id'            => $c->id,
                'club_name'          => $c->club_name,
                'club_type'          => $c->club_type,
                'district_id'        => $c->district_id,
                'enrolled'           => $enrolled,
                'session_count'      => $sessionCount,
                'avg_attendance_pct' => $avg,
            ];
        })->values()->all();
    }

    protected function computeClubSessions(int $clubId, int $month, int $year): array
    {
        $sessions = DB::table('rep_assistance_adv')
            ->where('club_id', $clubId)
            ->where('month', $month)
            ->where('year', $year)
            ->select('id', 'date')
            ->orderBy('date')
            ->get();

        if ($sessions->isEmpty()) return [];

        $enrolled = (int) MemberAdventurer::where('club_id', $clubId)->where('status', 'active')->count();

        $presentBySession = DB::table('rep_assistance_adv_merits')
            ->whereIn('report_id', $sessions->pluck('id')->toArray())
            ->where('asistencia', true)
            ->select('report_id', DB::raw('COUNT(*) as present_count'))
            ->groupBy('report_id')
            ->pluck('present_count', 'report_id');

        return $sessions->map(function ($s) use ($enrolled, $presentBySession) {
            $present = (int) ($presentBySession->get($s->id) ?? 0);
            return [
                'date'           => $s->date,
                'enrolled'       => $enrolled,
                'present'        => $present,
                'attendance_pct' => $enrolled > 0 ? round($present / $enrolled * 100, 1) : null,
            ];
        })->values()->all();
    }

    protected function buildAttendanceBreadcrumb(Union $union, $association, $district, $club, string $level): array
    {
        $crumbs = [['label' => $union->name, 'level' => 'union', 'params' => []]];
        if ($association) {
            $assoc = is_array($association) ? $association : ['id' => $association->id, 'name' => $association->name];
            $crumbs[] = ['label' => $assoc['name'], 'level' => 'association', 'params' => ['association_id' => $assoc['id']]];
        }
        if ($district) {
            $dist = is_array($district) ? $district : ['id' => $district->id, 'name' => $district->name];
            $crumbs[] = ['label' => $dist['name'], 'level' => 'district', 'params' => ['district_id' => $dist['id'], 'association_id' => (is_array($association) ? $association['id'] : $association?->id)]];
        }
        if ($club) {
            $crumbs[] = ['label' => $club->club_name ?? $club['club_name'] ?? '', 'level' => 'club', 'params' => []];
        }
        return $crumbs;
    }

    // ─── Progress Report ─────────────────────────────────────────────────────

    public function progressReport(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        $level      = $request->input('level', 'union');
        $clubType   = $request->input('club_type');

        $year = UnionCarpetaYear::where('union_id', $union->id)
            ->where('status', 'published')
            ->orderByDesc('year')->orderByDesc('id')
            ->first(['id', 'year']);

        $payload = [
            'union'               => ['id' => $union->id, 'name' => $union->name],
            'level'               => $level,
            'club_type_filter'    => $clubType,
            'carpeta_year'        => $year ? ['id' => $year->id, 'year' => $year->year] : null,
            'breadcrumb'          => [],
            'rows'                => [],
            'members'             => [],
            'current_entity'      => null,
            'parent_entity'       => null,
            'grandparent_entity'  => null,
            'requirements_report' => [],
        ];

        if (!$year) {
            return Inertia::render('Union/ProgressReport', $payload);
        }

        switch ($level) {
            case 'club':
                $clubId = (int) $request->input('club_id');
                $districtId = (int) $request->input('district_id');
                $assocId = (int) $request->input('association_id');

                $club = Club::withoutGlobalScopes()
                    ->whereHas('district.association', fn($q) => $q->where('union_id', $union->id))
                    ->findOrFail($clubId);

                $payload['current_entity']      = ['id' => $club->id, 'name' => $club->club_name, 'club_type' => $club->club_type];
                $payload['parent_entity']       = $districtId ? District::find($districtId, ['id', 'name']) : null;
                $payload['grandparent_entity']  = $assocId ? Association::find($assocId, ['id', 'name']) : null;
                $payload['members']             = $this->computeMemberProgress($club, $year->id);
                $payload['breadcrumb']          = $this->buildBreadcrumb($union, $payload['grandparent_entity'], $payload['parent_entity'], $club, $level);
                $payload['requirements_report'] = $this->computeRequirementsReport([$club->id], $year->id, $club->club_type);
                break;

            case 'district':
                $districtId = (int) $request->input('district_id');
                $assocId    = (int) $request->input('association_id');

                $district = District::whereHas('association', fn($q) => $q->where('union_id', $union->id))
                    ->findOrFail($districtId);
                $association = $assocId ? Association::find($assocId, ['id', 'name']) : $district->association;

                $clubIds = Club::withoutGlobalScopes()
                    ->where('district_id', $district->id)
                    ->pluck('id')->toArray();

                $payload['current_entity']      = ['id' => $district->id, 'name' => $district->name];
                $payload['parent_entity']       = $association ? ['id' => $association->id, 'name' => $association->name] : null;
                $payload['rows']                = $this->computeClubProgress($clubIds, $year->id, $clubType);
                $payload['breadcrumb']          = $this->buildBreadcrumb($union, $association, $district, null, $level);
                $payload['requirements_report'] = $this->computeRequirementsReport($clubIds, $year->id, $clubType);
                break;

            case 'association':
                $assocId = (int) $request->input('association_id');

                $association = Association::where('union_id', $union->id)->findOrFail($assocId);

                $districtIds = District::where('association_id', $association->id)->pluck('id')->toArray();
                $districts   = District::whereIn('id', $districtIds)->get(['id', 'name']);

                $allClubIds = Club::withoutGlobalScopes()
                    ->whereIn('district_id', $districtIds)
                    ->get(['id', 'district_id', 'club_type'])
                    ->when($clubType, fn($c) => $c->where('club_type', $clubType));

                $clubProgressByDistrict = $this->computeClubProgress(
                    $allClubIds->pluck('id')->toArray(), $year->id, $clubType
                );
                $clubMap = collect($clubProgressByDistrict)->groupBy('district_id');

                $rows = $districts->map(function ($d) use ($clubMap) {
                    $dClubs = $clubMap->get($d->id, collect());
                    $withMembers = $dClubs->filter(fn($c) => $c['member_count'] > 0);
                    return [
                        'id'           => $d->id,
                        'name'         => $d->name,
                        'total_clubs'  => $dClubs->count(),
                        'total_members'=> $dClubs->sum('member_count'),
                        'progress_pct' => $withMembers->count() > 0
                            ? ($withMembers->avg('progress_pct') !== null ? round($withMembers->avg('progress_pct'), 1) : null)
                            : null,
                    ];
                })->values()->all();

                $payload['current_entity']      = ['id' => $association->id, 'name' => $association->name];
                $payload['rows']                = $rows;
                $payload['breadcrumb']          = $this->buildBreadcrumb($union, $association, null, null, $level);
                $payload['requirements_report'] = $this->computeRequirementsReport($allClubIds->pluck('id')->toArray(), $year->id, $clubType);
                break;

            default: // union level
                $associations = Association::where('union_id', $union->id)->get(['id', 'name']);

                $allDistrictIds = District::whereIn('association_id', $associations->pluck('id')->toArray())->pluck('id')->toArray();
                $allClubsFlat   = Club::withoutGlobalScopes()
                    ->whereIn('district_id', $allDistrictIds)
                    ->get(['id', 'district_id', 'club_type']);

                $districtToAssoc = District::whereIn('id', $allDistrictIds)
                    ->get(['id', 'association_id'])
                    ->keyBy('id');

                $clubProgress = $this->computeClubProgress(
                    $allClubsFlat->pluck('id')->toArray(), $year->id, $clubType
                );
                $clubsByAssoc = collect($clubProgress)->groupBy(function ($c) use ($districtToAssoc) {
                    return $districtToAssoc->get($c['district_id'])?->association_id;
                });

                $rows = $associations->map(function ($a) use ($clubsByAssoc) {
                    $aClubs = $clubsByAssoc->get($a->id, collect());
                    $withMembers = $aClubs->filter(fn($c) => $c['member_count'] > 0);
                    return [
                        'id'           => $a->id,
                        'name'         => $a->name,
                        'total_clubs'  => $aClubs->count(),
                        'total_members'=> $aClubs->sum('member_count'),
                        'progress_pct' => $withMembers->count() > 0
                            ? ($withMembers->avg('progress_pct') !== null ? round($withMembers->avg('progress_pct'), 1) : null)
                            : null,
                    ];
                })->values()->all();

                $payload['rows']                = $rows;
                $payload['breadcrumb']          = $this->buildBreadcrumb($union, null, null, null, $level);
                $payload['requirements_report'] = $this->computeRequirementsReport($allClubsFlat->pluck('id')->toArray(), $year->id, $clubType);
                break;
        }

        return Inertia::render('Union/ProgressReport', $payload);
    }

    public function progressReportCsv(Request $request): StreamedResponse
    {
        $union     = $this->resolveScopedUnion($request);
        $level     = $request->input('level', 'union');
        $clubType  = $request->input('club_type');

        $year = UnionCarpetaYear::where('union_id', $union->id)
            ->where('status', 'published')
            ->orderByDesc('year')->orderByDesc('id')
            ->first(['id', 'year']);

        $headers = [];
        $rows    = [];

        if ($year) {
            switch ($level) {
                case 'club':
                    $club    = Club::withoutGlobalScopes()->findOrFail((int) $request->input('club_id'));
                    $members = $this->computeMemberProgress($club, $year->id);
                    $headers = ['Member', 'Class', 'Fulfilled', 'Total Requirements', 'Progress %'];
                    $rows    = array_map(fn($m) => [
                        $m['name'], $m['class_name'], $m['fulfilled'], $m['total'],
                        $m['progress_pct'] !== null ? $m['progress_pct'] . '%' : '—',
                    ], $members);
                    break;

                case 'district':
                    $clubIds = Club::withoutGlobalScopes()
                        ->where('district_id', (int) $request->input('district_id'))
                        ->pluck('id')->toArray();
                    $data    = $this->computeClubProgress($clubIds, $year->id, $clubType);
                    $headers = ['Club', 'Type', 'Church', 'Members', 'Progress %'];
                    $rows    = array_map(fn($c) => [
                        $c['club_name'], $c['club_type'], $c['church_name'], $c['member_count'],
                        $c['progress_pct'] !== null ? $c['progress_pct'] . '%' : '—',
                    ], $data);
                    break;

                case 'association':
                    $districtIds = District::where('association_id', (int) $request->input('association_id'))->pluck('id')->toArray();
                    $clubIds     = Club::withoutGlobalScopes()->whereIn('district_id', $districtIds)->pluck('id')->toArray();
                    $clubData    = collect($this->computeClubProgress($clubIds, $year->id, $clubType));
                    $districts   = District::whereIn('id', $districtIds)->get(['id', 'name'])->keyBy('id');
                    $headers     = ['District', 'Total Clubs', 'Total Members', 'Progress %'];
                    $byDistrict  = $clubData->groupBy('district_id');
                    $rows = $districts->map(function($d) use ($byDistrict) {
                        $dClubs = $byDistrict->get($d->id, collect());
                        $w = $dClubs->filter(fn($c) => $c['member_count'] > 0);
                        return [
                            $d->name, $dClubs->count(), $dClubs->sum('member_count'),
                            $w->count() > 0 ? round($w->avg('progress_pct'), 1) . '%' : '—',
                        ];
                    })->values()->all();
                    break;

                default:
                    $assocs     = Association::where('union_id', $union->id)->get(['id', 'name']);
                    $distIds    = District::whereIn('association_id', $assocs->pluck('id')->toArray())->get(['id', 'association_id']);
                    $clubIds    = Club::withoutGlobalScopes()->whereIn('district_id', $distIds->pluck('id')->toArray())->pluck('id')->toArray();
                    $clubData   = collect($this->computeClubProgress($clubIds, $year->id, $clubType));
                    $distToAssoc = $distIds->keyBy('id');
                    $byAssoc    = $clubData->groupBy(fn($c) => $distToAssoc->get($c['district_id'])?->association_id);
                    $headers    = ['Association', 'Total Clubs', 'Total Members', 'Progress %'];
                    $rows = $assocs->map(function($a) use ($byAssoc) {
                        $ac = $byAssoc->get($a->id, collect());
                        $w  = $ac->filter(fn($c) => $c['member_count'] > 0);
                        return [
                            $a->name, $ac->count(), $ac->sum('member_count'),
                            $w->count() > 0 ? round($w->avg('progress_pct'), 1) . '%' : '—',
                        ];
                    })->values()->all();
                    break;
            }
        }

        $filename = "progress-report-{$level}-" . now()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($headers, $rows) {
            $out = fopen('php://output', 'w');
            if ($headers) fputcsv($out, $headers);
            foreach ($rows as $row) fputcsv($out, $row);
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    // ─── Progress Helpers ──────────────────────────────────────────────────

    protected function computeClubProgress(array $clubIds, int $yearId, ?string $clubTypeFilter = null): array
    {
        if (empty($clubIds)) return [];

        $clubQuery = Club::withoutGlobalScopes()->whereIn('id', $clubIds);
        if ($clubTypeFilter) $clubQuery->where('club_type', $clubTypeFilter);
        $clubs = $clubQuery->get(['id', 'club_name', 'club_type', 'church_name', 'district_id']);

        if ($clubs->isEmpty()) return [];
        $filteredIds = $clubs->pluck('id')->toArray();

        $requirements = UnionCarpetaRequirement::where('union_carpeta_year_id', $yearId)
            ->where('status', 'active')
            ->get(['id', 'club_type', 'class_name']);

        $reqMap = [];
        foreach ($requirements as $r) {
            $key = $this->normalizeCarpetaClubType($r->club_type) . '|' . $this->normalizeCarpetaValue($r->class_name);
            $reqMap[$key][] = (int) $r->id;
        }

        // Pathfinder assignments
        $pathAssignments = DB::table('class_member_pathfinder as cmp')
            ->join('members as m', 'm.id', '=', 'cmp.member_id')
            ->join('club_classes as cc', 'cc.id', '=', 'cmp.club_class_id')
            ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
            ->whereIn('m.club_id', $filteredIds)
            ->where('cmp.active', true)
            ->where('m.status', 'active')
            ->whereIn('m.type', ['pathfinders', 'temp_pathfinder'])
            ->select('m.id as member_id', 'm.club_id')
            ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
            ->get();

        // Adventurer assignments (via id_data)
        $advAssignmentsRaw = DB::table('class_member_adventurer as cma')
            ->join('club_classes as cc', 'cc.id', '=', 'cma.club_class_id')
            ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
            ->whereIn('cc.club_id', $filteredIds)
            ->where('cma.active', true)
            ->select('cma.members_adventurer_id', 'cc.club_id')
            ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
            ->get();

        $advMemberMap = Member::where('type', 'adventurers')
            ->whereIn('id_data', $advAssignmentsRaw->pluck('members_adventurer_id')->unique()->toArray())
            ->whereIn('club_id', $filteredIds)
            ->where('status', 'active')
            ->get(['id', 'id_data', 'club_id'])
            ->keyBy('id_data');

        $advAssignments = $advAssignmentsRaw->map(function ($a) use ($advMemberMap) {
            $m = $advMemberMap->get($a->members_adventurer_id);
            return $m ? (object)['member_id' => $m->id, 'club_id' => $a->club_id, 'class_name' => $a->class_name] : null;
        })->filter()->values();

        $allAssignments = $pathAssignments->concat($advAssignments);
        $allMemberIds   = $allAssignments->pluck('member_id')->unique()->values()->toArray();

        $allReqIds = $requirements->pluck('id')->toArray();
        $evidencesByMember = collect();
        if (!empty($allMemberIds)) {
            $evidencesByMember = DB::table('parent_carpeta_requirement_evidences')
                ->whereIn('member_id', $allMemberIds)
                ->whereIn('union_carpeta_requirement_id', $allReqIds)
                ->where(function ($q) {
                    $q->whereNotNull('file_path')
                      ->orWhereNotNull('text_value')
                      ->orWhere('physical_completed', true);
                })
                ->select('member_id', 'union_carpeta_requirement_id')
                ->distinct()
                ->get()
                ->groupBy('member_id')
                ->map(fn($g) => $g->pluck('union_carpeta_requirement_id')->toArray());
        }

        $memberProgressByClub = [];
        foreach ($allAssignments as $a) {
            $club = $clubs->firstWhere('id', $a->club_id);
            if (!$club) continue;
            $key     = $this->normalizeCarpetaClubType($club->club_type) . '|' . $a->class_name;
            $reqIds  = $reqMap[$key] ?? [];
            $reqCount = count($reqIds);

            $pct = $reqCount === 0 ? null : (function () use ($reqIds, $evidencesByMember, $a) {
                $fulfilled = count(array_intersect($reqIds, $evidencesByMember->get($a->member_id, [])));
                return round($fulfilled / count($reqIds) * 100, 1);
            })();

            $memberProgressByClub[(int) $a->club_id][] = $pct;
        }

        return $clubs->map(function ($c) use ($memberProgressByClub) {
            $pcts    = array_filter($memberProgressByClub[(int) $c->id] ?? [], fn($v) => $v !== null);
            $mCount  = count($memberProgressByClub[(int) $c->id] ?? []);
            return [
                'id'           => $c->id,
                'club_name'    => $c->club_name,
                'club_type'    => $c->club_type,
                'church_name'  => $c->church_name,
                'district_id'  => $c->district_id,
                'member_count' => $mCount,
                'progress_pct' => count($pcts) > 0 ? round(array_sum($pcts) / count($pcts), 1) : null,
            ];
        })->values()->all();
    }

    protected function computeMemberProgress(Club $club, int $yearId): array
    {
        $requirements = UnionCarpetaRequirement::where('union_carpeta_year_id', $yearId)
            ->where('status', 'active')
            ->get(['id', 'club_type', 'class_name', 'title'])
            ->filter(fn($r) => $this->normalizeCarpetaClubType($r->club_type) === $this->normalizeCarpetaClubType($club->club_type));

        $reqByClass = $requirements->groupBy(fn($r) => $this->normalizeCarpetaValue($r->class_name));
        $reqMap     = $reqByClass->map(fn($g) => $g->pluck('id')->map(fn($id) => (int)$id)->toArray());
        $allReqIds  = $requirements->pluck('id')->toArray();

        if (in_array($club->club_type, ['pathfinders', 'master_guide'])) {
            $assignments = DB::table('class_member_pathfinder as cmp')
                ->join('members as m', 'm.id', '=', 'cmp.member_id')
                ->join('club_classes as cc', 'cc.id', '=', 'cmp.club_class_id')
                ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
                ->where('m.club_id', $club->id)
                ->where('cmp.active', true)
                ->where('m.status', 'active')
                ->select('m.id as member_id', 'm.id_data')
                ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
                ->get();

            $memberIds  = $assignments->pluck('member_id')->unique()->toArray();
            $idDataList = $assignments->pluck('id_data')->filter()->unique()->toArray();
            $nameMap    = MemberPathfinder::whereIn('id', $idDataList)
                ->get(['id', 'applicant_name'])
                ->keyBy('id')
                ->map(fn($r) => $r->applicant_name);

            $memberIdDataMap = $assignments->keyBy('member_id')->map(fn($a) => $a->id_data);
        } else {
            $rawAssign = DB::table('class_member_adventurer as cma')
                ->join('club_classes as cc', 'cc.id', '=', 'cma.club_class_id')
                ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
                ->where('cc.club_id', $club->id)
                ->where('cma.active', true)
                ->select('cma.members_adventurer_id')
                ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
                ->get();

            $advIds = $rawAssign->pluck('members_adventurer_id')->unique()->toArray();
            $advMemberMap = Member::where('type', 'adventurers')
                ->whereIn('id_data', $advIds)
                ->where('club_id', $club->id)
                ->where('status', 'active')
                ->get(['id', 'id_data'])
                ->keyBy('id_data');

            $assignments = $rawAssign->map(function ($a) use ($advMemberMap) {
                $m = $advMemberMap->get($a->members_adventurer_id);
                return $m ? (object)['member_id' => $m->id, 'id_data' => $a->members_adventurer_id, 'class_name' => $a->class_name] : null;
            })->filter()->values();

            $memberIds = $assignments->pluck('member_id')->unique()->toArray();
            $nameMap = MemberAdventurer::whereIn('id', $assignments->pluck('id_data')->unique()->toArray())
                ->get(['id', 'applicant_name'])
                ->keyBy('id')
                ->map(fn($r) => $r->applicant_name);
            $memberIdDataMap = $assignments->keyBy('member_id')->map(fn($a) => $a->id_data);
        }

        $evidencesByMember = collect();
        if (!empty($memberIds)) {
            $evidencesByMember = DB::table('parent_carpeta_requirement_evidences')
                ->whereIn('member_id', $memberIds)
                ->whereIn('union_carpeta_requirement_id', $allReqIds)
                ->where(function ($q) {
                    $q->whereNotNull('file_path')
                      ->orWhereNotNull('text_value')
                      ->orWhere('physical_completed', true);
                })
                ->select('member_id', 'union_carpeta_requirement_id')
                ->distinct()
                ->get()
                ->groupBy('member_id')
                ->map(fn($g) => $g->pluck('union_carpeta_requirement_id')->map(fn($id) => (int)$id)->toArray());
        }

        return $assignments->map(function ($a) use ($reqMap, $evidencesByMember, $nameMap, $memberIdDataMap) {
            $classReqIds = $reqMap->get($a->class_name, []);
            $total       = count($classReqIds);
            $fulfilled   = $total > 0
                ? count(array_intersect($classReqIds, $evidencesByMember->get($a->member_id, [])))
                : 0;

            return [
                'id'          => $a->member_id,
                'name'        => $nameMap->get($memberIdDataMap->get($a->member_id)) ?? '—',
                'class_name'  => ucwords($a->class_name),
                'fulfilled'   => $fulfilled,
                'total'       => $total,
                'progress_pct'=> $total > 0 ? round($fulfilled / $total * 100, 1) : null,
            ];
        })->values()->all();
    }

    protected function computeRequirementsReport(array $clubIds, int $yearId, ?string $clubTypeFilter = null): array
    {
        if (empty($clubIds)) return [];

        $clubs = Club::withoutGlobalScopes()->whereIn('id', $clubIds)->get(['id', 'club_type']);
        $clubTypeMap = $clubs->keyBy('id')->map(fn($c) => $this->normalizeCarpetaClubType($c->club_type));

        $filteredIds = $clubTypeFilter
            ? $clubs->filter(fn($c) => $this->normalizeCarpetaClubType($c->club_type) === $this->normalizeCarpetaClubType($clubTypeFilter))->pluck('id')->toArray()
            : $clubIds;

        if (empty($filteredIds)) return [];

        $requirements = UnionCarpetaRequirement::where('union_carpeta_year_id', $yearId)
            ->where('status', 'active')
            ->when($clubTypeFilter, fn($q) => $q->where('club_type', $clubTypeFilter))
            ->orderByRaw("club_type, class_name, sort_order")
            ->get(['id', 'club_type', 'class_name', 'title', 'sort_order']);

        if ($requirements->isEmpty()) return [];

        // Pathfinder assignments
        $pathAssignments = DB::table('class_member_pathfinder as cmp')
            ->join('members as m', 'm.id', '=', 'cmp.member_id')
            ->join('club_classes as cc', 'cc.id', '=', 'cmp.club_class_id')
            ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
            ->whereIn('m.club_id', $filteredIds)
            ->where('cmp.active', true)
            ->where('m.status', 'active')
            ->whereIn('m.type', ['pathfinders', 'temp_pathfinder'])
            ->select('m.id as member_id', 'm.club_id')
            ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
            ->get();

        $advAssignmentsRaw = DB::table('class_member_adventurer as cma')
            ->join('club_classes as cc', 'cc.id', '=', 'cma.club_class_id')
            ->join('union_class_catalogs as ucc', 'ucc.id', '=', 'cc.union_class_catalog_id')
            ->whereIn('cc.club_id', $filteredIds)
            ->where('cma.active', true)
            ->select('cma.members_adventurer_id', 'cc.club_id')
            ->selectRaw("LOWER(TRIM(ucc.name)) as class_name")
            ->get();

        $advMemberMap = Member::where('type', 'adventurers')
            ->whereIn('id_data', $advAssignmentsRaw->pluck('members_adventurer_id')->unique()->toArray())
            ->whereIn('club_id', $filteredIds)
            ->where('status', 'active')
            ->get(['id', 'id_data', 'club_id'])
            ->keyBy('id_data');

        $advAssignments = $advAssignmentsRaw->map(function ($a) use ($advMemberMap) {
            $m = $advMemberMap->get($a->members_adventurer_id);
            return $m ? (object)['member_id' => $m->id, 'club_id' => $a->club_id, 'class_name' => $a->class_name] : null;
        })->filter()->values();

        $allAssignments = $pathAssignments->concat($advAssignments);
        $allMemberIds   = $allAssignments->pluck('member_id')->unique()->values()->toArray();

        // Build (normalized club_type|class_name) → unique member count
        $totalPerKey = [];
        foreach ($allAssignments as $a) {
            $ct  = $clubTypeMap->get($a->club_id) ?? '';
            $key = $ct . '|' . $a->class_name;
            if (!isset($totalPerKey[$key])) $totalPerKey[$key] = [];
            $totalPerKey[$key][$a->member_id] = true;
        }
        $totalPerKey = array_map('count', $totalPerKey);

        // Count completions per requirement
        $reqIds      = $requirements->pluck('id')->toArray();
        $completions = collect();
        if (!empty($allMemberIds)) {
            $completions = DB::table('parent_carpeta_requirement_evidences')
                ->whereIn('member_id', $allMemberIds)
                ->whereIn('union_carpeta_requirement_id', $reqIds)
                ->where(function ($q) {
                    $q->whereNotNull('file_path')
                      ->orWhereNotNull('text_value')
                      ->orWhere('physical_completed', true);
                })
                ->select('union_carpeta_requirement_id', DB::raw('COUNT(DISTINCT member_id) as cnt'))
                ->groupBy('union_carpeta_requirement_id')
                ->pluck('cnt', 'union_carpeta_requirement_id');
        }

        return $requirements->map(function ($r) use ($completions, $totalPerKey) {
            $key   = $this->normalizeCarpetaClubType($r->club_type) . '|' . $this->normalizeCarpetaValue($r->class_name);
            $total = $totalPerKey[$key] ?? 0;
            $done  = (int) ($completions->get($r->id) ?? 0);
            return [
                'id'            => $r->id,
                'title'         => $r->title,
                'club_type'     => $r->club_type,
                'class_name'    => $r->class_name,
                'total_members' => $total,
                'completed'     => $done,
                'pct'           => $total > 0 ? round($done / $total * 100, 1) : null,
            ];
        })->values()->all();
    }

    protected function buildBreadcrumb(Union $union, $association, $district, $club, string $level): array
    {
        $crumbs = [['label' => $union->name, 'level' => 'union', 'params' => []]];

        if ($association) {
            $crumbs[] = ['label' => $association->name, 'level' => 'association', 'params' => ['association_id' => $association->id]];
        }
        if ($district) {
            $crumbs[] = ['label' => $district->name, 'level' => 'district', 'params' => ['district_id' => $district->id, 'association_id' => $association?->id]];
        }
        if ($club) {
            $crumbs[] = ['label' => $club->name ?? $club['name'] ?? '', 'level' => 'club', 'params' => []];
        }

        return $crumbs;
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
            'master guide', 'master guides', 'guia mayor', 'guías mayores', 'guia mayores' => 'master_guide',
            default => $normalized,
        };
    }

    // ─── Catalog ──────────────────────────────────────────────────────────

    protected function catalogPayload(Union $union)
    {
        return $union->clubCatalogs()
            ->with(['classCatalogs' => fn ($query) => $query->select(
                'id',
                'union_club_catalog_id',
                'name',
                'sort_order',
                'status'
            )])
            ->get(['id', 'union_id', 'name', 'club_type', 'sort_order', 'status']);
    }

    protected function clubTypeOptions()
    {
        return ClubTypeCatalog::query()
            ->where('status', 'active')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['code', 'name']);
    }
}
