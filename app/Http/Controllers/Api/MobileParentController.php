<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Controllers\ParentPaymentController;
use App\Jobs\SendParentPaymentSubmissionEmail;
use App\Models\ChurchInviteCode;
use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\ParentPaymentSubmission;
use App\Models\PaymentReceipt;
use App\Models\Workplan;
use App\Support\ClubHelper;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;

class MobileParentController extends Controller
{
    public function dashboard(Request $request, ParentPaymentController $payments)
    {
        $user = $this->authorizeParent($request);
        $children = $this->childrenPayload($user);
        $membership = $this->parentMembershipPayload($user);
        $expectedPayments = $payments->expectedPaymentsForParent($user);
        $receipts = $payments->receiptsForParent($user);
        $workplan = $this->workplanPayload($user);
        $basicCostsByClub = $expectedPayments
            ->filter(fn (array $payment) => !$payment['event_id'] && $payment['concept_type'] === 'mandatory')
            ->groupBy('club_id');
        $clubs = $membership['clubs']->map(function (array $club) use ($basicCostsByClub) {
            $basicCosts = collect($basicCostsByClub->get($club['id'], []))
                ->map(fn (array $payment) => [
                    'row_key' => $payment['row_key'],
                    'concept_id' => $payment['concept_id'],
                    'concept_name' => $payment['concept_name'],
                    'member_id' => $payment['member_id'],
                    'member_name' => $payment['member_name'],
                    'expected_amount' => $payment['expected_amount'],
                    'paid_amount' => $payment['paid_amount'],
                    'remaining_amount' => $payment['remaining_amount'],
                    'due_date' => $payment['due_date'],
                    'reusable' => $payment['reusable'],
                    'status' => $payment['status'],
                ])
                ->values();

            return [
                ...$club,
                'basic_costs' => $basicCosts,
                'basic_cost_total' => round((float) $basicCosts->sum('expected_amount'), 2),
            ];
        })->values();

        return response()->json([
            'church' => $membership['church'],
            'clubs' => $clubs,
            'children' => $children,
            'payment_summary' => [
                'due_count' => $expectedPayments->whereIn('status', ['due', 'optional'])->count(),
                'pending_count' => $expectedPayments->where('status', 'pending_review')->count(),
                'paid_count' => $expectedPayments->where('status', 'paid')->count(),
                'total_expected' => round((float) $expectedPayments->sum('expected_amount'), 2),
                'total_paid' => round((float) $expectedPayments->sum('paid_amount'), 2),
                'total_remaining' => round((float) $expectedPayments->sum('remaining_amount'), 2),
            ],
            'expected_payments' => $expectedPayments->take(8)->values(),
            'recent_receipts' => $receipts->take(6)->values(),
            'workplan' => [
                'clubs' => $workplan['clubs'],
                'upcoming_events' => $workplan['upcoming_events']->take(8)->values(),
            ],
        ]);
    }

    public function children(Request $request)
    {
        $user = $this->authorizeParent($request);

        return response()->json([
            'data' => $this->childrenPayload($user),
            'club_options' => $this->parentClubOptions($user),
        ]);
    }

