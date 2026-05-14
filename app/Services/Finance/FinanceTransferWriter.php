<?php

namespace App\Services\Finance;

use App\Models\Account;
use App\Models\Club;
use App\Models\Payment;
use App\Models\TreasuryMovement;
use App\Services\AttendanceDuesPaymentService;
use App\Services\ClubTreasuryService;
use App\Support\ClubHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class FinanceTransferWriter
{
    public function __construct(private readonly ClubTreasuryService $treasuryService)
    {
    }

    public function storeMovement(Request $request)
    {
        $club = ClubHelper::clubForUser($request->user(), $request->input('club_id'));

        $validated = $request->validate([
            'movement_type' => ['required', 'in:cash_deposit,cash_withdrawal,account_transfer'],
            'pay_to' => ['nullable', 'string', 'max:255'],
            'from_pay_to' => ['nullable', 'string', 'max:255'],
            'to_pay_to' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'in:cash,bank'],
            'from_location' => ['nullable', 'in:cash,bank'],
            'to_location' => ['nullable', 'in:cash,bank'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'movement_date' => ['required', 'date'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'proof' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:10240'],
        ]);

        $movementType = $validated['movement_type'];
        $fromLocation = $validated['from_location'] ?? $validated['location'] ?? TreasuryMovement::LOCATION_CASH;

        if (
            in_array($movementType, [TreasuryMovement::TYPE_CASH_DEPOSIT, TreasuryMovement::TYPE_CASH_WITHDRAWAL], true)
            || ($movementType === TreasuryMovement::TYPE_ACCOUNT_TRANSFER && $fromLocation === TreasuryMovement::LOCATION_BANK)
        ) {
            if (!$this->treasuryService->hasClubBankInfo($club)) {
                return response()->json([
                    'message' => 'Registra la cuenta bancaria del club antes de mover fondos electrónicos.',
                ], 422);
            }
        }

        if ($movementType === TreasuryMovement::TYPE_ACCOUNT_TRANSFER) {
            return $this->storeAccountTransfer($request, $club, $validated);
        }

        $summary = $this->treasuryService->summary($club);
        $amount = round((float) $validated['amount'], 2);
        $payTo = $validated['pay_to'] ?? 'club_budget';
        $accountSummary = collect($summary['accounts'] ?? [])->firstWhere('account', $payTo) ?: [
            'cash_balance' => 0,
            'bank_balance' => 0,
        ];

        if ($movementType === TreasuryMovement::TYPE_CASH_DEPOSIT && $amount > (float) $accountSummary['cash_balance']) {
            return response()->json([
                'message' => 'El depósito excede el efectivo disponible.',
            ], 422);
        }

        if ($movementType === TreasuryMovement::TYPE_CASH_WITHDRAWAL && $amount > (float) $accountSummary['bank_balance']) {
            return response()->json([
                'message' => 'El retiro excede el balance bancario disponible.',
            ], 422);
        }

        [$proofPath, $proofOriginalName] = $this->storeProof($request);

        $movement = TreasuryMovement::query()->create([
            'club_id' => $club->id,
            'pay_to' => $payTo,
            'from_pay_to' => $payTo,
            'to_pay_to' => $payTo,
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

    public function validateStaffRemittance(Request $request)
    {
        $club = ClubHelper::clubForUser($request->user(), $request->input('club_id'));
        $validated = $request->validate([
            'remittance_batch_id' => ['required', 'string', 'max:64'],
        ]);

        $payments = DB::transaction(function () use ($club, $validated, $request) {
            $payments = Payment::query()
                ->where('club_id', $club->id)
                ->where('remittance_batch_id', $validated['remittance_batch_id'])
                ->where('custody_status', AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING)
                ->lockForUpdate()
                ->get();

            if ($payments->isEmpty()) {
                abort(422, 'No hay entrega pendiente para validar.');
            }

            $now = Carbon::now();
            foreach ($payments as $payment) {
                $paymentType = $this->paymentTypeForRemittance($payment->remittance_method);
                $payment->forceFill([
                    'custody_status' => AttendanceDuesPaymentService::CUSTODY_CLUB_RECEIVED,
                    'custody_validated_by_user_id' => $request->user()?->id,
                    'custody_validated_at' => $now,
                    'payment_type' => $paymentType,
                    'zelle_phone' => $paymentType === 'zelle'
                        ? ($payment->zelle_phone ?: $payment->remittance_reference)
                        : $payment->zelle_phone,
                ])->save();

                Account::query()
                    ->firstOrCreate(
                        ['club_id' => $payment->club_id, 'pay_to' => $payment->pay_to ?: 'club_budget'],
                        ['label' => $payment->pay_to ?: 'Presupuesto del club', 'balance' => 0]
                    )
                    ->increment('balance', (float) $payment->amount_paid);
            }

            return $payments;
        });

        return response()->json([
            'message' => 'Entrega de staff validada.',
            'count' => $payments->count(),
            'amount' => round((float) $payments->sum('amount_paid'), 2),
        ]);
    }

    protected function storeAccountTransfer(Request $request, Club $club, array $validated)
    {
        $summary = $this->treasuryService->summary($club);
        $amount = round((float) $validated['amount'], 2);
        $fromPayTo = $validated['from_pay_to'] ?? $validated['pay_to'] ?? 'club_budget';
        $toPayTo = $validated['to_pay_to'] ?? null;
        $fromLocation = $validated['from_location'] ?? $validated['location'] ?? TreasuryMovement::LOCATION_CASH;
        $toLocation = $validated['to_location'] ?? $validated['location'] ?? $fromLocation;

        if (!$toPayTo) {
            return response()->json([
                'message' => 'Selecciona la cuenta destino.',
            ], 422);
        }

        if ($fromPayTo === $toPayTo) {
            return response()->json([
                'message' => 'La cuenta origen y destino deben ser diferentes.',
            ], 422);
        }

        $existingAccounts = Account::query()
            ->where('club_id', $club->id)
            ->whereIn('pay_to', [$fromPayTo, $toPayTo])
            ->pluck('pay_to')
            ->all();

        if (!in_array($fromPayTo, $existingAccounts, true) || !in_array($toPayTo, $existingAccounts, true)) {
            return response()->json([
                'message' => 'Selecciona cuentas válidas del club.',
            ], 422);
        }

        $sourceSummary = collect($summary['accounts'] ?? [])->firstWhere('account', $fromPayTo) ?: [
            'cash_balance' => 0,
            'bank_balance' => 0,
        ];
        $available = $fromLocation === TreasuryMovement::LOCATION_BANK
            ? (float) $sourceSummary['bank_balance']
            : (float) $sourceSummary['cash_balance'];

        if ($amount > $available) {
            return response()->json([
                'message' => $fromLocation === TreasuryMovement::LOCATION_BANK
                    ? 'La transferencia excede el balance bancario disponible de la cuenta origen.'
                    : 'La transferencia excede el efectivo disponible de la cuenta origen.',
            ], 422);
        }

        [$proofPath, $proofOriginalName] = $this->storeProof($request);

        $movement = TreasuryMovement::query()->create([
            'club_id' => $club->id,
            'pay_to' => $fromPayTo,
            'from_pay_to' => $fromPayTo,
            'to_pay_to' => $toPayTo,
            'created_by_user_id' => $request->user()?->id,
            'movement_type' => TreasuryMovement::TYPE_ACCOUNT_TRANSFER,
            'from_location' => $fromLocation,
            'to_location' => $toLocation,
            'amount' => $amount,
            'movement_date' => Carbon::parse($validated['movement_date'])->toDateString(),
            'reference' => $validated['reference'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'proof_path' => $proofPath,
            'proof_original_name' => $proofOriginalName,
        ]);

        return response()->json([
            'message' => 'Transferencia entre cuentas registrada.',
            'data' => $movement,
        ], 201);
    }

    private function storeProof(Request $request): array
    {
        if (!$request->hasFile('proof')) {
            return [null, null];
        }

        $file = $request->file('proof');

        return [
            $file->store('treasury/proofs', 'public'),
            $file->getClientOriginalName(),
        ];
    }

    private function paymentTypeForRemittance(?string $method): string
    {
        return match ($method) {
            'zelle' => 'zelle',
            'transfer' => 'transfer',
            default => 'cash',
        };
    }
}
