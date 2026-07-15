<?php

namespace App\Http\Controllers;

use App\Models\MemberAdventurer;
use App\Models\MasterGuideMemberFormSchema;
use App\Models\MemberMasterGuide;
use App\Models\MemberPathfinder;
use App\Models\MemberPathfinderInsuranceCard;
use App\Models\Club;
use App\Models\Account;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\User;
use Illuminate\Http\Request;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Str;
use App\Models\StaffAdventurer;
use App\Services\DocumentExportService;
use App\Services\ClubLogoService;
use App\Models\Member;
use App\Models\ClubCarpetaClassActivation;
use App\Models\ClubClass;
use App\Models\Staff;
use App\Models\MemberPastoralCare;
use App\Support\ClubHelper;
use App\Models\ClassMemberPathfinder;
use App\Services\PaymentReceiptService;
use Carbon\Carbon;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

use DB;
use Auth;
class MemberAdventurerController extends Controller
{
    /**
     * Finance summary used by the member list's Charges modal.  Keeping this
     * server-side is important: a concept may apply through an individual,
     * class, club-wide, or event-participant scope.
     */
    public function charges(Request $request, Member $member)
    {
        $this->authorizeMemberFinance($request, $member);

        $member->load(['club:id,club_name,club_email', 'class:id,class_name']);
        $charges = app(ParentPaymentController::class)
            ->expectedPaymentsForMembers(collect([$member]))
            ->map(fn (array $charge) => [
                ...$charge,
                'id' => (int) $charge['concept_id'],
                'concept' => $charge['concept_name'],
                'amount' => (float) $charge['expected_amount'],
                'event_required' => (bool) $charge['is_required'],
                // Event fee components are maintained by Event Planner, not here.
                'can_manage' => empty($charge['event_id']),
            ])
            ->values();

        return response()->json([
            'data' => [
                'member' => ['id' => $member->id, 'name' => ClubHelper::memberDetail($member)['name'] ?? 'Member'],
                'club' => ['id' => $member->club_id, 'name' => $member->club?->club_name],
                'charges' => $charges,
                'summary' => [
                    'expected' => round((float) $charges->sum('amount'), 2),
                    'paid' => round((float) $charges->sum('paid_amount'), 2),
                    'remaining' => round((float) $charges->sum('remaining_amount'), 2),
                ],
            ],
        ]);
    }

    public function updateCharge(Request $request, Member $member, PaymentConcept $paymentConcept)
    {
        $this->authorizeMemberFinance($request, $member);
        abort_unless((int) $paymentConcept->club_id === (int) $member->club_id && !$paymentConcept->event_id, 422, 'Event charges must be edited in Event Planner.');

        $payload = $request->validate([
            'concept' => ['required', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'payment_expected_by' => ['nullable', 'date'],
            'type' => ['required', 'in:mandatory,optional'],
        ]);
        $paymentConcept->update($payload);

        return response()->json(['data' => $paymentConcept->fresh()]);
    }

    public function destroyCharge(Request $request, Member $member, PaymentConcept $paymentConcept)
    {
        $this->authorizeMemberFinance($request, $member);
        abort_unless((int) $paymentConcept->club_id === (int) $member->club_id && !$paymentConcept->event_id, 422, 'Event charges must be managed in Event Planner.');

        // An individual charge is removed only for this member.  Other scopes
        // intentionally remain global; the UI makes that impact explicit.
        $individualScopes = $paymentConcept->scopes()
            ->where('scope_type', 'member')
            ->where('member_id', $member->id);
        if ($individualScopes->exists()) {
            $individualScopes->delete();
            if (!$paymentConcept->scopes()->whereNull('deleted_at')->exists()) {
                $paymentConcept->update(['status' => 'inactive']);
            }
        } else {
            $paymentConcept->update(['status' => 'inactive']);
        }

        return response()->json(['message' => 'Charge removed.']);
    }

    private function authorizeMemberFinance(Request $request, Member $member): void
    {
        abort_unless(in_array($request->user()?->profile_type, ['club_director', 'treasurer', 'superadmin'], true), 403);
        abort_unless(ClubHelper::clubIdsForUser($request->user())->contains((int) $member->club_id), 403);
    }

    protected function resolveClubDirectorName(Club $club): ?string
    {
        if (!empty($club->director_name)) {
            return $club->director_name;
        }

        if (!empty($club->user_id)) {
            return User::query()->where('id', $club->user_id)->value('name');
        }

        return null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'mark_insurance_paid' => 'nullable|boolean',
            'mark_enrollment_paid' => 'nullable|boolean',
        ]);

        $club = Club::findOrFail($request->input('club_id'));
        $clubType = strtolower($club->club_type ?? '');
        $parentId = auth()->user()?->profile_type === 'parent' && $clubType !== 'master_guide' ? auth()->id() : null;
        $directorName = $this->resolveClubDirectorName($club);