    public function applyChurchInvite(Request $request)
    {
        $user = $this->authorizeParent($request);
        $validated = $request->validate([
            'invite_code' => ['required', 'string', 'max:32'],
        ]);

        $code = strtoupper(trim($validated['invite_code']));

        $payload = DB::transaction(function () use ($user, $code) {
            $invite = ChurchInviteCode::query()
                ->where('code', $code)
                ->where('status', 'active')
                ->where(function ($query) {
                    $query->whereNull('expires_at')
                        ->orWhere('expires_at', '>=', now());
                })
                ->lockForUpdate()
                ->first();

            if (!$invite || ($invite->uses_left !== null && $invite->uses_left <= 0)) {
                throw ValidationException::withMessages([
                    'invite_code' => 'This invite code is not valid or has expired.',
                ]);
            }

            $invite->load('church:id,church_name');
            if (!$invite->church) {
                throw ValidationException::withMessages([
                    'invite_code' => 'This invite code is not attached to an active church.',
                ]);
            }

            if ($user->church_id && (int) $user->church_id !== (int) $invite->church_id) {
                throw ValidationException::withMessages([
                    'invite_code' => 'This parent account is already assigned to a different church.',
                ]);
            }

            $wasUnassigned = !$user->church_id;

            if ($wasUnassigned) {
                $user->forceFill([
                    'church_id' => $invite->church_id,
                    'church_name' => $invite->church->church_name,
                    'role_key' => $user->role_key ?: 'parent',
                    'scope_type' => $user->scope_type ?: 'church',
                    'scope_id' => $user->scope_id ?: $invite->church_id,
                ])->save();
            }

            if ($wasUnassigned && $invite->uses_left !== null) {
                $invite->decrement('uses_left');
            }

            $user->refresh();

            return [
                'church' => [
                    'id' => (int) $invite->church->id,
                    'church_name' => $invite->church->church_name,
                ],
                'club_options' => $this->parentClubOptions($user),
            ];
        });

        return response()->json(array_merge([
            'message' => 'Church invite applied.',
        ], $payload));
    }

    public function linkableChildren(Request $request)
    {
        $user = $this->authorizeParent($request);
        $search = strtolower(trim((string) $request->query('name', '')));
        $parentName = strtolower($user->name ?? '');
        $parentEmail = strtolower($user->email ?? '');
        $clubIds = $this->parentClubOptions($user)->pluck('id')->all();

        if (empty($clubIds)) {
            return response()->json(['data' => []]);
        }

        $linkedAdvIds = Member::query()
            ->whereNotNull('parent_id')
            ->where('type', 'adventurers')
            ->pluck('id_data')
            ->all();

        $linkedPathfinderIds = Member::query()
            ->whereNotNull('parent_id')
            ->whereIn('type', ['temp_pathfinder', 'pathfinders'])
            ->pluck('id_data')
            ->all();

        $adventurers = MemberAdventurer::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $linkedAdvIds)
            ->where(function ($query) use ($parentName, $parentEmail) {
                $query->whereRaw('LOWER(parent_name) = ?', [$parentName])
                    ->orWhereRaw('LOWER(emergency_contact) = ?', [$parentName])
                    ->orWhereRaw('LOWER(email_address) = ?', [$parentEmail]);
            })
            ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(applicant_name) LIKE ?', ['%' . $search . '%']))
            ->limit(20)
            ->get(['id', 'club_id', 'applicant_name'])
            ->map(fn ($row) => [
                'member_type' => 'adventurers',
                'id_data' => (int) $row->id,
                'name' => $row->applicant_name,
                'club_id' => (int) $row->club_id,
            ]);

        $pathfinders = MemberPathfinder::query()
            ->whereIn('club_id', $clubIds)
            ->whereNotIn('id', $linkedPathfinderIds)
            ->where(function ($query) use ($parentName, $parentEmail) {
                $query->whereRaw('LOWER(father_guardian_name) = ?', [$parentName])
                    ->orWhereRaw('LOWER(father_guardian_email) = ?', [$parentEmail])
                    ->orWhereRaw('LOWER(email_address) = ?', [$parentEmail]);
            })
            ->when($search !== '', fn ($query) => $query->whereRaw('LOWER(applicant_name) LIKE ?', ['%' . $search . '%']))
            ->limit(20)
            ->get(['id', 'club_id', 'applicant_name'])
            ->map(fn ($row) => [
                'member_type' => 'pathfinders',
                'id_data' => (int) $row->id,
                'name' => $row->applicant_name,
                'club_id' => (int) $row->club_id,
            ]);

