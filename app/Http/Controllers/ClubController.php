<?php

namespace App\Http\Controllers;
use Illuminate\Validation\Rule;
use App\Models\Club;
use Illuminate\Http\Request;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;use App\Models\Church;
use Illuminate\Support\Facades\DB;
use App\Models\User;
use App\Models\Staff;
use App\Models\StaffAdventurer;
use App\Models\Account;
use App\Support\ClubHelper;
use Illuminate\Http\Exceptions\HttpResponseException;
class ClubController extends Controller
{
    use AuthorizesRequests;

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

    public function storeBySuperadmin(Request $request)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can create clubs here.');
        }

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_id' => 'required|exists:churches,id',
            'director_user_id' => 'required|exists:users,id',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
        ]);

        $church = Church::findOrFail($validated['church_id']);
        $director = User::findOrFail($validated['director_user_id']);

        $this->enforceChurchClubTypeRule((int) $church->id, $validated['club_type']);

        if ($director->profile_type !== 'club_director') {
            return back()->withErrors([
                'director_user_id' => 'Selected user must have club_director profile.',
            ]);
        }

        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => $validated['club_name'],
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => $validated['creation_date'] ?? null,
            'pastor_name' => $validated['pastor_name'] ?? null,
            'conference_name' => $validated['conference_name'] ?? null,
            'conference_region' => $validated['conference_region'] ?? null,
            'club_type' => $validated['club_type'],
            'church_id' => $church->id,
            'status' => 'active',
        ]);

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $director->id, 'club_id' => $club->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $director->church_id = $church->id;
        $director->church_name = $church->church_name;
        $director->club_id = $club->id;
        $director->status = $director->status ?: 'active';
        $director->save();

        return back()->with('success', 'Club created and linked to director successfully.');
    }

    public function updateBySuperadmin(Request $request, Club $club)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can update clubs here.');
        }

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_id' => 'required|exists:churches,id',
            'director_user_id' => 'required|exists:users,id',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
        ]);

        $church = Church::findOrFail($validated['church_id']);
        $director = User::findOrFail($validated['director_user_id']);

        $this->enforceChurchClubTypeRule((int) $church->id, $validated['club_type'], (int) $club->id);

        if ($director->profile_type !== 'club_director') {
            return back()->withErrors([
                'director_user_id' => 'Selected user must have club_director profile.',
            ]);
        }

        $previousDirectorId = $club->user_id;

        $club->update([
            'user_id' => $director->id,
            'club_name' => $validated['club_name'],
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => $validated['creation_date'] ?? null,
            'pastor_name' => $validated['pastor_name'] ?? null,
            'conference_name' => $validated['conference_name'] ?? null,
            'conference_region' => $validated['conference_region'] ?? null,
            'club_type' => $validated['club_type'],
            'church_id' => $church->id,
        ]);

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $director->id, 'club_id' => $club->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $director->church_id = $church->id;
        $director->church_name = $church->church_name;
        $director->club_id = $club->id;
        $director->status = $director->status ?: 'active';
        $director->save();

        if ($previousDirectorId && $previousDirectorId !== $director->id) {
            $previousDirector = User::find($previousDirectorId);
            if ($previousDirector && (int) $previousDirector->club_id === (int) $club->id) {
                $previousDirector->club_id = null;
                $previousDirector->save();
            }
        }

        return back()->with('success', 'Club updated successfully.');
    }

    public function deactivateBySuperadmin(Club $club)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can deactivate clubs here.');
        }

        $club->update(['status' => 'inactive']);

        DB::table('club_user')
            ->where('club_id', $club->id)
            ->update(['status' => 'inactive', 'updated_at' => now()]);

        User::where('club_id', $club->id)->update(['club_id' => null]);

        return back()->with('success', 'Club deactivated successfully.');
    }

    public function deleteBySuperadmin(Club $club)
    {
        if (auth()->user()?->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can delete clubs here.');
        }

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
            'church_name' => 'required|string|max:255',
            'director_name' => 'required|string|max:255',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
            'church_id' => 'required|exists:churches,id',
        ]);

        $this->enforceChurchClubTypeRule((int) $validated['church_id'], $validated['club_type']);

        $club = Club::create(array_merge($validated, [
            'user_id' => auth()->id(),
        ]));
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
        // Remove policy if not using it
        $club = Club::where('user_id', auth()->id())->firstOrFail();

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'church_name' => 'required|string|max:255',
            'creation_date' => 'nullable|date',
            'pastor_name' => 'nullable|string|max:255',
            'conference_name' => 'nullable|string|max:255',
            'conference_region' => 'nullable|string|max:255',
            'club_type' => 'required|in:adventurers,pathfinders,master_guide',
        ]);

        $club->update($validated);

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
                ->with(['clubClasses.investitureRequirements', 'staffAdventurers'])
                ->orderBy('club_name');

            $contextChurchId = session('superadmin_context.church_id');
            if ($contextChurchId) {
                $query->where('church_id', (int) $contextChurchId);
            }

            $clubs = $query->get();

            return response()->json($this->attachStaffAssignments($clubs));
        }

        $clubIds = ClubHelper::clubIdsForUser($user);

        $clubs = Club::whereIn('id', $clubIds)
            ->with(['clubClasses.investitureRequirements', 'staffAdventurers', 'localObjectives'])
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
        $clubs = Club::with('clubClasses.investitureRequirements', 'staffAdventurers', 'users:id,name,email', 'localObjectives')
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

        if ($activeDirectors->count() <= 1) {
            return response()->json([
                'message' => 'No puedes desvincularte hasta que otro director este vinculado a este club.',
            ], 422);
        }

        DB::table('club_user')
            ->where('user_id', $targetUser->id)
            ->where('club_id', $club->id)
            ->delete();

        $replacementDirector = $activeDirectors
            ->firstWhere('id', '!=', $targetUser->id);

        if ((int) $club->user_id === (int) $targetUser->id && $replacementDirector) {
            $club->user_id = $replacementDirector->id;
            $club->director_name = $replacementDirector->name;
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

        $clubIds = $clubs->pluck('id');
        $staffRecords = Staff::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotNull('assigned_class')
            ->get(['id', 'id_data', 'club_id', 'assigned_class', 'type']);

        if ($staffRecords->isEmpty()) {
            return $clubs;
        }

        $adventurerIds = $staffRecords
            ->where('type', 'adventurers')
            ->pluck('id_data')
            ->filter()
            ->unique();

        $staffNames = [];
        if ($adventurerIds->isNotEmpty()) {
            $staffNames = StaffAdventurer::whereIn('id', $adventurerIds)
                ->get(['id', 'name'])
                ->keyBy('id');
        }

        $byClass = [];
        foreach ($staffRecords as $record) {
            $name = null;
            if ($record->type === 'adventurers' && $record->id_data && isset($staffNames[$record->id_data])) {
                $name = $staffNames[$record->id_data]->name;
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

        return $clubs;
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
