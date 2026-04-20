<?php

namespace App\Http\Controllers;
use Illuminate\Validation\Rule;
use App\Models\Club;
use App\Models\District;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;use App\Models\Church;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Staff;
use App\Models\StaffAdventurer;
use App\Models\StaffPathfinder;
use App\Models\Account;
use App\Models\Union;
use App\Models\UnionCarpetaYear;
use App\Support\ClubHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
class ClubController extends Controller
{
    use AuthorizesRequests;

    protected function syncEnrollmentPaymentConcept(Club $club): void
    {
        $amount = (float) ($club->enrollment_payment_amount ?? 0);

        $concept = PaymentConcept::query()
            ->where('club_id', $club->id)
            ->where('concept', 'Cuota de inscripción')
            ->where('pay_to', 'club_budget')
            ->first();

        if ($amount <= 0) {
            if ($concept) {
                $concept->update(['amount' => 0]);
            }

            return;
        }

        $concept ??= PaymentConcept::query()->create([
            'club_id' => $club->id,
            'concept' => 'Cuota de inscripción',
            'pay_to' => 'club_budget',
            'type' => 'mandatory',
            'status' => 'active',
            'created_by' => auth()->id(),
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

    protected function resolveEvaluationSystemForChurch(Church $church): string
    {
        $church->loadMissing('district.association.union:id,evaluation_system');

        return $church->district?->association?->union?->evaluation_system ?: 'honors';
    }

    protected function resolveDistrictHierarchy(District $district): array
    {
        $district->loadMissing('association.union');

        return [
            'district' => $district,
            'association' => $district->association,
            'union' => $district->association?->union,
        ];
    }

    protected function buildClubHierarchyFields(Church $church, District $district): array
    {
        $hierarchy = $this->resolveDistrictHierarchy($district);

        return [
            'church_name' => $church->church_name,
            'pastor_name' => $church->pastor_name,
            'conference_name' => $hierarchy['association']?->name,
            'evaluation_system' => $hierarchy['union']?->evaluation_system ?: 'honors',
            'district_id' => $district->id,
            'church_id' => $church->id,
        ];
    }

    protected function enforceChurchClubTypeRule(int $churchId, string $clubType, ?int $ignoreClubId = null): void
    {
        if (!in_array($clubType, ['adventurers', 'pathfinders'], true)) {
            return;
        }

        $query = Club::query()
            ->withoutGlobalScopes()
            ->where('church_id', $churchId)
            ->where('club_type', $clubType)
            ->where('status', 'active');

        if ($ignoreClubId) {
            $query->where('id', '!=', $ignoreClubId);
        }

        if ($query->exists()) {
            throw new HttpResponseException(response()->json([
                'message' => 'Esta iglesia ya tiene un club activo de este tipo.',
            ], 422));
        }
    }

    protected function normalizeCatalogName(?string $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function normalizeClubTypeValue(?string $value): string
    {
        $normalized = str_replace(['-', '_'], ' ', $this->normalizeCatalogName($value));
        $normalized = preg_replace('/\s+/', ' ', $normalized);

        return match ($normalized) {
            'adventurers', 'adventurer', 'aventureros', 'aventurero' => 'adventurers',
            'pathfinders', 'pathfinder', 'conquistadores', 'conquistador' => 'pathfinders',
            'master guide', 'master guides', 'guia mayor', 'guia mayores', 'guia mayor avanzado' => 'master_guide',
            default => $normalized,
        };
    }

    protected function clubTypeMatches(?string $left, ?string $right): bool
    {
        return $this->normalizeClubTypeValue($left) === $this->normalizeClubTypeValue($right);
    }

    protected function attachUnionCarpetaDefinitions($clubs)
    {
        if ($clubs->isEmpty()) {
            return $clubs;
        }

        $unionIds = $clubs
            ->map(fn ($club) => $club->district?->association?->union?->id)
            ->filter()
            ->unique()
            ->values();

        if ($unionIds->isEmpty()) {
            foreach ($clubs as $club) {
                $club->published_carpeta_year = null;
            }

            return $clubs;
        }

        $publishedYears = UnionCarpetaYear::query()
            ->with(['requirements' => fn ($query) => $query
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->whereIn('union_id', $unionIds)
            ->where('status', 'published')
            ->orderByDesc('year')
            ->orderByDesc('id')
            ->get()
            ->unique('union_id')
            ->keyBy('union_id');

        $unions = Union::query()
            ->with(['clubCatalogs.classCatalogs' => fn ($query) => $query
                ->where('status', 'active')
                ->orderBy('sort_order')
                ->orderBy('id')])
            ->whereIn('id', $unionIds)
            ->get()
            ->keyBy('id');

        $allActivationStaffIds = $clubs
            ->flatMap(fn ($club) => $club->carpetaClassActivations->pluck('assigned_staff_id'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $carpetaStaffNamesById = [];
        if (!empty($allActivationStaffIds)) {
            $carpetaStaff = Staff::query()
                ->whereIn('id', $allActivationStaffIds)
                ->with('user:id,name')
                ->get(['id', 'id_data', 'type', 'user_id']);

            foreach ($carpetaStaff as $staff) {
                $name = $staff->user?->name;
                if (!$name) {
                    $detail = ClubHelper::staffDetail($staff);
                    $name = $detail['name'] ?? null;
                }
                if ($name) {
                    $carpetaStaffNamesById[(int) $staff->id] = $name;
                }
            }
        }

        foreach ($clubs as $club) {
            $unionId = $club->district?->association?->union?->id;
            $publishedYear = $unionId ? $publishedYears->get($unionId) : null;
            $union = $unionId ? $unions->get($unionId) : null;
            $clubCatalog = $union?->clubCatalogs
                ?->first(fn ($catalog) => $this->clubTypeMatches($catalog->name, $club->club_type));
            $activationsByCatalogId = $club->carpetaClassActivations
                ->keyBy(fn ($activation) => (int) $activation->union_class_catalog_id);

            $club->union_class_catalogs = $clubCatalog
                ? $clubCatalog->classCatalogs
                    ->map(function ($catalogClass) use ($club, $publishedYear, $activationsByCatalogId, $carpetaStaffNamesById) {
                        $activation = $activationsByCatalogId->get((int) $catalogClass->id);

                        $requirements = $publishedYear
                            ? $publishedYear->requirements
                                ->filter(fn ($requirement) => $this->clubTypeMatches($requirement->club_type, $club->club_type)
                                    && $this->normalizeCatalogName($requirement->class_name) === $this->normalizeCatalogName($catalogClass->name))
                                ->map(fn ($requirement) => [
                                    'id' => $requirement->id,
                                    'title' => $requirement->title,
                                    'description' => $requirement->description,
                                    'requirement_type' => $requirement->requirement_type,
                                    'validation_mode' => $requirement->validation_mode,
                                    'allowed_evidence_types' => $requirement->allowed_evidence_types ?? [],
                                    'evidence_instructions' => $requirement->evidence_instructions,
                                    'sort_order' => $requirement->sort_order,
                                ])
                                ->values()
                                ->all()
                            : [];

                        return [
                            'id' => $catalogClass->id,
                            'name' => $catalogClass->name,
                            'sort_order' => $catalogClass->sort_order,
                            'is_active' => (bool) $activation,
                            'activation' => $activation ? [
                                'id' => $activation->id,
                                'union_class_catalog_id' => $activation->union_class_catalog_id,
                                'assigned_staff_name' => $activation->assigned_staff_id
                                    ? ($carpetaStaffNamesById[(int) $activation->assigned_staff_id] ?? null)
                                    : null,
                            ] : null,
                            'carpeta_requirements' => $requirements,
                        ];
                    })
                    ->values()
                    ->all()
                : [];

            $club->published_carpeta_year = $publishedYear
                ? [
                    'id' => $publishedYear->id,
                    'year' => $publishedYear->year,
                    'published_at' => $publishedYear->published_at,
                ]
                : null;
        }

        return $clubs;
    }

    public function storeBySuperadmin(Request $request)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can create clubs here.');
        }

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_id' => 'required|exists:churches,id',
            'district_id' => 'required|exists:districts,id',
            'director_user_id' => 'nullable|exists:users,id',
            'status' => 'required|in:active,inactive',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
            'evaluation_system' => 'required|in:honors,carpetas',
            'enrollment_payment_amount' => 'nullable|numeric|min:0|max:9999.99',
        ]);

        $church = Church::findOrFail($validated['church_id']);
        $district = District::findOrFail($validated['district_id']);
        $director = !empty($validated['director_user_id'])
            ? User::findOrFail($validated['director_user_id'])
            : null;
        $hierarchyFields = $this->buildClubHierarchyFields($church, $district);

        $this->enforceChurchClubTypeRule((int) $church->id, $validated['club_type']);

        if ($director && $director->profile_type !== 'club_director') {
            return back()->withErrors([
                'director_user_id' => 'Selected user must have club_director profile.',
            ]);
        }

        if ($validated['status'] === 'active' && !$director) {
            return back()->withErrors([
                'director_user_id' => 'An active club must have a director assigned.',
            ])->withInput();
        }

        if ($validated['status'] === 'inactive' && $director) {
            return back()->withErrors([
                'director_user_id' => 'Remove the director before saving the club as inactive.',
            ])->withInput();
        }

        $club = Club::create([
            'user_id' => $director?->id,
            'club_name' => $validated['club_name'],
            'church_name' => $hierarchyFields['church_name'],
            'director_name' => $director?->name,
            'creation_date' => $validated['creation_date'] ?? null,
            'pastor_name' => $hierarchyFields['pastor_name'],
            'conference_name' => $hierarchyFields['conference_name'],
            'conference_region' => $validated['conference_region'] ?? null,
            'club_type' => $validated['club_type'],
            'evaluation_system' => $hierarchyFields['evaluation_system'],
            'church_id' => $hierarchyFields['church_id'],
            'district_id' => $hierarchyFields['district_id'],
            'status' => $validated['status'],
            'enrollment_payment_amount' => $validated['enrollment_payment_amount'] ?? null,
        ]);

        $this->syncEnrollmentPaymentConcept($club);

        if ($director) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $director->id, 'club_id' => $club->id],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            $director->church_id = $church->id;
            $director->church_name = $church->church_name;
            $director->club_id = $club->id;
            $director->status = $director->status ?: 'active';
            $director->save();
        }

        return back()->with(
            'success',
            $director
                ? 'Club created and linked to director successfully.'
                : 'Club created as inactive. Link a director later to activate it.'
        );
    }

    public function updateBySuperadmin(Request $request, int $club)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can update clubs here.');
        }

        $club = Club::query()
            ->withoutGlobalScopes()
            ->findOrFail($club);

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_id' => 'required|exists:churches,id',
            'district_id' => 'required|exists:districts,id',
            'director_user_id' => 'nullable|exists:users,id',
            'status' => 'required|in:active,inactive',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
            'evaluation_system' => 'required|in:honors,carpetas',
            'enrollment_payment_amount' => 'nullable|numeric|min:0|max:9999.99',
        ]);

        $church = Church::findOrFail($validated['church_id']);
        $district = District::findOrFail($validated['district_id']);
        $director = !empty($validated['director_user_id'])
            ? User::findOrFail($validated['director_user_id'])
            : null;
        $hierarchyFields = $this->buildClubHierarchyFields($church, $district);

        $this->enforceChurchClubTypeRule((int) $church->id, $validated['club_type'], (int) $club->id);

        if ($director && $director->profile_type !== 'club_director') {
            return back()->withErrors([
                'director_user_id' => 'Selected user must have club_director profile.',
            ]);
        }

        if ($validated['status'] === 'active' && !$director) {
            return back()->withErrors([
                'director_user_id' => 'An active club must have a director assigned.',
            ])->withInput();
        }

        if ($validated['status'] === 'inactive' && $director) {
            return back()->withErrors([
                'director_user_id' => 'Remove the director before saving the club as inactive.',
            ])->withInput();
        }

        $previousDirectorId = $club->user_id;
        $nextDirectorId = $validated['status'] === 'active' ? $director?->id : null;

        $club->update([
            'user_id' => $nextDirectorId,
            'club_name' => $validated['club_name'],
            'church_name' => $hierarchyFields['church_name'],
            'director_name' => $nextDirectorId ? $director?->name : null,
            'creation_date' => $validated['creation_date'] ?? null,
            'pastor_name' => $hierarchyFields['pastor_name'],
            'conference_name' => $hierarchyFields['conference_name'],
            'conference_region' => $validated['conference_region'] ?? null,
            'club_type' => $validated['club_type'],
            'evaluation_system' => $hierarchyFields['evaluation_system'],
            'church_id' => $hierarchyFields['church_id'],
            'district_id' => $hierarchyFields['district_id'],
            'status' => $validated['status'],
            'enrollment_payment_amount' => $validated['enrollment_payment_amount'] ?? null,
        ]);

        $this->syncEnrollmentPaymentConcept($club);

        if ($nextDirectorId) {
            DB::table('club_user')->updateOrInsert(
                ['user_id' => $director->id, 'club_id' => $club->id],
                ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
            );

            $director->church_id = $church->id;
            $director->church_name = $church->church_name;
            $director->club_id = $club->id;
            $director->status = $director->status ?: 'active';
            $director->save();
        }

        if ($previousDirectorId && $previousDirectorId !== $nextDirectorId) {
            $previousDirector = User::find($previousDirectorId);
            if ($previousDirector && (int) $previousDirector->club_id === (int) $club->id) {
                $previousDirector->club_id = null;
                $previousDirector->save();
            }

            DB::table('club_user')
                ->where('user_id', $previousDirectorId)
                ->where('club_id', $club->id)
                ->update(['status' => 'inactive', 'updated_at' => now()]);
        }

        return back()->with('success', 'Club updated successfully.');
    }

    public function deactivateBySuperadmin(int $club)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can deactivate clubs here.');
        }

        $club = Club::query()
            ->withoutGlobalScopes()
            ->findOrFail($club);

        $club->update(['status' => 'inactive']);

        DB::table('club_user')
            ->where('club_id', $club->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        User::where('club_id', $club->id)->update(['club_id' => null]);

        return back()->with('success', 'Club deactivated successfully.');
    }

    public function deleteBySuperadmin(int $club)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can delete clubs here.');
        }

        $club = Club::query()
            ->withoutGlobalScopes()
            ->findOrFail($club);

        $club->update(['status' => 'deleted']);

        DB::table('club_user')
            ->where('club_id', $club->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        User::where('club_id', $club->id)->update(['club_id' => null]);

        return back()->with('success', 'Club deleted successfully.');
    }

    public function store(Request $request)
    {
        if (!in_array(auth()->user()->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403, 'Only club directors or superadmin can create a club.');
        }

        $authUser = auth()->user();
        if ($authUser?->profile_type === 'club_director') {
            $ownedClubCount = Club::query()
                ->where('user_id', $authUser->id)
                ->count();

            if ($ownedClubCount >= 2) {
                return response()->json([
                    'message' => 'Un director no puede tener mas de 2 clubes asignados.',
                ], 422);
            }
        }

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'creation_date' => 'nullable|date',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
            'church_id' => 'required|exists:churches,id',
            'district_id' => 'required|exists:districts,id',
            'evaluation_system' => 'required|in:honors,carpetas',
            'enrollment_payment_amount' => 'nullable|numeric|min:0|max:9999.99',
        ]);

        $this->enforceChurchClubTypeRule((int) $validated['church_id'], $validated['club_type']);

        $church = Church::findOrFail($validated['church_id']);
        $district = District::findOrFail($validated['district_id']);
        $hierarchyFields = $this->buildClubHierarchyFields($church, $district);

        $club = Club::create(array_merge($validated, [
            'user_id' => auth()->id(),
            'church_name' => $hierarchyFields['church_name'],
            'director_name' => auth()->user()->name,
            'pastor_name' => $hierarchyFields['pastor_name'],
            'conference_name' => $hierarchyFields['conference_name'],
            'evaluation_system' => $hierarchyFields['evaluation_system'],
            'church_id' => $hierarchyFields['church_id'],
            'district_id' => $hierarchyFields['district_id'],
        ]));

        $this->syncEnrollmentPaymentConcept($club);
        // Link user to this club in pivot table with status
        $club->users()->attach(auth()->id(), ['status' => 'active']);

        $user = auth()->user();
        $user->club_id = $club->id;
        $user->save();

        return redirect()->route('club.my-club')
            ->with('success', 'Club created successfully!');
    }

    public function show()
    {
        $club = Club::where('user_id', auth()->id())->firstOrFail();

        $this->authorize('view', $club);

        return response()->json($club);
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'id' => 'required|exists:clubs,id',
            'club_name' => 'required|string|max:255',
            'creation_date' => 'nullable|date',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
            'church_id' => 'required|exists:churches,id',
            'district_id' => 'required|exists:districts,id',
            'evaluation_system' => 'required|in:honors,carpetas',
            'enrollment_payment_amount' => 'nullable|numeric|min:0|max:9999.99',
        ]);

        $clubQuery = Club::query()
            ->withoutGlobalScopes()
            ->where('id', (int) $validated['id']);

        if (auth()->user()?->profile_type !== 'superadmin') {
            $clubQuery->where(function ($query) {
                $query->where('user_id', auth()->id())
                    ->orWhereHas('users', function ($userQuery) {
                        $userQuery->where('users.id', auth()->id());
                    });
            });
        }

        $club = $clubQuery->firstOrFail();

        $this->enforceChurchClubTypeRule((int) $validated['church_id'], $validated['club_type'], (int) $club->id);

        $church = Church::findOrFail($validated['church_id']);
        $district = District::findOrFail($validated['district_id']);
        $hierarchyFields = $this->buildClubHierarchyFields($church, $district);

        $club->update(array_merge(\Illuminate\Support\Arr::except($validated, ['id']), [
            'church_name' => $hierarchyFields['church_name'],
            'pastor_name' => $hierarchyFields['pastor_name'],
            'conference_name' => $hierarchyFields['conference_name'],
            'evaluation_system' => $hierarchyFields['evaluation_system'],
            'church_id' => $hierarchyFields['church_id'],
            'district_id' => $hierarchyFields['district_id'],
        ]));

        $this->syncEnrollmentPaymentConcept($club);

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Club updated successfully.']);
        }

        return redirect()->back()->with('success', 'Club updated successfully.');
    }


    public function destroy(Request $request)
    {
        $clubId = $request->input('id');

        $club = Club::findOrFail($clubId);

        if (auth()->user()?->profile_type !== 'superadmin' && !$club->users()->where('user_id', auth()->id())->exists()) {
            abort(403);
        }

        $club->update(['status' => 'deleted']);

        return response()->json(['message' => 'Club deleted successfully.']);
    }
    public function getByIds(Request $request)
    {
        $ids = (array) $request->input('ids', []);

        $clubs = Club::whereIn('id', $ids)->get();

        return response()->json($clubs);
    }

    public function getByUser(User $user)
    {
        $authUser = auth()->user();
        if (!$authUser) {
            abort(401);
        }

        if ($authUser->profile_type !== 'superadmin' && (int) $authUser->id !== (int) $user->id) {
            abort(403, 'Not allowed to load clubs for another user.');
        }

        if ($authUser->profile_type === 'superadmin' && (int) $authUser->id === (int) $user->id) {
            $query = Club::query()
                ->with(['clubClasses.investitureRequirements', 'carpetaClassActivations', 'staffAdventurers', 'localObjectives', 'district.association.union'])
                ->orderBy('club_name');

            $contextClubId = session('superadmin_context.club_id');
            $contextChurchId = session('superadmin_context.church_id');
            if ($contextClubId) {
                $query->where('id', (int) $contextClubId);
            } elseif ($contextChurchId) {
                $query->where('church_id', (int) $contextChurchId);
            }

            $clubs = $query->get();

            return response()->json($this->attachStaffAssignments($clubs));
        }

        $clubIds = ClubHelper::clubIdsForUser($user);

        $clubs = Club::whereIn('id', $clubIds)
            ->with(['clubClasses.investitureRequirements', 'carpetaClassActivations', 'staffAdventurers', 'localObjectives', 'district.association.union'])
            ->orderBy('club_name')
            ->get();

        return response()->json($this->attachStaffAssignments($clubs));
    }

    public function getByChurchNames(Request $request)
    {
        $input = $request->input('church_name', []);

        // Normalize to array
        $names = is_array($input) ? $input : [$input];

        $clubs = Club::whereIn('church_name', $names)->get();

        return response()->json($clubs);
    }

    public function getByChurch(Church $church)
    {
        return $church->clubs()->select('id', 'club_name', 'club_type')->orderBy('club_name')->get();
    }

    public function getClubsByChurchId($churchId)
    {
        $clubs = Club::with('clubClasses.investitureRequirements', 'carpetaClassActivations', 'staffAdventurers', 'users:id,name,email', 'localObjectives', 'district.association.union')
            ->where('church_id', $churchId)
            ->orderBy('club_name')
            ->get();

        $clubs = $this->attachStaffAssignments($clubs);

        foreach ($clubs as $club) {
            $ownerId = $club->user_id ?? null;
            if ($ownerId) {
                $owner = User::select('id', 'name', 'email')->find($ownerId);
                if ($owner && !$club->users->contains('id', $owner->id)) {
                    $club->users->push($owner);
                }
            }
        }

        return response()->json($clubs);
    }

    public function selectClub(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'user_id' => 'required|exists:users,id',
        ]);

        $authUser = $request->user();
        $user = User::findOrFail($validated['user_id']);

        if (!$authUser) {
            abort(401);
        }

        if ($authUser->profile_type !== 'superadmin' && (int) $authUser->id !== (int) $user->id) {
            abort(403, 'Not allowed to change another user club.');
        }

        if ($authUser->profile_type === 'superadmin' && (int) $authUser->id === (int) $user->id) {
            $club = Club::query()
                ->where('id', (int) $validated['club_id'])
                ->firstOrFail(['id', 'club_name', 'church_id']);

            $request->session()->put('superadmin_context.club_id', $club->id);
            $request->session()->put('superadmin_context.church_id', $club->church_id);

            return response()->json([
                'message' => 'Club selected successfully.',
                'context' => [
                    'club_id' => $club->id,
                    'club_name' => $club->club_name,
                    'church_id' => $club->church_id,
                ],
            ]);
        }

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $user->id, 'club_id' => $validated['club_id']],
            ['status' => 'active', 'updated_at' => now()]
        );

        $club = Club::query()
            ->where('id', (int) $validated['club_id'])
            ->firstOrFail(['id', 'club_name', 'church_id', 'church_name']);

        $request->session()->put('club_context.club_id', $club->id);
        $request->session()->put('club_context.church_id', $club->church_id);

        $user->club_id = $validated['club_id'];
        $user->church_id = $club->church_id;
        $user->church_name = $club->church_name;
        $user->save();

        $user->load(['clubs.clubClasses', 'church', 'clubs.staffAdventurers']);

        return response()->json([
            'message' => 'Club selected successfully.',
            'context' => [
                'club_id' => $club->id,
                'club_name' => $club->club_name,
                'church_id' => $club->church_id,
            ],
        ]);
    }

    public function attachDirector(Request $request, Club $club)
    {
        $user = $request->user();
        if (!$user || !in_array($user->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403, 'Only club directors or superadmin can attach to a club.');
        }

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetUser = User::findOrFail((int) $request->input('user_id'));
        if ($user->profile_type !== 'superadmin' && (int) $user->id !== (int) $targetUser->id) {
            abort(403, 'Not allowed to attach another user.');
        }

        if ($targetUser->profile_type !== 'club_director') {
            return response()->json([
                'message' => 'Solo usuarios con perfil club_director pueden adjuntarse a clubes.',
            ], 422);
        }

        if ($targetUser->church_id && (int) $targetUser->church_id !== (int) $club->church_id) {
            return response()->json([
                'message' => 'Solo puedes adjuntarte a clubes de tu misma iglesia.',
            ], 422);
        }

        $currentClubCount = DB::table('club_user')
            ->where('user_id', $targetUser->id)
            ->where('status', 'active')
            ->count();

        if ($currentClubCount >= 2 && !DB::table('club_user')->where('user_id', $targetUser->id)->where('club_id', $club->id)->exists()) {
            return response()->json([
                'message' => 'Un director no puede tener mas de 2 clubes asignados.',
            ], 422);
        }

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $targetUser->id, 'club_id' => $club->id],
            ['status' => 'active', 'updated_at' => now(), 'created_at' => now()]
        );

        if ((int) ($targetUser->club_id ?? 0) !== (int) $club->id) {
            $targetUser->club_id = $club->id;
            $targetUser->church_id = $club->church_id;
            $targetUser->church_name = $club->church_name;
            $targetUser->save();
        }

        if ((int) ($club->user_id ?? 0) !== (int) $targetUser->id || $club->status !== 'active') {
            $club->user_id = $targetUser->id;
            $club->director_name = $targetUser->name;
            $club->status = 'active';
            $club->save();
        }

        $request->session()->put('club_context.club_id', $club->id);
        $request->session()->put('club_context.church_id', $club->church_id);

        return response()->json([
            'message' => 'Director adjuntado al club correctamente.',
            'context' => [
                'club_id' => $club->id,
                'club_name' => $club->club_name,
                'church_id' => $club->church_id,
            ],
        ]);
    }

    public function detachDirector(Request $request, Club $club)
    {
        $user = $request->user();
        if (!$user || !in_array($user->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403, 'Only club directors or superadmin can detach from a club.');
        }

        $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $targetUser = User::findOrFail((int) $request->input('user_id'));
        if ($user->profile_type !== 'superadmin' && (int) $user->id !== (int) $targetUser->id) {
            abort(403, 'Not allowed to detach another user.');
        }

        $isLinked = DB::table('club_user')
            ->where('user_id', $targetUser->id)
            ->where('club_id', $club->id)
            ->where('status', 'active')
            ->exists();

        if (!$isLinked) {
            return response()->json([
                'message' => 'Este director no esta vinculado activamente a este club.',
            ], 422);
        }

        $activeDirectors = User::query()
            ->select('users.id', 'users.name')
            ->join('club_user', 'club_user.user_id', '=', 'users.id')
            ->where('club_user.club_id', $club->id)
            ->where('club_user.status', 'active')
            ->where('users.profile_type', 'club_director')
            ->where('users.status', 'active')
            ->get();

        DB::table('club_user')
            ->where('user_id', $targetUser->id)
            ->where('club_id', $club->id)
            ->delete();

        $replacementDirector = $activeDirectors
            ->firstWhere('id', '!=', $targetUser->id);

        if ($replacementDirector) {
            if ((int) $club->user_id === (int) $targetUser->id || $club->status !== 'active') {
                $club->user_id = $replacementDirector->id;
                $club->director_name = $replacementDirector->name;
                $club->status = 'active';
                $club->save();
            }
        } else {
            $club->user_id = null;
            $club->director_name = null;
            $club->status = 'inactive';
            $club->save();
        }

        $remainingClubIds = DB::table('club_user')
            ->where('user_id', $targetUser->id)
            ->where('status', 'active')
            ->pluck('club_id');

        $remainingClub = $remainingClubIds->isNotEmpty()
            ? Club::query()->whereIn('id', $remainingClubIds)->orderBy('club_name')->first(['id', 'church_id', 'church_name'])
            : null;

        $targetUser->club_id = $remainingClub?->id;
        $targetUser->church_id = $remainingClub?->church_id;
        $targetUser->church_name = $remainingClub?->church_name;
        $targetUser->save();

        if ((int) ($request->session()->get('club_context.club_id') ?? 0) === (int) $club->id) {
            $request->session()->put('club_context.club_id', $remainingClub?->id);
            $request->session()->put('club_context.church_id', $remainingClub?->church_id);
        }

        return response()->json([
            'message' => 'Director desvinculado del club correctamente.',
            'context' => [
                'club_id' => $remainingClub?->id,
                'church_id' => $remainingClub?->church_id,
            ],
        ]);
    }

    /**
     * Attach staff assignment info from staff table to each class,
     * resolving the staff name from staff_adventurers when type is adventurers.
     */
    protected function attachStaffAssignments($clubs)
    {
        if ($clubs->isEmpty()) {
            return $clubs;
        }

        foreach ($clubs as $club) {
            $club->district_name = $club->district?->name;
            $club->association_name = $club->district?->association?->name;
            $club->union_name = $club->district?->association?->union?->name;
        }

        $clubIds = $clubs->pluck('id');

        // Only query honors-style assignments; carpeta staff names are attached in attachUnionCarpetaDefinitions
        $honorsClubIds = $clubs
            ->filter(fn ($club) => ($club->evaluation_system ?? 'honors') !== 'carpetas')
            ->pluck('id');

        $staffRecords = Staff::query()
            ->whereIn('club_id', $honorsClubIds)
            ->whereNotNull('assigned_class')
            ->with('user:id,name')
            ->get(['id', 'id_data', 'club_id', 'assigned_class', 'type', 'user_id']);

        if ($staffRecords->isEmpty()) {
            return $this->attachUnionCarpetaDefinitions($clubs);
        }

        $adventurerDetailNames = StaffAdventurer::whereIn('id',
            $staffRecords->where('type', 'adventurers')->pluck('id_data')->filter()->unique()->values()
        )->get(['id', 'name'])->keyBy('id');

        $pathfinderDetailNames = StaffPathfinder::whereIn('id',
            $staffRecords->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id_data')->filter()->unique()->values()
        )->get(['id', 'staff_name'])->keyBy('id');

        $byClass = [];
        foreach ($staffRecords as $record) {
            $name = $record->user?->name;
            if (!$name && $record->id_data) {
                if ($record->type === 'adventurers') {
                    $name = $adventurerDetailNames->get($record->id_data)?->name;
                } elseif (in_array($record->type, ['pathfinders', 'temp_pathfinder'], true)) {
                    $name = $pathfinderDetailNames->get($record->id_data)?->staff_name;
                }
            }
            $byClass[$record->assigned_class] = [
                'staff_id' => $record->id,
                'name' => $name,
            ];
        }

        foreach ($clubs as $club) {
            foreach ($club->clubClasses as $class) {
                if (isset($byClass[$class->id])) {
                    $class->assigned_staff_name = $byClass[$class->id]['name'];
                    $class->assigned_staff_record_id = $byClass[$class->id]['staff_id'];
                }
            }
        }

        return $this->attachUnionCarpetaDefinitions($clubs);
    }






    /* =========================
     * PAYMENT CONCEPTS (CRUD)
     * ========================= */

    // GET /clubs/{club}/payment-concepts
    public function paymentConceptsIndex(Request $request, Club $club)
    {
        $request->validate([
            'status' => ['nullable', Rule::in(['active'])],
        ]);

        $query = PaymentConcept::query()
            ->where('club_id', $club->id)
            ->when($request->filled('status'), fn($q) => $q->where('status', $request->status))
            ->with([
                'createdBy:id,name',
                'club:id,club_name',
                'scopes',
                'scopes.club:id,club_name',
                'scopes.class:id,class_name',
                'scopes.member:id,applicant_name',
                'scopes.staff:id,name',
            ])
            ->orderByDesc('created_at');

        return response()->json(['data' => $query->get()]);
    }

    // GET /clubs/{club}/payment-concepts/{paymentConcept}
    public function paymentConceptsShow(Club $club, PaymentConcept $paymentConcept)
    {
        $this->assertBelongsToClub($paymentConcept, $club);

        $paymentConcept->load([
            'createdBy:id,name',
            'club:id,club_name',
            'scopes',
            'scopes.club:id,club_name',
            'scopes.class:id,class_name',
            'scopes.member:id,applicant_name',
            'scopes.staff:id,name',
        ]);

        return response()->json(['data' => $paymentConcept]);
    }

    // POST /clubs/{club}/payment-concepts
    public function paymentConceptsStore(Request $request, Club $club)
    {
        $payload = $this->validateConcept($request, create: true);

        // Normalize pay_to typo and payee_type short names from the UI
        $payload['pay_to'] = $this->normalizePayTo($payload['pay_to'] ?? null);
        [$payload['payee_type'], $payload['payee_id']] = $this->normalizePayee(
            $payload['pay_to'] ?? null,
            $payload['payee_type'] ?? null,
            $payload['payee_id'] ?? null
        );

        $this->assertScopeCoherence($payload['scopes'] ?? []);
        $this->assertAccountPayTo($club->id, $payload['pay_to'] ?? null);

        return DB::transaction(function () use ($payload, $request, $club) {
            $concept = PaymentConcept::create([
                'concept'             => $payload['concept'],
                'payment_expected_by' => $payload['payment_expected_by'] ?? null,
                'amount'              => $payload['amount'],       // <--
                'reusable'            => (bool) ($payload['reusable'] ?? false),
                'type'                => $payload['type'],
                'pay_to'              => $payload['pay_to'],
                'payee_type'          => $payload['payee_type'] ?? null,
                'payee_id'            => $payload['payee_id'] ?? null,
                'created_by'          => $request->user()->id,
                'status'              => $payload['status'],
                'club_id'             => $club->id,
            ]);

            foreach ($payload['scopes'] as $s) {
                $concept->scopes()->create([
                    'scope_type' => $s['scope_type'],
                    'club_id'    => $s['club_id']   ?? null,
                    'class_id'   => $s['class_id']  ?? null,
                    'member_id'  => $s['member_id'] ?? null,
                    'staff_id'   => $s['staff_id']  ?? null,
                ]);
            }

            return response()->json([
                'data' => $concept->load([
                    'createdBy:id,name',
                    'club:id,club_name',
                    'scopes',
                    'scopes.club:id,club_name',
                    'scopes.class:id,class_name',
                    'scopes.member:id,applicant_name',
                    'scopes.staff:id,name',
                ])
            ], 201);
        });
    }

    // PUT /clubs/{club}/payment-concepts/{paymentConcept}
    public function paymentConceptsUpdate(Request $request, Club $club, PaymentConcept $paymentConcept)
    {
        $this->assertBelongsToClub($paymentConcept, $club);

        $payload = $this->validateConcept($request, create: false);

        if (array_key_exists('pay_to', $payload)) {
            $payload['pay_to'] = $this->normalizePayTo($payload['pay_to']);
        }

        if (array_key_exists('pay_to', $payload) || array_key_exists('payee_type', $payload) || array_key_exists('payee_id', $payload)) {
            [$payload['payee_type'], $payload['payee_id']] = $this->normalizePayee(
                $payload['pay_to'] ?? $paymentConcept->pay_to,
                $payload['payee_type'] ?? $paymentConcept->payee_type,
                $payload['payee_id'] ?? $paymentConcept->payee_id
            );
        }

        if (array_key_exists('scopes', $payload)) {
            $this->assertScopeCoherence($payload['scopes']);
        }
        if (array_key_exists('pay_to', $payload)) {
            $this->assertAccountPayTo($club->id, $payload['pay_to']);
        }

        return DB::transaction(function () use ($paymentConcept, $payload) {
            $paymentConcept->fill($payload);
            $paymentConcept->save();

            if (array_key_exists('scopes', $payload)) {
                $paymentConcept->scopes()->delete();
                foreach ($payload['scopes'] as $s) {
                    $paymentConcept->scopes()->create([
                        'scope_type' => $s['scope_type'],
                        'club_id'    => $s['club_id']   ?? null,
                        'class_id'   => $s['class_id']  ?? null,
                        'member_id'  => $s['member_id'] ?? null,
                        'staff_id'   => $s['staff_id']  ?? null,
                    ]);
                }
            }

            return response()->json([
                'data' => $paymentConcept->load([
                    'createdBy:id,name',
                    'club:id,club_name',
                    'scopes',
                    'scopes.club:id,club_name',
                    'scopes.class:id,class_name',
                    'scopes.member:id,applicant_name',
                    'scopes.staff:id,name',
                ])
            ]);
        });
    }

    // DELETE /clubs/{club}/payment-concepts/{paymentConcept}
    public function paymentConceptsDestroy(Club $club, PaymentConcept $paymentConcept)
    {
        $this->assertBelongsToClub($paymentConcept, $club);

        DB::transaction(function () use ($paymentConcept) {
            $paymentConcept->update(['status' => 'inactive']);
            $paymentConcept->scopes()->update(['deleted_on' => now()]);

        });

        return response()->json(['message' => 'Deleted (soft)']);
    }

    /* ---------- Helpers ---------- */

    protected function assertBelongsToClub(PaymentConcept $concept, Club $club): void
    {
        abort_if($concept->club_id !== $club->id, 404, 'Not found.');
    }

    protected function validateConcept(Request $request, bool $create): array
    {
        $base = [
            'concept'              => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'payment_expected_by'  => [$create ? 'nullable' : 'sometimes', 'date'],
            'amount'               => [$create ? 'required' : 'sometimes', 'numeric', 'min:0', 'max:999999.99'], // <--
            'reusable'             => ['sometimes', 'boolean'],
            'type'                 => [$create ? 'required' : 'sometimes', Rule::in(['mandatory','optional'])],
            'pay_to'               => [$create ? 'required' : 'sometimes', 'string', 'max:255'],
            'payee_type'           => ['nullable','string','max:255'],
            'payee_id'             => ['nullable','integer'],
            'status'               => [$create ? 'required' : 'sometimes', Rule::in(['active','inactive'])],
            'scopes'               => [$create ? 'required' : 'sometimes','array','min:1'],
            'scopes.*.scope_type'  => ['required_with:scopes', Rule::in(['club_wide','class','member','staff_wide','staff'])],
            'scopes.*.club_id'     => ['nullable','integer','exists:clubs,id'],
            'scopes.*.class_id'    => ['nullable','integer','exists:club_classes,id'],
            'scopes.*.member_id'   => ['nullable','integer','exists:member_adventurers,id'],
            'scopes.*.staff_id'    => ['nullable','integer','exists:staff_adventurers,id'],
        ];

        return $request->validate($base);
    }

    protected function normalizePayTo(?string $payTo): ?string
    {
        if ($payTo === 'reinbursement_to') return 'reimbursement_to';
        return $payTo;
    }

    protected function assertAccountPayTo(int $clubId, ?string $payTo): void
    {
        if (!$payTo) {
            abort(422, 'Invalid pay_to.');
        }
        $exists = Account::query()
            ->where('club_id', $clubId)
            ->where('pay_to', $payTo)
            ->exists();
        if (!$exists) {
            abort(422, "Account '{$payTo}' does not exist for this club.");
        }
    }

    /**
     * Accepts short names from UI (e.g., 'StaffAdventurer') or fully-qualified class names.
     * Clears payee when pay_to != reimbursement_to.
     */
    protected function normalizePayee(?string $payTo, ?string $type, $id): array
    {
        if ($payTo !== 'reimbursement_to') {
            return [null, null];
        }

        if (!$type || !$id) {
            return [null, null];
        }

        // Map short names to FQCN
        $map = [
            'StaffAdventurer'  => \App\Models\Staff::class,
            'MemberAdventurer' => \App\Models\Member::class,
            'Staff'            => \App\Models\Staff::class,
            'Member'           => \App\Models\Member::class,
            'User'             => \App\Models\User::class,
        ];

        if (isset($map[$type])) {
            $type = $map[$type];
        }

        return [$type, $id];
    }

    /**
     * Ensure each scope has the required foreign keys:
     * - club_wide:  club_id required
     * - class:      class_id required
     * - member:     member_id required
     * - staff_wide: club_id required
     * - staff:      staff_id required
     */
    protected function assertScopeCoherence(array $scopes): void
    {
        foreach ($scopes as $s) {
            $t = $s['scope_type'] ?? null;
            $ok = match ($t) {
                'club_wide'  => !empty($s['club_id']),
                'class'      => !empty($s['class_id']),
                'member'     => !empty($s['member_id']),
                'staff_wide' => !empty($s['club_id']),
                'staff'      => !empty($s['staff_id']),
                default      => false,
            };
            if (!$ok) {
                abort(422, "Invalid scope payload for scope_type '{$t}'");
            }
        }
    }






















}
