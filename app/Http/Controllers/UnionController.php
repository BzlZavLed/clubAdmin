<?php

namespace App\Http\Controllers;

use App\Models\UnionCarpetaYear;
use App\Models\UnionCarpetaRequirement;
use App\Models\UnionClubCatalog;
use App\Models\UnionClassCatalog;
use App\Models\Union;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use App\Support\SuperadminContext;

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
        ]);
    }

    public function storeClubCatalog(Request $request)
    {
        $union = $this->resolveScopedUnion($request);

        $validated = $request->validate([
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('union_club_catalogs', 'name')->where(fn ($query) => $query->where('union_id', $union->id)),
            ],
            'sort_order' => ['nullable', 'integer', 'min:1'],
        ]);

        $union->clubCatalogs()->create([
            'name' => $validated['name'],
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
            'club_type' => $clubCatalog->name,
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
            ->get(['id', 'union_id', 'name', 'sort_order', 'status']);
    }
}
