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
class ClubController extends Controller
{
    use AuthorizesRequests;
    public function store(Request $request)
    {
        if (auth()->user()->profile_type !== 'club_director') {
            abort(403, 'Only club directors can create a club.');
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

        if (!$club->users()->where('user_id', auth()->id())->exists()) {
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
        $clubs = Club::with('clubClasses', 'staffAdventurers')
            ->where('church_id', $churchId)
            ->orderBy('club_name')
            ->get();

        return response()->json($clubs);
    }

    public function selectClub(Request $request)
    {
        $validated = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'user_id' => 'required|exists:users,id',
        ]);

        $user = User::findOrFail($validated['user_id']);

        DB::table('club_user')->updateOrInsert(
            ['user_id' => $user->id, 'club_id' => $validated['club_id']],
            ['status' => 'active', 'updated_at' => now()]
        );

        $user->club_id = $validated['club_id'];
        $user->save();

        $user->load(['clubs.clubClasses', 'church', 'clubs.staffAdventurers']);

        return response()->json(['message' => 'Club selected successfully.']);
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

        return DB::transaction(function () use ($payload, $request, $club) {
            $concept = PaymentConcept::create([
                'concept'             => $payload['concept'],
                'payment_expected_by' => $payload['payment_expected_by'] ?? null,
                'amount'              => $payload['amount'],       // <--
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
            'type'                 => [$create ? 'required' : 'sometimes', Rule::in(['mandatory','optional'])],
            'pay_to'               => [$create ? 'required' : 'sometimes', Rule::in(['church_budget','club_budget','conference','reimbursement_to','reinbursement_to'])],
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
            'StaffAdventurer'  => \App\Models\StaffAdventurer::class,
            'MemberAdventurer' => \App\Models\MemberAdventurer::class,
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