        $clubs = Club::query()
            ->whereIn('id', collect($adventurers)->pluck('club_id')->merge(collect($pathfinders)->pluck('club_id'))->unique())
            ->pluck('club_name', 'id');

        return response()->json([
            'data' => $adventurers->concat($pathfinders)
                ->map(function (array $row) use ($clubs) {
                    $row['club_name'] = $clubs[$row['club_id']] ?? null;
                    return $row;
                })
                ->values(),
        ]);
    }

    public function linkChild(Request $request)
    {
        $user = $this->authorizeParent($request);
        $validated = $request->validate([
            'member_type' => ['required', 'in:adventurers,pathfinders,temp_pathfinder'],
            'id_data' => ['required', 'integer'],
        ]);

        if ($validated['member_type'] === 'adventurers') {
            $detail = MemberAdventurer::query()->findOrFail($validated['id_data']);
            $member = Member::query()->firstOrCreate(
                ['type' => 'adventurers', 'id_data' => $detail->id],
                ['club_id' => $detail->club_id, 'class_id' => null, 'status' => 'active']
            );
        } else {
            $detail = MemberPathfinder::query()->findOrFail($validated['id_data']);
            $member = Member::query()->firstOrCreate(
                ['type' => 'pathfinders', 'id_data' => $detail->id],
                ['club_id' => $detail->club_id, 'class_id' => null, 'status' => 'active']
            );
            if (!$detail->member_id) {
                $detail->update(['member_id' => $member->id]);
            }
        }

        $member->forceFill(['parent_id' => $user->id])->save();

        return response()->json([
            'message' => 'Child linked.',
            'child' => $this->childPayload($member->fresh(['club:id,club_name,club_type,evaluation_system', 'class:id,class_name'])),
        ]);
    }

    public function storeChild(Request $request)
    {
        $user = $this->authorizeParent($request);
        $validated = $request->validate([
            'club_id' => ['required', 'integer', 'exists:clubs,id'],
            'member_type' => ['required', 'in:adventurers,pathfinders'],
            'applicant_name' => ['required', 'string', 'max:255'],
            'birthdate' => ['required', 'date'],
            'grade' => ['nullable', 'string', 'max:50'],
            'cell_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'parent_name' => ['nullable', 'string', 'max:255'],
            'parent_cell' => ['nullable', 'string', 'max:50'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'health_history' => ['nullable', 'string'],
            'allergies' => ['nullable', 'string'],
            'physical_restrictions' => ['nullable', 'string'],
            'signature' => ['nullable', 'string', 'max:255'],
        ]);

        abort_unless($this->parentClubOptions($user)->pluck('id')->contains((int) $validated['club_id']), 403);

        $club = Club::query()->findOrFail($validated['club_id']);
        abort_unless(
            ($validated['member_type'] === 'pathfinders' && $club->club_type === 'pathfinders')
            || ($validated['member_type'] === 'adventurers' && $club->club_type === 'adventurers'),
            422,
            'Member type must match the selected club type.'
        );

        $parentName = $validated['parent_name'] ?: $user->name;
        $parentPhone = $validated['parent_cell'] ?: '';
        $parentEmail = $validated['email_address'] ?: $user->email;

        if ($validated['member_type'] === 'pathfinders') {
            $detail = MemberPathfinder::query()->create([
                'club_id' => $club->id,
                'club_name' => $club->club_name,
                'director_name' => $club->director_name,
                'church_name' => $club->church_name,
                'applicant_name' => $validated['applicant_name'],
                'birthdate' => $validated['birthdate'],
                'grade' => $validated['grade'] ?? null,
                'cell_number' => $validated['cell_number'] ?? null,
                'email_address' => $parentEmail,
                'father_guardian_name' => $parentName,
                'father_guardian_email' => $user->email,
                'father_guardian_phone' => $parentPhone,
                'health_history' => $validated['health_history'] ?? null,
                'food_allergies' => $validated['allergies'] ?? null,
                'physical_restrictions' => $validated['physical_restrictions'] ?? null,
                'parent_guardian_signature' => $validated['signature'] ?? $parentName,
                'signed_at' => now()->toDateString(),
                'status' => 'active',
            ]);

            $member = Member::query()->create([
                'type' => 'pathfinders',
                'id_data' => $detail->id,
                'club_id' => $club->id,
                'class_id' => null,
                'parent_id' => $user->id,
                'status' => 'active',
            ]);
            $detail->update(['member_id' => $member->id]);
        } else {
            $detail = MemberAdventurer::query()->create([
                'club_id' => $club->id,
                'club_name' => $club->club_name,
                'director_name' => $club->director_name,
                'church_name' => $club->church_name,
                'applicant_name' => $validated['applicant_name'],
                'birthdate' => $validated['birthdate'],
                'age' => Carbon::parse($validated['birthdate'])->age,
                'grade' => $validated['grade'] ?? '—',
                'mailing_address' => $validated['mailing_address'] ?? $validated['home_address'] ?? '—',
                'cell_number' => $validated['cell_number'] ?? '—',
                'emergency_contact' => $validated['emergency_contact'] ?? $parentName,
                'investiture_classes' => [],
                'allergies' => $validated['allergies'] ?? null,
                'physical_restrictions' => $validated['physical_restrictions'] ?? null,
                'health_history' => $validated['health_history'] ?? null,
                'parent_name' => $parentName,
                'parent_cell' => $parentPhone ?: '—',
                'home_address' => $validated['home_address'] ?? $validated['mailing_address'] ?? '—',
                'email_address' => $parentEmail,
                'signature' => $validated['signature'] ?? $parentName,
                'status' => 'active',
            ]);

            $member = Member::query()->create([
                'type' => 'adventurers',
                'id_data' => $detail->id,
                'club_id' => $club->id,
                'class_id' => null,
                'parent_id' => $user->id,
                'status' => 'active',
            ]);
        }

        return response()->json([
            'message' => 'Child created.',
            'child' => $this->childPayload($member->fresh(['club:id,club_name,club_type,evaluation_system', 'class:id,class_name'])),
        ], 201);
    }

    public function updateChild(Request $request, Member $member)
    {
        $user = $this->authorizeParent($request);
        abort_unless((int) $member->parent_id === (int) $user->id, 403);

        $validated = $request->validate([
            'applicant_name' => ['required', 'string', 'max:255'],
            'birthdate' => ['required', 'date'],
            'grade' => ['nullable', 'string', 'max:50'],
            'cell_number' => ['nullable', 'string', 'max:50'],
            'email_address' => ['nullable', 'email', 'max:255'],
            'parent_name' => ['nullable', 'string', 'max:255'],
            'parent_cell' => ['nullable', 'string', 'max:50'],
            'mailing_address' => ['nullable', 'string', 'max:255'],
            'home_address' => ['nullable', 'string', 'max:255'],
            'emergency_contact' => ['nullable', 'string', 'max:255'],
            'health_history' => ['nullable', 'string'],
            'allergies' => ['nullable', 'string'],
            'physical_restrictions' => ['nullable', 'string'],
            'signature' => ['nullable', 'string', 'max:255'],
        ]);

        if (in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)) {
            $detail = MemberPathfinder::query()->findOrFail($member->id_data);
            $detail->update([
                'applicant_name' => $validated['applicant_name'],
                'birthdate' => $validated['birthdate'],
                'grade' => $validated['grade'] ?? null,
                'cell_number' => $validated['cell_number'] ?? null,
                'email_address' => $validated['email_address'] ?? $user->email,
                'father_guardian_name' => $validated['parent_name'] ?? $user->name,
                'father_guardian_email' => $user->email,
                'father_guardian_phone' => $validated['parent_cell'] ?? null,
                'health_history' => $validated['health_history'] ?? null,
                'food_allergies' => $validated['allergies'] ?? null,
                'physical_restrictions' => $validated['physical_restrictions'] ?? null,
                'parent_guardian_signature' => $validated['signature'] ?? null,
            ]);
        } else {
            $detail = MemberAdventurer::query()->findOrFail($member->id_data);
            $detail->update([
                'applicant_name' => $validated['applicant_name'],
                'birthdate' => $validated['birthdate'],
                'age' => Carbon::parse($validated['birthdate'])->age,
                'grade' => $validated['grade'] ?? '—',
                'mailing_address' => $validated['mailing_address'] ?? '—',
                'cell_number' => $validated['cell_number'] ?? '—',
                'emergency_contact' => $validated['emergency_contact'] ?? '—',
                'allergies' => $validated['allergies'] ?? null,
                'physical_restrictions' => $validated['physical_restrictions'] ?? null,
                'health_history' => $validated['health_history'] ?? null,
                'parent_name' => $validated['parent_name'] ?? $user->name,
                'parent_cell' => $validated['parent_cell'] ?? '—',
                'home_address' => $validated['home_address'] ?? '—',
                'email_address' => $validated['email_address'] ?? $user->email,
                'signature' => $validated['signature'] ?? $user->name,
            ]);
        }

        return response()->json([
            'message' => 'Child updated.',
            'child' => $this->childPayload($member->fresh(['club:id,club_name,club_type,evaluation_system', 'class:id,class_name'])),
        ]);
    }

    public function payments(Request $request, ParentPaymentController $payments)
    {
        $user = $this->authorizeParent($request);

        return response()->json([
            'club_deposit_accounts' => $payments->clubDepositAccountsForParent($user)->values(),
            'expected_payments' => $payments->expectedPaymentsForParent($user)->values(),
            'transfer_submissions' => $payments->transferSubmissionsForParent($user)->values(),
            'receipts' => $payments->receiptsForParent($user)->values(),
        ]);
    }

    public function submitTransfer(Request $request, ParentPaymentController $payments)
    {
        $user = $this->authorizeParent($request);

        $validated = $request->validate([
            'payment_concept_id' => ['required', 'integer', 'exists:payment_concepts,id'],
            'member_id' => ['required', 'integer', 'exists:members,id'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'payment_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'receipt_image' => ['required', 'image', 'max:5120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $charge = $payments->expectedPaymentsForParent($user)
            ->first(fn (array $row) => (int) $row['concept_id'] === (int) $validated['payment_concept_id'] && (int) $row['member_id'] === (int) $validated['member_id']);

        abort_unless($charge, 403, 'This charge does not apply to the selected child.');

        if (!$charge['can_submit_transfer']) {
            throw ValidationException::withMessages([
                'amount' => $charge['transfer_blocked_reason'] ?? 'This charge does not allow new transfer receipts.',
            ]);
        }

        $availableAmount = (float) ($charge['available_amount'] ?? $charge['remaining_amount'] ?? 0);
        $amount = (float) $validated['amount'];

        if (!$charge['reusable'] && $availableAmount <= 0.0001) {
            throw ValidationException::withMessages([
                'amount' => 'Pending receipts already cover the available balance for this charge.',
            ]);
        }

        if (!$charge['reusable'] && $availableAmount > 0 && $amount > $availableAmount) {
            throw ValidationException::withMessages([
                'amount' => 'The submitted amount exceeds the available balance for this charge.',
            ]);
        }

        $receiptImagePath = $request->file('receipt_image')->store('payments/transfers', 'public');
        $club = Club::withoutGlobalScopes()->find($charge['club_id']);
        $clubReceiptEmail = $club?->club_email;

        $submission = ParentPaymentSubmission::query()->create([
            'club_id' => $charge['club_id'],
            'payment_concept_id' => $charge['concept_id'],
            'member_id' => $charge['member_id'],
            'parent_user_id' => $user->id,
            'event_id' => $charge['event_id'],
            'concept_text' => $charge['concept_name'],
            'pay_to' => $charge['pay_to'],
            'expected_amount' => $charge['expected_amount'],
            'amount' => $amount,
            'payment_date' => $validated['payment_date'],
            'payment_type' => 'transfer',
            'reference' => $validated['reference'] ?? null,
            'receipt_image_path' => $receiptImagePath,
            'club_receipt_email' => $clubReceiptEmail,
            'club_receipt_email_status' => $clubReceiptEmail ? 'queued' : 'manual_required',
            'notes' => $validated['notes'] ?? null,
            'status' => 'pending',
        ]);

        if ($clubReceiptEmail) {
            SendParentPaymentSubmissionEmail::dispatch($submission->id)->afterCommit();
        }

        return response()->json([
            'message' => $clubReceiptEmail
                ? 'Receipt uploaded for club validation and emailed to the club.'
                : 'Receipt uploaded for club validation.',
            'submission' => $payments->transferSubmissionsForParent($user)->firstWhere('id', $submission->id),
            'expected_payments' => $payments->expectedPaymentsForParent($user)->values(),
            'transfer_submissions' => $payments->transferSubmissionsForParent($user)->values(),
            'receipts' => $payments->receiptsForParent($user)->values(),
        ], 201);
    }

    public function receipt(Request $request, PaymentReceipt $receipt)
    {
        $user = $this->authorizeParent($request);
        $memberIds = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->pluck('id');

        abort_unless(
            (int) $receipt->parent_user_id === (int) $user->id || $memberIds->contains((int) $receipt->member_id),
            403,
            'Not allowed to view this receipt.'
        );

        $receipt->load([
            'club:id,club_name,church_name,club_email',
            'payment:id,club_id,member_id,staff_id,payer_name,payer_email,amount_paid,expected_amount,payment_date,payment_type,payment_concept_id,concept_text,pay_to,received_by_user_id,notes',
            'payment.member:id,type,id_data,parent_id',
            'payment.concept:id,concept,amount,reusable',
            'payment.receivedBy:id,name',
            'payment.allocations:id,payment_id,payment_concept_id,event_fee_component_id,amount',
            'payment.allocations.concept:id,concept,event_id,event_fee_component_id',
            'payment.allocations.concept.event:id,title,start_at',
            'payment.allocations.concept.eventFeeComponent:id,label,amount,is_required,sort_order',
        ]);

        $payment = $receipt->payment;
        $memberDetail = $payment ? ClubHelper::memberDetail($payment->member) : null;
        $allocations = $payment?->allocations?->map(fn ($allocation) => [
            'id' => (int) $allocation->id,
            'label' => $allocation->concept?->event?->title
                ?: $allocation->concept?->concept
                ?: $allocation->eventFeeComponent?->label
                ?: 'Allocation',
            'amount' => (float) $allocation->amount,
        ])->values() ?? collect();

        return response()->json([
            'data' => [
                'id' => (int) $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'issued_at' => optional($receipt->issued_at)->toDateString(),
                'issued_to_type' => $receipt->issued_to_type,
                'issued_to_email' => $receipt->issued_to_email,
                'delivery_status' => $receipt->delivery_status,
                'download_url' => route('payment-receipts.download', $receipt),
                'club' => [
                    'name' => $receipt->club?->club_name,
                    'church_name' => $receipt->club?->church_name,
                    'email' => $receipt->club?->club_email,
                ],
                'payment' => [
                    'payer_name' => $memberDetail['name'] ?? $payment?->payer_name,
                    'payer_email' => $payment?->payer_email,
                    'member_name' => $memberDetail['name'] ?? null,
                    'concept_name' => $payment?->allocations?->first()?->concept?->event?->title
                        ?? $payment?->concept?->concept
                        ?? $payment?->concept_text,
                    'amount_paid' => (float) ($payment?->amount_paid ?? 0),
                    'expected_amount' => $payment?->expected_amount !== null ? (float) $payment->expected_amount : null,
                    'payment_date' => optional($payment?->payment_date)->toDateString(),
                    'payment_type' => $payment?->payment_type,
                    'pay_to' => $payment?->pay_to,
                    'received_by' => $payment?->receivedBy?->name,
                    'notes' => $payment?->notes,
                    'allocations' => $allocations,
                ],
            ],
        ]);
    }

    public function workplan(Request $request)
    {
        $user = $this->authorizeParent($request);

        return response()->json($this->workplanPayload($user));
    }

    private function authorizeParent(Request $request)
    {
        $user = $request->user();
        abort_unless($user && $user->profile_type === 'parent', 403, 'Parent mobile access required.');

        return $user;
    }

    private function childrenPayload($user)
    {
        return Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->with(['club:id,club_name,club_type,evaluation_system', 'class:id,class_name'])
            ->get(['id', 'type', 'id_data', 'club_id', 'class_id', 'parent_id', 'status'])
            ->map(fn (Member $member) => $this->childPayload($member))
            ->values();
    }

    private function parentMembershipPayload($user): array
    {
        $clubIds = Member::query()
            ->where('parent_id', $user->id)
            ->whereIn('type', ['adventurers', 'pathfinders', 'temp_pathfinder'])
            ->where('status', '!=', 'deleted')
            ->pluck('club_id')
            ->filter()
            ->unique()
            ->values();

        $clubs = Club::query()
            ->whereIn('id', $clubIds)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type', 'church_id', 'church_name'])
            ->map(fn (Club $club) => [
                'id' => (int) $club->id,
                'club_name' => $club->club_name,
                'club_type' => $club->club_type,
                'church_id' => $club->church_id ? (int) $club->church_id : null,
                'church_name' => $club->church_name,
            ])
            ->values();

        $firstClub = $clubs->first();

        return [
            'church' => [
                'id' => $user->church_id ? (int) $user->church_id : ($firstClub['church_id'] ?? null),
                'church_name' => $user->church_name ?: ($firstClub['church_name'] ?? null),
            ],
            'clubs' => $clubs,
        ];
    }

    private function childPayload(Member $member): array
    {
        $detail = ClubHelper::memberDetail($member);
        $detailRow = null;
        if ($member->type === 'adventurers') {
            $detailRow = MemberAdventurer::query()->find($member->id_data);
        } elseif (in_array($member->type, ['pathfinders', 'temp_pathfinder'], true)) {
            $detailRow = MemberPathfinder::query()->find($member->id_data);
        }

        return [
            'member_id' => (int) $member->id,
            'id_data' => (int) $member->id_data,
            'name' => $detail['name'] ?? 'Member #' . $member->id,
            'member_type' => $member->type,
            'member_label' => $this->memberLabel($member->type),
            'club_id' => $member->club_id ? (int) $member->club_id : null,
            'club_name' => $member->club?->club_name,
            'club_type' => $member->club?->club_type,
            'evaluation_system' => $member->club?->evaluation_system,
            'class_id' => $member->class_id ? (int) $member->class_id : null,
            'class_name' => $member->class?->class_name,
            'status' => $member->status,
            'applicant_name' => $detailRow?->applicant_name,
            'birthdate' => optional($detailRow?->birthdate)->toDateString(),
            'grade' => $detailRow?->grade,
            'cell_number' => $detailRow?->cell_number,
            'email_address' => $detailRow instanceof MemberPathfinder ? $detailRow->email_address : $detailRow?->email_address,
            'parent_name' => $detailRow instanceof MemberPathfinder ? $detailRow->father_guardian_name : $detailRow?->parent_name,
            'parent_cell' => $detailRow instanceof MemberPathfinder ? $detailRow->father_guardian_phone : $detailRow?->parent_cell,
            'mailing_address' => $detailRow?->mailing_address,
            'home_address' => $detailRow instanceof MemberAdventurer ? $detailRow->home_address : null,
            'emergency_contact' => $detailRow instanceof MemberPathfinder ? $detailRow->emergency_contact_name : $detailRow?->emergency_contact,
            'health_history' => $detailRow?->health_history,
            'allergies' => $detailRow instanceof MemberPathfinder ? $detailRow->food_allergies : $detailRow?->allergies,
            'physical_restrictions' => $detailRow?->physical_restrictions,
            'signature' => $detailRow instanceof MemberPathfinder ? $detailRow->parent_guardian_signature : $detailRow?->signature,
        ];
    }

    private function parentClubOptions($user)
    {
        if (!$user->church_id) {
            return collect();
        }

        return Club::query()
            ->where('status', '!=', 'deleted')
            ->where('church_id', $user->church_id)
            ->whereIn('club_type', ['adventurers', 'pathfinders'])
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type']);
    }

    private function workplanPayload($user): array
    {
        $children = Member::query()
            ->where('parent_id', $user->id)
            ->where('status', '!=', 'deleted')
            ->get(['id', 'club_id', 'class_id']);

        $clubIds = $children->pluck('club_id')->filter()->unique()->values();
        $classIdsByClub = $children
            ->whereNotNull('class_id')
            ->groupBy('club_id')
            ->map(fn ($rows) => $rows->pluck('class_id')->filter()->unique()->values());

        $clubs = Club::query()
            ->whereIn('id', $clubIds)
            ->orderBy('club_name')
            ->get(['id', 'club_name', 'club_type'])
            ->values();

        $workplans = Workplan::query()
            ->whereIn('club_id', $clubIds)
            ->with(['events' => function ($query) {
                $query
                    ->with(['classPlans:id,workplan_event_id,class_id,title,status'])
                    ->where('status', 'active')
                    ->whereDate('date', '>=', now()->toDateString())
                    ->orderBy('date')
                    ->orderBy('start_time')
                    ->limit(40);
            }])
            ->get(['id', 'club_id', 'start_date', 'end_date']);

        $upcomingEvents = $workplans
            ->flatMap(function (Workplan $workplan) use ($clubs, $classIdsByClub) {
                $club = $clubs->firstWhere('id', $workplan->club_id);
                $classIds = $classIdsByClub->get($workplan->club_id, collect());

                return $workplan->events->map(function ($event) use ($club, $classIds, $workplan) {
                    $classPlans = $event->classPlans
                        ->filter(fn ($plan) => !$plan->class_id || $classIds->contains((int) $plan->class_id))
                        ->values();

                    return [
                        'id' => (int) $event->id,
                        'club_id' => (int) $workplan->club_id,
                        'club_name' => $club?->club_name,
                        'title' => $event->title,
                        'description' => $event->description,
                        'date' => optional($event->date)->toDateString(),
                        'end_date' => optional($event->end_date)->toDateString(),
                        'start_time' => $event->start_time,
                        'end_time' => $event->end_time,
                        'location' => $event->location,
                        'meeting_type' => $event->meeting_type,
                        'is_offsite' => (bool) $event->is_offsite,
                        'location_tracking_allowed' => (bool) $event->location_tracking_allowed,
                        'class_plans' => $classPlans->map(fn ($plan) => [
                            'id' => (int) $plan->id,
                            'class_id' => $plan->class_id ? (int) $plan->class_id : null,
                            'title' => $plan->title,
                            'status' => $plan->status,
                        ])->values(),
                    ];
                });
            })
            ->sortBy(['date', 'start_time'])
            ->values();

        return [
            'clubs' => $clubs,
            'upcoming_events' => $upcomingEvents,
        ];
    }

    private function memberLabel(?string $type): string
    {
        return match ($type) {
            'adventurers' => 'Adventurer',
            'pathfinders', 'temp_pathfinder' => 'Pathfinder',
            default => 'Member',
        };
    }
}