        if ($clubType === 'master_guide') {
            $validated = $request->validate([
                'applicant_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string|max:1000',
                'email' => 'nullable|email|max:255',
                'emergency_contact_name' => 'nullable|string|max:255',
                'emergency_contact_phone' => 'nullable|string|max:50',
                'emergency_contact_email' => 'nullable|email|max:255',
                'program_year' => 'required|integer|in:1,2',
                'custom_fields_json' => 'nullable|array',
                'mark_insurance_paid' => 'nullable|boolean',
                'mark_enrollment_paid' => 'nullable|boolean',
                'is_sda' => 'nullable|boolean',
                'baptism_date' => ['nullable', 'date'],
            ]);
            $validated = $this->masterGuideDetailPayload($validated, $club);

            $validated['club_id'] = $club->id;
            $validated['club_name'] = $club->club_name;
            $validated['director_name'] = $directorName;
            $validated['church_name'] = $club->church_name;
            $validated['status'] = 'active';

            $masterGuide = MemberMasterGuide::create($validated);

            $member = Member::create([
                'type' => 'master_guide',
                'id_data' => $masterGuide->id,
                'club_id' => $club->id,
                'class_id' => null,
                'parent_id' => null,
                'assigned_staff_id' => null,
                'status' => 'active',
                ...$this->spiritualProfilePayload($request),
            ]);

            $masterGuide->update(['member_id' => $member->id]);
            $this->syncPastoralCareForMember($member->fresh(), $club);

            if ($request->boolean('mark_insurance_paid')) {
                $this->handleInsurancePayment($club, $masterGuide, $member);
            }

            if ($request->boolean('mark_enrollment_paid')) {
                $this->handleEnrollmentPayment($club, $masterGuide, $member);
            }
        } elseif ($clubType === 'pathfinders') {
            $validated = $request->validate([
                'applicant_name' => 'required|string|max:255',
                'birthdate' => 'required|date',
                'grade' => 'nullable|string|max:50',
                'mailing_address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:50',
                'zip' => 'nullable|string|max:30',
                'school' => 'nullable|string|max:255',
                'cell_number' => 'nullable|string|max:50',
                'email_address' => 'nullable|email|max:255',
                'father_guardian_name' => 'required|string|max:255',
                'father_guardian_email' => 'nullable|email|max:255',
                'father_guardian_phone' => 'required|string|max:50',
                'mother_guardian_name' => 'nullable|string|max:255',
                'mother_guardian_email' => 'nullable|email|max:255',
                'mother_guardian_phone' => 'nullable|string|max:50',
                'pickup_authorized_people' => 'nullable|array',
                'pickup_authorized_people.*' => 'string|max:255',
                'consent_acknowledged' => 'nullable|boolean',
                'photo_release' => 'nullable|boolean',
                'health_history' => 'nullable|string',
                'disabilities' => 'nullable|string',
                'medication_allergies' => 'nullable|string',
                'food_allergies' => 'nullable|string',
                'dietary_considerations' => 'nullable|string',
                'physical_restrictions' => 'nullable|string',
                'immunization_notes' => 'nullable|string',
                'current_medications' => 'nullable|string',
                'physician_name' => 'nullable|string|max:255',
                'physician_phone' => 'nullable|string|max:50',
                'emergency_contact_name' => 'nullable|string|max:255',
                'emergency_contact_phone' => 'nullable|string|max:50',
                'insurance_provider' => 'nullable|string|max:255',
                'insurance_number' => 'nullable|string|max:255',
                'parent_guardian_signature' => 'nullable|string|max:255',
                'signed_at' => 'nullable|date',
                'mark_insurance_paid' => 'nullable|boolean',
                'mark_enrollment_paid' => 'nullable|boolean',
                'is_sda' => 'nullable|boolean',
                'baptism_date' => ['nullable', 'date'],
            ]);
            $validated = $this->memberDetailPayload($validated);

            $validated['club_id'] = $club->id;
            $validated['club_name'] = $club->club_name;
            $validated['director_name'] = $directorName;
            $validated['church_name'] = $club->church_name;
            $validated['status'] = 'active';

            $tempMember = MemberPathfinder::create($validated);

            $member = Member::create([
                'type' => 'pathfinders',
                'id_data' => $tempMember->id,
                'club_id' => $club->id,
                'class_id' => null,
                'parent_id' => $parentId,
                'assigned_staff_id' => null,
                'status' => 'active',
                ...$this->spiritualProfilePayload($request),
            ]);

            $tempMember->update(['member_id' => $member->id]);
            $this->syncPastoralCareForMember($member->fresh(), $club);

            if ($request->boolean('mark_insurance_paid')) {
                $this->handleInsurancePayment($club, $tempMember, $member);
            }

            if ($request->boolean('mark_enrollment_paid')) {
                $this->handleEnrollmentPayment($club, $tempMember, $member);
            }
        } else {
            $validated = $request->validate([
                'club_name' => 'nullable|string|max:255',
                'director_name' => 'nullable|string|max:255',
                'church_name' => 'nullable|string|max:255',

                'applicant_name' => 'required|string|max:255',
                'birthdate' => 'required|date',
                'age' => 'required|integer|min:1|max:99',
                'grade' => 'required|string|max:20',
                'mailing_address' => 'required|string',
                'cell_number' => 'required|string',
                'emergency_contact' => 'required|string',

                'investiture_classes' => 'nullable|array',

                'allergies' => 'nullable|string',
                'physical_restrictions' => 'nullable|string',
                'health_history' => 'nullable|string',

                'parent_name' => 'required|string|max:255',
                'parent_cell' => 'required|string|max:255',
                'home_address' => 'required|string',
                'email_address' => 'required|email',
                'signature' => 'required|string|max:255',
                'mark_insurance_paid' => 'nullable|boolean',
                'mark_enrollment_paid' => 'nullable|boolean',
                'is_sda' => 'nullable|boolean',
                'baptism_date' => ['nullable', 'date'],
            ]);
            $validated = $this->memberDetailPayload($validated);

            $validated['status'] = 'active';
            $validated['club_id'] = $club->id;
            $validated['club_name'] = $club->club_name;
            $validated['director_name'] = $directorName;
            $validated['church_name'] = $club->church_name;

            $member = MemberAdventurer::create($validated);

            $memberRecord = Member::firstOrCreate(
                [
                    'type' => 'adventurers',
                    'id_data' => $member->id,
                ],
                [
                    'club_id' => $club->id,
                    'class_id' => null,
                    'parent_id' => $parentId,
                    'assigned_staff_id' => null,
                    'status' => 'active',
                    ...$this->spiritualProfilePayload($request),
                ]
            );
            $memberRecord->update($this->spiritualProfilePayload($request));
            $this->syncPastoralCareForMember($memberRecord->fresh(), $club);

            if ($request->boolean('mark_insurance_paid')) {
                $this->handleInsurancePayment($club, $member, $memberRecord);
            }

            if ($request->boolean('mark_enrollment_paid')) {
                $this->handleEnrollmentPayment($club, $member, $memberRecord);
            }
        }

        if (auth()->user()?->profile_type === 'parent') {
            return redirect()->route('parent.dashboard')->with('success', 'Member registered successfully.');
        }

