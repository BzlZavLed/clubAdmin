<?php

namespace App\Http\Controllers;

use App\Models\MemberAdventurer;
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
use Illuminate\Validation\Rule;

use DB;
use Auth;
class MemberAdventurerController extends Controller
{
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
        $parentId = auth()->user()?->profile_type === 'parent' ? auth()->id() : null;
        $directorName = $this->resolveClubDirectorName($club);

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
                'baptism_date' => ['nullable', 'date', Rule::requiredIf(fn () => $request->boolean('is_sda', true))],
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
                'baptism_date' => ['nullable', 'date', Rule::requiredIf(fn () => $request->boolean('is_sda', true))],
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
        $member = MemberAdventurer::findOrFail($id);
        $member->update([
            'status' => 'deleted',
            'notes_deleted' => $request['notes_deleted'],
        ]);

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
                'baptism_date' => ['nullable', 'date', Rule::requiredIf(fn () => $request->boolean('is_sda', true))],
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
            'baptism_date' => ['nullable', 'date', Rule::requiredIf(fn () => $request->boolean('is_sda', true))],
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
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->get();

        $adventurerIds = $memberRows->where('type', 'adventurers')->pluck('id_data')->all();
        $pathfinderMemberIds = $memberRows->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id')->all();
        $tempPathfinderIds = $memberRows->whereIn('type', ['pathfinders', 'temp_pathfinder'])->pluck('id_data')->all();

        $pathfinderAssignments = ClassMemberPathfinder::whereIn('member_id', $pathfinderMemberIds)
            ->with(['clubClass:id,club_id,class_order,class_name'])
            ->get()
            ->groupBy('member_id');

        $adventurers = MemberAdventurer::whereIn('id', $adventurerIds)
            ->where('status', 'active')
            ->with(['classAssignments.clubClass'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($m) use ($memberRows) {
                $memberRow = $memberRows->first(fn ($row) => $row->type === 'adventurers' && (int) $row->id_data === (int) $m->id);
                $memberId = optional($memberRow)->id;
                $m->member_id = $memberId;
                $m->current_class_id = optional($memberRow)->class_id;
                $m->is_sda = (bool) (optional($memberRow)->is_sda ?? true);
                $m->baptism_date = optional(optional($memberRow)->baptism_date)->toDateString()
                    ?? optional($memberRow)->baptism_date;
                return $m;
            });

        $pathfinderRows = MemberPathfinder::with('insuranceCard')->whereIn('id', $tempPathfinderIds)->get()
            ->map(function ($row) use ($memberRows, $pathfinderAssignments) {
                $memberRow = $memberRows->first(fn ($memberRow) => in_array($memberRow->type, ['pathfinders', 'temp_pathfinder'], true)
                    && (int) $memberRow->id_data === (int) $row->id);
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
                    'parent_name' => $row->father_guardian_name ?: $row->mother_guardian_name,
                    'parent_cell' => $row->father_guardian_phone ?: $row->mother_guardian_phone,
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

        return $adventurers->concat($pathfinderRows)->values();
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
