<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Account;
use App\Models\TreasuryMovement;
use App\Services\ClubTreasuryService;
use App\Support\BankInfoFormatter;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;

class ClubTreasuryController extends Controller
{
    public function __construct(private readonly ClubTreasuryService $treasuryService)
    {
    }

    public function index(Request $request)
    {
        return Inertia::render('ClubDirector/Treasury', [
            'auth_user' => $request->user(),
        ]);
    }

    public function data(Request $request)
    {
        $club = $this->resolveAllowedClub($request);
        $summary = $this->treasuryService->summary($club);

        return response()->json([
            'club' => [
                'id' => (int) $club->id,
                'club_name' => $club->club_name,
            ],
            'bank_info' => BankInfoFormatter::payload($this->treasuryService->clubBankInfo($club)),
            'accounts' => Account::query()
                ->where('club_id', $club->id)
                ->orderBy('label')
                ->get(['pay_to', 'label'])
                ->map(fn (Account $account) => [
                    'value' => $account->pay_to,
                    'label' => $account->label ?: $account->pay_to,
                ])
                ->values(),
            'summary' => $summary,
            'income_rows' => $this->treasuryService->incomeRows($club)->values(),
            'movements' => $this->movementRows($club),
        ]);
    }

    public function storeMovement(Request $request)
    {
        $club = $this->resolveAllowedClub($request);

        $validated = $request->validate([
            'movement_type' => ['required', 'in:cash_deposit,cash_withdrawal'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'movement_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        if (!$this->treasuryService->hasClubBankInfo($club)) {
            return response()->json([
                'message' => 'Registra la cuenta bancaria del club antes de mover fondos electrónicos.',
            ], 422);
        }

        $summary = $this->treasuryService->summary($club);
        $amount = round((float) $validated['amount'], 2);
        $payTo = $validated['pay_to'] ?? 'club_budget';
        $accountSummary = collect($summary['accounts'] ?? [])->firstWhere('account', $payTo) ?: [
            'cash_balance' => 0,
            'bank_balance' => 0,
        ];

        if ($validated['movement_type'] === TreasuryMovement::TYPE_CASH_DEPOSIT && $amount > (float) $accountSummary['cash_balance']) {
            return response()->json([
                'message' => 'El depósito excede el efectivo disponible.',
            ], 422);
        }

        if ($validated['movement_type'] === TreasuryMovement::TYPE_CASH_WITHDRAWAL && $amount > (float) $accountSummary['bank_balance']) {
            return response()->json([
                'message' => 'El retiro excede el balance bancario disponible.',
            ], 422);
        }

        $proofPath = null;
        $proofOriginalName = null;
        if ($request->hasFile('proof')) {
            $file = $request->file('proof');
            $proofPath = $file->store('treasury/proofs', 'public');
            $proofOriginalName = $file->getClientOriginalName();
        }

        $movementType = $validated['movement_type'];
        $movement = TreasuryMovement::query()->create([
            'club_id' => $club->id,
            'pay_to' => $payTo,
            'created_by_user_id' => $request->user()?->id,
            'movement_type' => $movementType,
            'from_location' => $movementType === TreasuryMovement::TYPE_CASH_DEPOSIT
                ? TreasuryMovement::LOCATION_CASH
                : TreasuryMovement::LOCATION_BANK,
            'to_location' => $movementType === TreasuryMovement::TYPE_CASH_DEPOSIT
                ? TreasuryMovement::LOCATION_BANK
                : TreasuryMovement::LOCATION_CASH,
            'amount' => $amount,
            'movement_date' => Carbon::parse($validated['movement_date'])->toDateString(),
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $proofPath,
            'proof_original_name' => $proofOriginalName,
        ]);

        return response()->json([
            'message' => 'Movimiento registrado.',
            'data' => $movement,
        ], 201);
    }

    protected function resolveAllowedClub(Request $request): Club
    {
        return ClubHelper::clubForUser($request->user(), $request->input('club_id'));
    }

    protected function movementRows(Club $club): array
    {
        return TreasuryMovement::query()
            ->where('club_id', $club->id)
            ->with(['creator:id,name', 'event:id,title', 'eventClubSettlement:id,receipt_number'])
            ->latest('movement_date')
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (TreasuryMovement $movement) => [
                'id' => (int) $movement->id,
                'pay_to' => $movement->pay_to,
                'movement_type' => $movement->movement_type,
                'from_location' => $movement->from_location,
                'to_location' => $movement->to_location,
                'amount' => (float) $movement->amount,
                'movement_date' => optional($movement->movement_date)->toDateString(),
                'reference' => $movement->reference,
                'notes' => $movement->notes,
                'proof_url' => $movement->proof_path ? asset('storage/' . $movement->proof_path) : null,
                'event_title' => $movement->event?->title,
                'receipt_number' => $movement->eventClubSettlement?->receipt_number,
                'created_by' => $movement->creator?->name,
            ])
            ->values()
            ->all();
    }
}