        return redirect()->back()->with('success', 'Member registered successfully.');
    }

    protected function handleInsurancePayment(Club $club, $memberDetail, Member $memberRecord): void
    {
        if (($club->evaluation_system ?? 'honors') !== 'carpetas') {
            return;
        }

        $club->loadMissing('district.association');
        $association = $club->district?->association;
        $insuranceAmount = $association?->insurance_payment_amount;

        if (!$insuranceAmount || (float) $insuranceAmount <= 0) {
            return;
        }

        $memberDetail->update([
            'insurance_paid' => true,
            'insurance_paid_at' => now(),
        ]);

        $concept = PaymentConcept::firstOrCreate(
            [
                'club_id' => $club->id,
                'concept' => 'Seguro de membresía',
                'pay_to' => 'church_budget',
            ],
            [
                'type' => 'mandatory',
                'status' => 'active',
                'created_by' => auth()->id(),
            ]
        );

        $account = Account::firstOrCreate(
            ['club_id' => $club->id, 'pay_to' => 'church_budget'],
            ['label' => 'Church Budget', 'balance' => 0]
        );

        $account->increment('balance', (float) $insuranceAmount);

        $payment = Payment::create([
            'club_id'             => $club->id,
            'payment_concept_id'  => $concept->id,
            'concept_text'        => 'Seguro de membresía — ' . ($memberDetail->applicant_name ?? ''),
            'pay_to'              => 'church_budget',
            'account_id'          => $account->id,
            'member_id'           => $memberRecord->id,
            'amount_paid'         => (float) $insuranceAmount,
            'expected_amount'     => (float) $insuranceAmount,
            'payment_date'        => now()->toDateString(),
            'payment_type'        => 'insurance',
            'balance_due_after'   => 0,
            'received_by_user_id' => auth()->id(),
        ]);

        app(PaymentReceiptService::class)->syncForPayment($payment);
    }

    protected function handleEnrollmentPayment(Club $club, $memberDetail, Member $memberRecord): void
    {
        $enrollmentAmount = (float) ($club->enrollment_payment_amount ?? 0);

        if ($enrollmentAmount <= 0) {
            return;
        }

        $memberDetail->update([
            'enrollment_paid' => true,
            'enrollment_paid_at' => now(),
        ]);

        $concept = PaymentConcept::firstOrCreate(
            [
                'club_id' => $club->id,
                'concept' => 'Cuota de inscripción',
                'pay_to' => 'club_budget',
            ],
            [
                'type' => 'mandatory',
                'status' => 'active',
                'created_by' => auth()->id(),
                'amount' => $enrollmentAmount,
                'reusable' => true,
            ]
        );

        $concept->update([
            'amount' => $enrollmentAmount,
            'status' => 'active',
            'type' => 'mandatory',
            'reusable' => true,
        ]);

        $account = Account::firstOrCreate(
            ['club_id' => $club->id, 'pay_to' => 'club_budget'],
            ['label' => 'Club Budget', 'balance' => 0]
        );

        $account->increment('balance', $enrollmentAmount);

        $payment = Payment::create([
            'club_id' => $club->id,
            'payment_concept_id' => $concept->id,
            'concept_text' => 'Cuota de inscripción — ' . ($memberDetail->applicant_name ?? ''),
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'member_id' => $memberRecord->id,
            'amount_paid' => $enrollmentAmount,
            'expected_amount' => $enrollmentAmount,
            'payment_date' => now()->toDateString(),
            'payment_type' => 'enrollment',
            'balance_due_after' => 0,
            'received_by_user_id' => auth()->id(),
        ]);

        app(PaymentReceiptService::class)->syncForPayment($payment);
    }

    public function destroy(Request $request, $id)
    {
        $validated = $request->validate([
            'notes_deleted' => ['nullable', 'string', 'max:2000'],
            'member_type' => ['nullable', 'string', 'in:adventurers,pathfinders,temp_pathfinder,master_guide'],
            'member_record_id' => ['nullable', 'integer', 'exists:members,id'],
        ]);

        $memberRecord = !empty($validated['member_record_id'])
            ? Member::find($validated['member_record_id'])
            : null;
        $memberType = $validated['member_type'] ?? $memberRecord?->type ?? 'adventurers';

        if (in_array($memberType, ['pathfinders', 'temp_pathfinder'], true)) {
            $pathfinder = MemberPathfinder::findOrFail($memberRecord?->id_data ?? $id);
            $allowedClubIds = ClubHelper::clubIdsForUser(Auth::user())->map(fn ($clubId) => (int) $clubId)->all();
            if (!in_array((int) $pathfinder->club_id, $allowedClubIds, true)) {
                abort(403, 'Unauthorized');
            }

            $pathfinder->update(['status' => 'deleted']);

            Member::query()
                ->whereIn('type', ['pathfinders', 'temp_pathfinder'])
                ->where('club_id', $pathfinder->club_id)
                ->where('id_data', $pathfinder->id)
                ->update(['status' => 'deleted']);

            return response()->json(['message' => 'Member deleted.']);
        }

        if ($memberType === 'master_guide') {
            $masterGuide = MemberMasterGuide::findOrFail($memberRecord?->id_data ?? $id);
            $allowedClubIds = ClubHelper::clubIdsForUser(Auth::user())->map(fn ($clubId) => (int) $clubId)->all();
            if (!in_array((int) $masterGuide->club_id, $allowedClubIds, true)) {
                abort(403, 'Unauthorized');
            }

            $masterGuide->update([
                'status' => 'deleted',
                'notes_deleted' => $validated['notes_deleted'] ?? null,
            ]);

            Member::query()
                ->where('type', 'master_guide')
                ->where('club_id', $masterGuide->club_id)
                ->where('id_data', $masterGuide->id)
                ->update(['status' => 'deleted']);

            return response()->json(['message' => 'Member deleted.']);
        }

        $member = MemberAdventurer::findOrFail($memberRecord?->id_data ?? $id);
        $allowedClubIds = ClubHelper::clubIdsForUser(Auth::user())->map(fn ($clubId) => (int) $clubId)->all();
        if (!in_array((int) $member->club_id, $allowedClubIds, true)) {
            abort(403, 'Unauthorized');
        }

        $member->update([
            'status' => 'deleted',
            'notes_deleted' => $validated['notes_deleted'] ?? null,
        ]);

        Member::query()
            ->where('type', 'adventurers')
            ->where('club_id', $member->club_id)
            ->where('id_data', $member->id)
            ->update(['status' => 'deleted']);

        return response()->json(['message' => 'Member deleted.']);
    }

    public function update(Request $request, $id)
    {
        $validatedClub = $request->validate([
            'club_id' => 'required|exists:clubs,id',
            'mark_insurance_paid' => 'nullable|boolean',
            'mark_enrollment_paid' => 'nullable|boolean',
        ]);

        $club = Club::findOrFail($validatedClub['club_id']);
        $allowedClubIds = ClubHelper::clubIdsForUser(Auth::user())->map(fn ($clubId) => (int) $clubId)->all();
        if (!in_array((int) $club->id, $allowedClubIds, true)) {
            abort(403, 'Unauthorized');
        }

        $clubType = strtolower($club->club_type ?? '');
        $directorName = $this->resolveClubDirectorName($club);

        if ($clubType === 'master_guide') {
            $validated = $request->validate([
                'applicant_name' => 'required|string|max:255',
                'phone' => 'nullable|string|max:50',
                'address' => 'nullable|string|max:1000',
                'email' => 'nullable|email|max:255',
                'emergency_contact_name' => 'nullable|string|max:255',
                'emergency_contact_phone' => 'nullable|string|max:50',
                'emergency_contact_email' => 'nullable|email|max:255',
                'program_year' => 'required|integer|in:1,2',
                'custom_fields_json' => 'nullable|array',
                'mark_insurance_paid' => 'nullable|boolean',
                'mark_enrollment_paid' => 'nullable|boolean',
                'is_sda' => 'nullable|boolean',
                'baptism_date' => ['nullable', 'date'],
            ]);
            $validated = $this->masterGuideDetailPayload($validated, $club);

            $validated['club_id'] = $club->id;
            $validated['club_name'] = $club->club_name ?? null;
            $validated['director_name'] = $directorName;
            $validated['church_name'] = $club->church_name ?? null;

            $member = MemberMasterGuide::query()
                ->where('club_id', $club->id)
                ->findOrFail($id);
            $wasInsurancePaid = (bool) $member->insurance_paid;
            $wasEnrollmentPaid = (bool) $member->enrollment_paid;
            $member->update($validated);

            $memberRecord = Member::query()
                ->where('type', 'master_guide')
                ->where('id_data', $member->id)
                ->where('club_id', $club->id)
                ->first();

            if ($memberRecord) {
                $memberRecord->update($this->spiritualProfilePayload($request));
                $this->syncPastoralCareForMember($memberRecord->fresh(), $club);

                if ($request->boolean('mark_insurance_paid') && !$wasInsurancePaid) {
                    $this->handleInsurancePayment($club, $member->fresh(), $memberRecord);
                }

                if ($request->boolean('mark_enrollment_paid') && !$wasEnrollmentPaid) {
                    $this->handleEnrollmentPayment($club, $member->fresh(), $memberRecord);
                }
            }

            return redirect()->back()->with('success', 'Master Guide member updated successfully.');
        }

        if ($clubType === 'pathfinders') {
            $validated = $request->validate([
                'applicant_name' => 'required|string|max:255',
                'birthdate' => 'required|date',
                'grade' => 'nullable|string|max:50',
                'mailing_address' => 'nullable|string|max:255',
                'city' => 'nullable|string|max:255',
                'state' => 'nullable|string|max:50',
                'zip' => 'nullable|string|max:30',
                'school' => 'nullable|string|max:255',
                'cell_number' => 'nullable|string|max:50',
                'email_address' => 'nullable|email|max:255',
                'father_guardian_name' => 'required|string|max:255',
                'father_guardian_email' => 'nullable|email|max:255',
                'father_guardian_phone' => 'required|string|max:50',
                'mother_guardian_name' => 'nullable|string|max:255',
                'mother_guardian_email' => 'nullable|email|max:255',
                'mother_guardian_phone' => 'nullable|string|max:50',
                'pickup_authorized_people' => 'nullable|array',
                'pickup_authorized_people.*' => 'string|max:255',
                'consent_acknowledged' => 'nullable|boolean',
                'photo_release' => 'nullable|boolean',
                'health_history' => 'nullable|string',
                'disabilities' => 'nullable|string',
                'medication_allergies' => 'nullable|string',
                'food_allergies' => 'nullable|string',
                'dietary_considerations' => 'nullable|string',
                'physical_restrictions' => 'nullable|string',
                'immunization_notes' => 'nullable|string',
                'current_medications' => 'nullable|string',
                'physician_name' => 'nullable|string|max:255',
                'physician_phone' => 'nullable|string|max:50',
                'emergency_contact_name' => 'nullable|string|max:255',
                'emergency_contact_phone' => 'nullable|string|max:50',
                'insurance_provider' => 'nullable|string|max:255',
                'insurance_number' => 'nullable|string|max:255',
                'parent_guardian_signature' => 'nullable|string|max:255',
                'signed_at' => 'nullable|date',
                'mark_insurance_paid' => 'nullable|boolean',
                'mark_enrollment_paid' => 'nullable|boolean',
                'is_sda' => 'nullable|boolean',
                'baptism_date' => ['nullable', 'date'],
            ]);
            $validated = $this->memberDetailPayload($validated);

            $validated['club_name'] = $club->club_name ?? null;
            $validated['director_name'] = $directorName;
            $validated['church_name'] = $club->church_name ?? null;

            $member = MemberPathfinder::findOrFail($id);
            $wasInsurancePaid = (bool) $member->insurance_paid;
            $wasEnrollmentPaid = (bool) $member->enrollment_paid;
            $member->update($validated);

            $memberRecord = Member::query()
                ->whereIn('type', ['pathfinders', 'temp_pathfinder'])
                ->where('id_data', $member->id)
                ->where('club_id', $club->id)
                ->first();

            if ($memberRecord) {
                $memberRecord->update($this->spiritualProfilePayload($request));
                $this->syncPastoralCareForMember($memberRecord->fresh(), $club);

                if ($request->boolean('mark_insurance_paid') && !$wasInsurancePaid) {
                    $this->handleInsurancePayment($club, $member->fresh(), $memberRecord);
                }

                if ($request->boolean('mark_enrollment_paid') && !$wasEnrollmentPaid) {
                    $this->handleEnrollmentPayment($club, $member->fresh(), $memberRecord);
                }
            }

            return redirect()->back()->with('success', 'Pathfinder member updated successfully.');
        }

        $validated = $request->validate([
            'club_name' => 'required|string|max:255',
            'director_name' => 'required|string|max:255',
            'church_name' => 'required|string|max:255',
            'applicant_name' => 'required|string|max:255',
            'birthdate' => 'required|date',
            'age' => 'required|integer|min:1|max:99',
            'grade' => 'required|string|max:20',
            'mailing_address' => 'required|string',
            'cell_number' => 'required|string',
            'emergency_contact' => 'required|string',
            'investiture_classes' => 'nullable|array',
            'allergies' => 'nullable|string',
            'physical_restrictions' => 'nullable|string',
            'health_history' => 'nullable|string',
            'parent_name' => 'required|string|max:255',
            'parent_cell' => 'required|string|max:255',
            'home_address' => 'required|string',
            'email_address' => 'required|email',
            'signature' => 'required|string|max:255',
            'mark_insurance_paid' => 'nullable|boolean',
            'mark_enrollment_paid' => 'nullable|boolean',
            'is_sda' => 'nullable|boolean',
            'baptism_date' => ['nullable', 'date'],
        ]);
        $validated = $this->memberDetailPayload($validated);

        $validated['club_id'] = $club->id;
        $validated['club_name'] = $club->club_name ?? $validated['club_name'];
        $validated['director_name'] = $directorName ?? $validated['director_name'];
        $validated['church_name'] = $club->church_name ?? $validated['church_name'];

        $member = MemberAdventurer::findOrFail($id);
        $wasInsurancePaid = (bool) $member->insurance_paid;
        $wasEnrollmentPaid = (bool) $member->enrollment_paid;
        $member->update($validated);

        $memberRecord = Member::query()
            ->where('type', 'adventurers')
            ->where('id_data', $member->id)
            ->where('club_id', $club->id)
            ->first();

        if ($memberRecord) {
            $memberRecord->update($this->spiritualProfilePayload($request));
            $this->syncPastoralCareForMember($memberRecord->fresh(), $club);

            if ($request->boolean('mark_insurance_paid') && !$wasInsurancePaid) {
                $this->handleInsurancePayment($club, $member->fresh(), $memberRecord);
            }

            if ($request->boolean('mark_enrollment_paid') && !$wasEnrollmentPaid) {
                $this->handleEnrollmentPayment($club, $member->fresh(), $memberRecord);
            }
        }

        return redirect()->back()->with('success', 'Adventurer member updated successfully.');
    }

    protected function memberDetailPayload(array $validated): array
    {
        return collect($validated)
            ->except(['is_sda', 'baptism_date', 'mark_insurance_paid', 'mark_enrollment_paid'])
            ->all();
    }

    protected function masterGuideDetailPayload(array $validated, ?Club $club = null): array
    {
        $payload = collect($validated)
            ->except(['is_sda', 'baptism_date', 'mark_insurance_paid', 'mark_enrollment_paid'])
            ->all();

        $payload['program_year'] = (int) ($payload['program_year'] ?? 1);
        if (!in_array($payload['program_year'], [1, 2], true)) {
            $payload['program_year'] = 1;
        }

        $customFields = is_array($payload['custom_fields_json'] ?? null)
            ? $payload['custom_fields_json']
            : [];
        $payload['custom_fields_json'] = $this->sanitizeMasterGuideCustomFieldValues(
            $customFields,
            $this->masterGuideSchemaFieldsForClubId($club?->id)
        );

        return $payload;
    }

    protected function masterGuideSchemaFieldsForClubId(?int $clubId): array
    {
        if (!$clubId) {
            return [];
        }

        $schema = MasterGuideMemberFormSchema::query()
            ->where('club_id', $clubId)
            ->value('schema_json');

        if (is_string($schema)) {
            $schema = json_decode($schema, true) ?: [];
        }

        return $this->sanitizeMasterGuideSchema(is_array($schema) ? $schema : [])['fields'] ?? [];
    }

    protected function sanitizeMasterGuideCustomFieldValues(array $values, array $schemaFields): array
    {
        if (empty($schemaFields)) {
            return [];
        }

        $clean = [];
        foreach ($schemaFields as $field) {
            $key = $field['key'] ?? null;
            if (!$key) {
                continue;
            }

            $value = $values[$key] ?? null;
            $clean[$key] = ($field['type'] ?? null) === 'checkbox' ? (bool) $value : $value;
        }

        return $clean;
    }

    protected function masterGuideCustomFieldsDisplay(array $values, array $schemaFields): array
    {
        $clean = $this->sanitizeMasterGuideCustomFieldValues($values, $schemaFields);

        return collect($schemaFields)
            ->map(function ($field) use ($clean) {
                $key = $field['key'] ?? null;
                if (!$key || !array_key_exists($key, $clean)) {
                    return null;
                }

                $value = $clean[$key];
                if ($value === null || $value === '') {
                    return null;
                }

                return [
                    'key' => $key,
                    'label' => $field['label'] ?? $key,
                    'type' => $field['type'] ?? 'text',
                    'value' => $value,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    public function masterGuideSchema($id)
    {
        $user = Auth::user();
        $club = ClubHelper::clubForUser($user, $id);

        $schema = MasterGuideMemberFormSchema::query()
            ->where('club_id', $club->id)
            ->first();

        return response()->json([
            'schema_json' => $schema?->schema_json ?: ['mode' => 'single', 'fields' => []],
            'updated_at' => optional($schema?->updated_at)->toDateTimeString(),
        ]);
    }

    public function updateMasterGuideSchema(Request $request, $id)
    {
        $user = Auth::user();
        $club = ClubHelper::clubForUser($user, $id);

        $validated = $request->validate([
            'schema_json' => ['nullable', 'array'],
        ]);

        $schemaJson = $this->sanitizeMasterGuideSchema($validated['schema_json'] ?? []);

        $schema = MasterGuideMemberFormSchema::query()->updateOrCreate(
            ['club_id' => $club->id],
            [
                'schema_json' => $schemaJson,
                'updated_by' => $user?->id,
            ]
        );

        return response()->json([
            'schema_json' => $schema->schema_json ?: ['mode' => 'single', 'fields' => []],
            'updated_at' => optional($schema->updated_at)->toDateTimeString(),
        ]);
    }

    public function updateMasterGuideYear(Request $request, $id)
    {
        $validated = $request->validate([
            'program_year' => ['required', 'integer', 'in:1,2'],
        ]);

        $member = MemberMasterGuide::query()->findOrFail($id);
        $allowedClubIds = ClubHelper::clubIdsForUser(Auth::user())->map(fn ($clubId) => (int) $clubId)->all();

        if (!in_array((int) $member->club_id, $allowedClubIds, true)) {
            abort(403, 'Unauthorized');
        }

        $programYear = (int) $validated['program_year'];
        $member->update(['program_year' => $programYear]);

        return response()->json([
            'program_year' => $programYear,
            'program_year_label' => 'Year ' . $programYear,
        ]);
    }

    protected function sanitizeMasterGuideSchema(?array $schema): array
    {
        $allowedTypes = ['text', 'textarea', 'number', 'date', 'time', 'select', 'checkbox', 'email', 'phone'];
        $fields = is_array($schema['fields'] ?? null) ? $schema['fields'] : [];
        $normalized = [];
        $seenKeys = [];

        foreach ($fields as $field) {
            if (!is_array($field)) {
                continue;
            }

            $label = trim((string) ($field['label'] ?? ''));
            $key = Str::of((string) ($field['key'] ?? $label))
                ->lower()
                ->replaceMatches('/[^a-z0-9]+/', '_')
                ->trim('_')
                ->toString();

            if ($label === '' || $key === '' || isset($seenKeys[$key])) {
                continue;
            }

            $type = in_array($field['type'] ?? 'text', $allowedTypes, true)
                ? $field['type']
                : 'text';

            $help = trim((string) ($field['help'] ?? ''));
            $options = [];
            if ($type === 'select' && is_array($field['options'] ?? null)) {
                $options = collect($field['options'])
                    ->map(fn ($option) => trim((string) $option))
                    ->filter()
                    ->values()
                    ->all();
            }

            $normalized[] = array_filter([
                'key' => $key,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
                'help' => $help ?: null,
                'options' => $options ?: null,
            ], fn ($value) => $value !== null);

            $seenKeys[$key] = true;
        }

        return [
            'mode' => 'single',
            'fields' => $normalized,
        ];
    }

    protected function spiritualProfilePayload(Request $request): array
    {
        $isSda = $request->boolean('is_sda', true);

        return [
            'is_sda' => $isSda,
            'baptism_date' => $isSda ? ($request->input('baptism_date') ?: null) : null,
        ];
    }

    protected function syncPastoralCareForMember(Member $member, Club $club): void
    {
        $districtId = $club->district_id;
        if (!$districtId && $club->church_id) {
            $districtId = \App\Models\Church::query()
                ->whereKey($club->church_id)
                ->value('district_id');
        }

        if (!$member->is_sda) {
            MemberPastoralCare::query()->updateOrCreate(
                ['member_id' => $member->id],
                [
                    'district_id' => $districtId,
                    'status' => 'active',
                    'baptized_at' => null,
                    'new_believer_until' => null,
                    'updated_by' => auth()->id(),
                ]
            );

            return;
        }

        if ($member->baptism_date) {
            MemberPastoralCare::query()
                ->where('member_id', $member->id)
                ->update([
                    'district_id' => $districtId,
                    'baptized_at' => $member->baptism_date,
                    'new_believer_until' => Carbon::parse($member->baptism_date)->addMonthsNoOverflow(18)->toDateString(),
                    'status' => 'new_believer',
                    'updated_by' => auth()->id(),
                ]);
        }
    }

    public function updateForParent(Request $request, $id)
    {
        $member = MemberAdventurer::findOrFail($id);
        $parentId = auth()->id();

        $link = Member::where('type', 'adventurers')
            ->where('id_data', $member->id)
            ->where('parent_id', $parentId)
            ->firstOrFail();

        $validated = $request->validate([
            'applicant_name' => 'required|string|max:255',
            'birthdate' => 'required|date',
            'age' => 'required|integer|min:1|max:99',
            'grade' => 'required|string|max:20',
            'mailing_address' => 'required|string',
            'cell_number' => 'required|string',
            'emergency_contact' => 'required|string',
            'investiture_classes' => 'nullable|array',
            'allergies' => 'nullable|string',
            'physical_restrictions' => 'nullable|string',
            'health_history' => 'nullable|string',
            'parent_name' => 'required|string|max:255',
            'parent_cell' => 'required|string|max:255',
            'home_address' => 'required|string',
            'email_address' => 'required|email',
            'signature' => 'required|string|max:255',
        ]);

        $member->update($validated);

        return redirect()->back()->with('success', 'Child updated.');
    }


    public function byClub($id, Request $request)
    {
        $user = Auth::user();
        $club = ClubHelper::clubForUser($user, $id);
        $members = $this->buildMembersPayloadForClub((int) $club->id);

        return response()->json([
            'club' => $club,
            'members' => $members,
        ]);
    }

    public function classSummaryPdf(Request $request, $id, ClubLogoService $clubLogoService)
    {
        $user = Auth::user();
        $club = ClubHelper::clubForUser($user, $id);

        $options = [
            'include_contact' => $request->boolean('include_contact'),
            'include_parent' => $request->boolean('include_parent'),
            'include_dob' => $request->boolean('include_dob'),
            'include_address' => $request->boolean('include_address'),
        ];

        $members = $this->buildMembersPayloadForClub((int) $club->id);
        $classes = ClubClass::query()
            ->where('club_id', $club->id)
            ->orderBy('class_order')
            ->orderBy('class_name')
            ->get(['id', 'class_name', 'class_order']);

        $this->attachAssignedStaffNamesToClasses($classes);

        $classBuckets = $classes->map(function ($class) use ($members) {
            $classMembers = collect($members)
                ->filter(function ($member) use ($class) {
                    $currentClassId = (int) ($member['current_class_id'] ?? 0);
                    if ($currentClassId > 0) {
                        return $currentClassId === (int) $class->id;
                    }
                    $assignments = collect($member['class_assignments'] ?? []);
                    return $assignments->contains(fn ($a) => !empty($a['active']) && (int) ($a['club_class_id'] ?? 0) === (int) $class->id);
                })
                ->sortBy(fn ($member) => mb_strtolower((string) ($member['applicant_name'] ?? '')))
                ->values();

            return [
                'id' => $class->id,
                'class_name' => $class->class_name,
                'class_order' => $class->class_order,
                'assigned_staff_name' => $class->assigned_staff_name ?? '—',
                'members' => $classMembers,
            ];
        })->values();

        $pdf = Pdf::loadView('pdf.class_members_summary', [
            'club' => $club,
            'classes' => $classBuckets,
            'options' => $options,
            'generatedAt' => now()->toDateTimeString(),
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
        ]);

        $filename = 'class-members-summary-' . $club->id . '-' . now()->format('Ymd-His') . '.pdf';

        return $pdf->download($filename);
    }
    public function exportWord($id, DocumentExportService $exportService)
    {
        $member = MemberAdventurer::findOrFail($id);
        $outputDir = storage_path('app/temp');

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $outputPath = $exportService->generateMemberDoc($member, $outputDir);

        return response()->download($outputPath)->deleteFileAfterSend(true);
    }

    public function exportPathfinderPdf($id, ClubLogoService $clubLogoService)
    {
        $member = MemberPathfinder::with('insuranceCard')->findOrFail($id);
        $club = $member->club;

        $pdf = Pdf::loadView('pdf.pathfinder_application', [
            'member' => $member,
            'club' => $club,
            'generatedAt' => now()->toDateTimeString(),
            'clubLogoDataUri' => $clubLogoService->dataUri($club),
        ])->setPaper('letter', 'portrait');

        $filename = 'pathfinder-application-' . Str::slug($member->applicant_name ?: 'member') . '.pdf';

        return $pdf->download($filename);
    }

    public function uploadPathfinderInsuranceCard(Request $request, $id)
    {
        $member = MemberPathfinder::with('insuranceCard')->findOrFail($id);
        $clubId = $member->club_id ?: $member->member?->club_id;
        $allowedClubIds = ClubHelper::clubIdsForUser(Auth::user())->map(fn ($value) => (int) $value)->all();

        if ($clubId && !in_array((int) $clubId, $allowedClubIds, true)) {
            abort(403, 'Unauthorized');
        }

        $validated = $request->validate([
            'insurance_card_image' => 'required|image|max:10240',
        ]);

        $oldPath = $member->insuranceCard?->path;
        $oldDisk = $member->insuranceCard?->disk ?: 'public';

        $path = $validated['insurance_card_image']->store('pathfinder-insurance-cards', 'public');

        $insuranceCard = MemberPathfinderInsuranceCard::updateOrCreate(
            ['member_pathfinder_id' => $member->id],
            [
                'disk' => 'public',
                'path' => $path,
                'original_name' => $validated['insurance_card_image']->getClientOriginalName(),
                'mime_type' => $validated['insurance_card_image']->getClientMimeType(),
                'uploaded_by' => Auth::id(),
            ]
        );

        if ($oldPath && $oldPath !== $path) {
            Storage::disk($oldDisk)->delete($oldPath);
        }

        return response()->json([
            'message' => 'Insurance card uploaded successfully.',
            'insurance_card_url' => $insuranceCard->url,
        ]);
    }

    public function assignMember(Request $request)
    {
        $data = $request->validate([
            'member_id' => 'nullable|integer|exists:members,id',
            // Backward compatibility: frontend previously sent members_adventurer_id (either adventurer id or temp id)
            'members_adventurer_id' => 'nullable|integer',
            'club_class_id' => 'required|integer',
            'role' => 'nullable|string|max:50',
            'assigned_at' => 'nullable|date',
        ]);

        $member = null;
        if (!empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        } elseif (!empty($data['members_adventurer_id'])) {
            $member = Member::where('type', 'adventurers')->where('id_data', $data['members_adventurer_id'])->first()
                ?? Member::where('type', 'temp_pathfinder')->where('id_data', $data['members_adventurer_id'])->first();
        }

        if (!$member) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        $requestedClassId = (int) $data['club_class_id'];
        $clubClass = ClubClass::query()
            ->where('id', $requestedClassId)
            ->where('club_id', $member->club_id)
            ->first();

        if (!$clubClass) {
            $activation = ClubCarpetaClassActivation::query()
                ->with('unionClassCatalog')
                ->where('id', $requestedClassId)
                ->where('club_id', $member->club_id)
                ->first();

            if ($activation) {
                $clubClass = ClubClass::firstOrCreate(
                    [
                        'club_id' => $member->club_id,
                        'union_class_catalog_id' => $activation->union_class_catalog_id,
                    ],
                    [
                        'class_order' => $activation->unionClassCatalog?->sort_order,
                        'class_name' => $activation->unionClassCatalog?->name,
                    ]
                );

                $data['club_class_id'] = $clubClass->id;
            }
        }

        if (!$clubClass) {
            return response()->json(['message' => 'Selected class does not belong to the member club.'], 422);
        }

        $newStaffId = $clubClass->staff()->pluck('staff.id')->first();
        if (!$newStaffId && $clubClass->union_class_catalog_id) {
            $newStaffId = ClubCarpetaClassActivation::query()
                ->where('club_id', $member->club_id)
                ->where('union_class_catalog_id', $clubClass->union_class_catalog_id)
                ->value('assigned_staff_id');
        }

        $role = $data['role'] ?? 'student';
        $assignedAt = $data['assigned_at'] ?? now()->toDateString();

        if ($member->type === 'adventurers') {
            $adventurerId = $member->id_data;
            if (!$adventurerId) {
                return response()->json(['message' => 'Adventurer detail missing (id_data)'], 422);
            }

            DB::table('class_member_adventurer')
                ->where('members_adventurer_id', $adventurerId)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            DB::table('class_member_adventurer')->insert([
                'members_adventurer_id' => $adventurerId,
                'club_class_id' => $data['club_class_id'],
                'role' => $role,
                'assigned_at' => $assignedAt,
                'active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $member->class_id = $data['club_class_id'];
            $member->assigned_staff_id = $newStaffId;
            $member->save();

            return response()->json(['message' => 'Member assigned successfully']);
        }

        if (in_array($member->type, ['temp_pathfinder', 'pathfinders'], true)) {
            ClassMemberPathfinder::where('member_id', $member->id)
                ->where('active', true)
                ->update([
                    'active' => false,
                    'finished_at' => now(),
                    'updated_at' => now(),
                ]);

            ClassMemberPathfinder::create([
                'member_id' => $member->id,
                'club_class_id' => $data['club_class_id'],
                'role' => $role,
                'assigned_at' => $assignedAt,
                'active' => true,
            ]);

            $member->class_id = $data['club_class_id'];
            $member->assigned_staff_id = $newStaffId;
            $member->save();

            return response()->json(['message' => 'Member assigned successfully']);
        }

        return response()->json(['message' => 'Unsupported member type'], 422);
    }

    public function undoLastAssignment(Request $request)
    {
        $data = $request->validate([
            'member_id' => 'nullable|integer|exists:members,id',
            'members_adventurer_id' => 'nullable|integer',
        ]);

        $member = null;
        if (!empty($data['member_id'])) {
            $member = Member::find($data['member_id']);
        } elseif (!empty($data['members_adventurer_id'])) {
            $member = Member::where('type', 'adventurers')->where('id_data', $data['members_adventurer_id'])->first()
                ?? Member::where('type', 'temp_pathfinder')->where('id_data', $data['members_adventurer_id'])->first();
        }

        if (!$member) {
            return response()->json(['message' => 'Member not found'], 404);
        }

        if ($member->type === 'adventurers') {
            $adventurerId = $member->id_data;
            if (!$adventurerId) {
                return response()->json(['message' => 'Adventurer detail missing (id_data)'], 422);
            }

            $lastAssignment = DB::table('class_member_adventurer')
                ->where('members_adventurer_id', $adventurerId)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if (!$lastAssignment) {
                return response()->json(['message' => 'No assignment found to undo'], 404);
            }

            DB::table('class_member_adventurer')
                ->where('id', $lastAssignment->id)
                ->update([
                    'active' => false,
                    'finished_at' => now(),
                    'undone_at' => now(),
                    'updated_at' => now(),
                ]);

            $previous = DB::table('class_member_adventurer')
                ->where('members_adventurer_id', $adventurerId)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if ($previous) {
                DB::table('class_member_adventurer')
                    ->where('id', $previous->id)
                    ->update([
                        'active' => true,
                        'finished_at' => null,
                        'updated_at' => now(),
                    ]);
                $clubClass = ClubClass::find($previous->club_class_id);
                $member->class_id = $previous->club_class_id;
                $member->assigned_staff_id = $clubClass?->staff()->pluck('staff.id')->first();
            } else {
                $member->class_id = null;
                $member->assigned_staff_id = null;
            }

            $member->save();
            return response()->json(['message' => 'Undo successful']);
        }

        if (in_array($member->type, ['temp_pathfinder', 'pathfinders'], true)) {
            $lastAssignment = ClassMemberPathfinder::where('member_id', $member->id)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if (!$lastAssignment) {
                return response()->json(['message' => 'No assignment found to undo'], 404);
            }

            $lastAssignment->update([
                'active' => false,
                'finished_at' => now(),
                'undone_at' => now(),
            ]);

            $previous = ClassMemberPathfinder::where('member_id', $member->id)
                ->whereNull('undone_at')
                ->orderByDesc('created_at')
                ->first();

            if ($previous) {
                $previous->update([
                    'active' => true,
                    'finished_at' => null,
                ]);
                $clubClass = ClubClass::find($previous->club_class_id);
                $member->class_id = $previous->club_class_id;
                $member->assigned_staff_id = $clubClass?->staff()->pluck('staff.id')->first();
            } else {
                $member->class_id = null;
                $member->assigned_staff_id = null;
            }

            $member->save();
            return response()->json(['message' => 'Undo successful']);
        }

        return response()->json(['message' => 'Unsupported member type'], 422);
    }

    protected function buildMembersPayloadForClub(int $clubId)
    {
        $memberRows = \App\Models\Member::where('club_id', $clubId)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder', 'master_guide'])
            ->where('status', 'active')
            ->get();
        $parentUsers = User::query()
            ->whereIn('id', $memberRows->pluck('parent_id')->filter()->unique()->values())
            ->where('profile_type', 'parent')
            ->where(fn ($query) => $query->whereNull('status')->orWhere('status', '!=', 'deleted'))
            ->get(['id', 'name', 'email'])
            ->keyBy('id');
        $parentPortalProfiles = ['superadmin'];
        $canOpenParentPortal = in_array(Auth::user()?->profile_type, $parentPortalProfiles, true);
        $parentPortalUrlFor = function ($memberRow, ?string $parentName = null) use ($canOpenParentPortal, $parentUsers) {
            if (!$canOpenParentPortal || !$memberRow || blank($parentName)) {
                return null;
            }

            if (!empty($memberRow->parent_id) && $parentUsers->has($memberRow->parent_id)) {
                return route('superadmin.parents.portal', ['parent' => $memberRow->parent_id]);
            }

            return route('superadmin.members.parent-portal', ['member' => $memberRow->id]);
        };

        $adventurerIds = $memberRows->where('type', 'adventurers')->pluck('id_data')->all();
        $pathfinderMemberIds = $memberRows->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id')->all();
        $tempPathfinderIds = $memberRows->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id_data')->all();
        $masterGuideIds = $memberRows->where('type', 'master_guide')->pluck('id_data')->all();
        $masterGuideSchemaFields = $this->masterGuideSchemaFieldsForClubId($clubId);

        $pathfinderAssignments = ClassMemberPathfinder::whereIn('member_id', $pathfinderMemberIds)
            ->with(['clubClass:id,club_id,class_order,class_name'])
            ->get()
            ->groupBy('member_id');

        $adventurers = MemberAdventurer::whereIn('id', $adventurerIds)
            ->where('status', 'active')
            ->with(['classAssignments.clubClass'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($m) use ($memberRows, $parentUsers, $parentPortalUrlFor) {
                $memberRow = $memberRows->first(fn ($row) => $row->type === 'adventurers' && (int) $row->id_data === (int) $m->id);
                $parentUser = $memberRow?->parent_id ? $parentUsers->get($memberRow->parent_id) : null;
                $memberId = optional($memberRow)->id;
                $m->member_id = $memberId;
                $m->current_class_id = optional($memberRow)->class_id;
                $m->is_sda = (bool) (optional($memberRow)->is_sda ?? true);
                $m->baptism_date = optional(optional($memberRow)->baptism_date)->toDateString()
                    ?? optional($memberRow)->baptism_date;
                $m->father_name = $m->parent_name ?: $parentUser?->name;
                $m->parent_user_id = $memberRow?->parent_id;
                $m->parent_user_name = $parentUser?->name;
                $m->parent_user_email = $parentUser?->email;
                $m->father_portal_url = $parentPortalUrlFor($memberRow, $m->father_name);
                return $m;
            });

        $pathfinderRows = MemberPathfinder::with('insuranceCard')->whereIn('id', $tempPathfinderIds)->get()
            ->map(function ($row) use ($memberRows, $pathfinderAssignments, $parentUsers, $parentPortalUrlFor) {
                $memberRow = $memberRows->first(fn ($memberRow) => in_array($memberRow->type, ['pathfinders', 'temp_pathfinder'], true)
                    && (int) $memberRow->id_data === (int) $row->id);
                $parentUser = $memberRow?->parent_id ? $parentUsers->get($memberRow->parent_id) : null;
                $memberId = optional($memberRow)->id;
                $age = null;
                if ($row->birthdate) {
                    $age = Carbon::parse($row->birthdate)->age;
                }

                $assignments = [];
                if ($memberId && isset($pathfinderAssignments[$memberId])) {
                    $assignments = $pathfinderAssignments[$memberId]
                        ->map(function ($a) {
                            return [
                                'id' => $a->id,
                                'member_id' => $a->member_id,
                                'club_class_id' => $a->club_class_id,
                                'role' => $a->role,
                                'assigned_at' => optional($a->assigned_at)->toDateString(),
                                'finished_at' => optional($a->finished_at)->toDateString(),
                                'active' => (bool) $a->active,
                                'club_class' => $a->clubClass ? [
                                    'id' => $a->clubClass->id,
                                    'class_name' => $a->clubClass->class_name,
                                    'class_order' => $a->clubClass->class_order,
                                ] : null,
                            ];
                        })
                        ->values()
                        ->all();
                }

                $fatherName = $row->father_guardian_name ?: ($row->mother_guardian_name ?: $parentUser?->name);

                return [
                    'id' => $row->id,
                    'member_id' => $memberId,
                    'current_class_id' => optional($memberRow)->class_id,
                    'member_type' => 'temp_pathfinder',
                    'is_sda' => (bool) (optional($memberRow)->is_sda ?? true),
                    'baptism_date' => optional(optional($memberRow)->baptism_date)->toDateString()
                        ?? optional($memberRow)->baptism_date,
                    'applicant_name' => $row->applicant_name,
                    'birthdate' => $row->birthdate,
                    'age' => $age,
                    'grade' => $row->grade,
                    'mailing_address' => $row->mailing_address,
                    'cell_number' => $row->cell_number,
                    'emergency_contact' => $row->emergency_contact_name,
                    'investiture_classes' => [],
                    'allergies' => collect([$row->medication_allergies, $row->food_allergies])->filter()->implode(' | ') ?: null,
                    'physical_restrictions' => $row->physical_restrictions,
                    'health_history' => $row->health_history,
                    'father_name' => $fatherName,
                    'parent_name' => $row->father_guardian_name ?: $row->mother_guardian_name,
                    'parent_cell' => $row->father_guardian_phone ?: $row->mother_guardian_phone,
                    'parent_user_id' => $memberRow?->parent_id,
                    'parent_user_name' => $parentUser?->name,
                    'parent_user_email' => $parentUser?->email,
                    'father_portal_url' => $parentPortalUrlFor($memberRow, $fatherName),
                    'home_address' => $row->mailing_address,
                    'email_address' => $row->email_address,
                    'signature' => $row->parent_guardian_signature,
                    'status' => $row->status ?? 'active',
                    'city' => $row->city,
                    'state' => $row->state,
                    'zip' => $row->zip,
                    'school' => $row->school,
                    'father_guardian_name' => $row->father_guardian_name,
                    'father_guardian_email' => $row->father_guardian_email,
                    'father_guardian_phone' => $row->father_guardian_phone,
                    'mother_guardian_name' => $row->mother_guardian_name,
                    'mother_guardian_email' => $row->mother_guardian_email,
                    'mother_guardian_phone' => $row->mother_guardian_phone,
                    'pickup_authorized_people' => $row->pickup_authorized_people ?? [],
                    'consent_acknowledged' => (bool) $row->consent_acknowledged,
                    'photo_release' => (bool) $row->photo_release,
                    'disabilities' => $row->disabilities,
                    'medication_allergies' => $row->medication_allergies,
                    'food_allergies' => $row->food_allergies,
                    'dietary_considerations' => $row->dietary_considerations,
                    'immunization_notes' => $row->immunization_notes,
                    'current_medications' => $row->current_medications,
                    'physician_name' => $row->physician_name,
                    'physician_phone' => $row->physician_phone,
                    'emergency_contact_name' => $row->emergency_contact_name,
                    'emergency_contact_phone' => $row->emergency_contact_phone,
                    'insurance_provider' => $row->insurance_provider,
                    'insurance_number' => $row->insurance_number,
                    'insurance_paid' => (bool) $row->insurance_paid,
                    'insurance_paid_at' => $row->insurance_paid_at,
                    'enrollment_paid' => (bool) $row->enrollment_paid,
                    'enrollment_paid_at' => $row->enrollment_paid_at,
                    'insurance_card_url' => $row->insuranceCard?->url,
                    'signed_at' => $row->signed_at,
                    'class_assignments' => $assignments,
                ];
            });

        $masterGuideRows = MemberMasterGuide::whereIn('id', $masterGuideIds)
            ->where('status', 'active')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($row) use ($memberRows, $masterGuideSchemaFields) {
                $memberRow = $memberRows->first(fn ($memberRow) => $memberRow->type === 'master_guide'
                    && (int) $memberRow->id_data === (int) $row->id);
                $memberId = optional($memberRow)->id;
                $yearLabel = 'Year ' . ((int) ($row->program_year ?: 1));
                $customFields = $this->sanitizeMasterGuideCustomFieldValues(
                    $row->custom_fields_json ?: [],
                    $masterGuideSchemaFields
                );

                return [
                    'id' => $row->id,
                    'member_id' => $memberId,
                    'current_class_id' => null,
                    'member_type' => 'master_guide',
                    'is_sda' => (bool) (optional($memberRow)->is_sda ?? true),
                    'baptism_date' => optional(optional($memberRow)->baptism_date)->toDateString()
                        ?? optional($memberRow)->baptism_date,
                    'applicant_name' => $row->applicant_name,
                    'birthdate' => null,
                    'age' => null,
                    'grade' => $yearLabel,
                    'program_year' => (int) ($row->program_year ?: 1),
                    'program_year_label' => $yearLabel,
                    'mailing_address' => $row->address,
                    'cell_number' => $row->phone,
                    'phone' => $row->phone,
                    'emergency_contact' => $row->emergency_contact_name,
                    'emergency_contact_name' => $row->emergency_contact_name,
                    'emergency_contact_phone' => $row->emergency_contact_phone,
                    'emergency_contact_email' => $row->emergency_contact_email,
                    'investiture_classes' => [$yearLabel],
                    'allergies' => null,
                    'physical_restrictions' => null,
                    'health_history' => null,
                    'father_name' => null,
                    'parent_name' => null,
                    'parent_cell' => null,
                    'parent_user_id' => null,
                    'parent_user_name' => null,
                    'parent_user_email' => null,
                    'father_portal_url' => null,
                    'home_address' => $row->address,
                    'address' => $row->address,
                    'email_address' => $row->email,
                    'email' => $row->email,
                    'signature' => null,
                    'status' => $row->status ?? 'active',
                    'insurance_paid' => (bool) $row->insurance_paid,
                    'insurance_paid_at' => $row->insurance_paid_at,
                    'enrollment_paid' => (bool) $row->enrollment_paid,
                    'enrollment_paid_at' => $row->enrollment_paid_at,
                    'custom_fields_json' => $customFields,
                    'custom_fields_display' => $this->masterGuideCustomFieldsDisplay($customFields, $masterGuideSchemaFields),
                    'class_assignments' => [],
                ];
            });

        return $adventurers->concat($pathfinderRows)->concat($masterGuideRows)->values();
    }

    protected function attachAssignedStaffNamesToClasses($classes): void
    {
        if ($classes->isEmpty()) {
            return;
        }

        $classIds = $classes->pluck('id')->map(fn ($id) => (int) $id)->all();
        $staffRecords = Staff::query()
            ->whereIn('assigned_class', $classIds)
            ->with('user:id,name')
            ->get(['id', 'id_data', 'assigned_class', 'type', 'user_id']);

        $namesByClass = [];
        foreach ($staffRecords as $staff) {
            $name = $staff->user?->name ?? null;
            if (!$name) {
                $detail = ClubHelper::staffDetail($staff);
                $name = $detail['name'] ?? null;
            }
            if ($name) {
                $classId = (int) $staff->assigned_class;
                if (!isset($namesByClass[$classId])) {
                    $namesByClass[$classId] = [];
                }
                $namesByClass[$classId][] = $name;
            }
        }

        foreach ($classes as $class) {
            $names = $namesByClass[(int) $class->id] ?? [];
            $class->assigned_staff_name = !empty($names)
                ? implode(', ', collect($names)->unique()->values()->all())
                : '—';
        }
    }

    /* private function generateMemberDoc(MemberAdventurer $member, string $outputDir): string
    {
        $templatePath = storage_path('app/templates/template_adventurer_new.docx');
        $processor = new TemplateProcessor($templatePath);

        $processor->setValue('current_date', date('m/d/Y'));
        $processor->setValue('club_name', $member->club_name);
        $processor->setValue('director_name', $member->director_name);
        $processor->setValue('church_name', $member->church_name);

        $processor->setValue('applicant_name', $member->applicant_name);
        $processor->setValue('birthdate', $member->birthdate);
        $processor->setValue('age', $member->age);
        $processor->setValue('grade', $member->grade);
        $processor->setValue('mailing_address', $member->mailing_address);
        $processor->setValue('cell_number', $member->cell_number);
        $processor->setValue('emergency_contact', $member->emergency_contact . " (Cell: " . $member->cell_number . ")");

        $processor->setValue('investiture_classes', is_array($member->investiture_classes) ? implode(', ', $member->investiture_classes) : $member->investiture_classes);
        $processor->setValue('allergies', $member->allergies);
        $processor->setValue('physical_restrictions', $member->physical_restrictions);
        $processor->setValue('health_history', $member->health_history);

        $processor->setValue('signature', $member->signature);
        $processor->setValue('parent_signature', $member->parent_name);
        $processor->setValue('parent_name', $member->parent_name);
        $processor->setValue('parent_cell', $member->parent_cell);
        $processor->setValue('home_address', $member->home_address);
        $processor->setValue('email_address', $member->email_address);

        if (!file_exists($outputDir)) {
            mkdir($outputDir, 0775, true);
        }

        $filename = "adventurer_member_" . Str::slug($member->applicant_name) . ".docx";
        $outputPath = $outputDir . '/' . $filename;
        $processor->saveAs($outputPath);

        return $outputPath;
    }
 */



}
