<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Association;
use App\Models\BankInfo;
use App\Models\Church;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\District;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\EventParticipant;
use App\Models\Expense;
use App\Models\Member;
use App\Models\MemberPathfinder;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\PaymentReceipt;
use App\Models\Staff;
use App\Models\TreasuryMovement;
use App\Models\Union;
use App\Models\User;
use App\Services\AttendanceDuesPaymentService;
use App\Services\EventFinanceService;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FinanceEngineWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_finance_pages_redirect_to_engine_views_and_pdfs_remain_available(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $this->createAccount($club, 'club_budget', 'Club Budget');

        foreach ([
            'club.my-club-finances' => 'club.director.finance.accounting',
            'club.director.treasury' => 'club.director.finance.accounting',
            'club.reports.finances' => 'club.director.finance.accounting',
            'club.reports.accounts' => 'club.director.finance.accounting',
            'club.director.accounting-corrections' => 'club.director.finance.accounting',
            'club.director.payments' => 'club.director.finance.cashbox',
            'club.director.expenses' => 'club.director.finance.cashbox',
        ] as $legacyRoute => $targetRoute) {
            $this->actingAs($director)
                ->get(route($legacyRoute, ['club_id' => $club->id]))
                ->assertRedirect(route($targetRoute, ['club_id' => $club->id]));
        }

        $this->actingAs($director)
            ->get(route('financial.report.pdf', ['club_id' => $club->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $this->actingAs($director)
            ->get(route('club.finance-engine.accounting.pdf', ['club_id' => $club->id]))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_cashbox_records_income_with_precreated_and_manual_concepts_and_reports_receipts(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $this->createAccount($club, 'club_budget', 'Club Budget');
        $class = ClubClass::create([
            'club_id' => $club->id,
            'class_order' => 1,
            'class_name' => 'Friends',
        ]);
        $member = $this->makePathfinderMember($club, 'Cashbox Member');
        [, $staff] = $this->makeStaffForClub($club, 'Cashbox Staff');

        $actionables = $this->actingAs($director)
            ->getJson(route('club.finance-engine.actionables', ['club_id' => $club->id]))
            ->assertOk()
            ->json('data.groups');

        $this->assertArrayHasKey('cashbox', $actionables);
        $this->assertArrayHasKey('accounting', $actionables);
        $this->assertArrayHasKey('reports', $actionables);
        $this->assertSame('club.finance-engine.cashbox', $actionables['cashbox']['actions'][1]['route_name']);
        $this->assertSame('club.finance-engine.concepts.store', $actionables['cashbox']['actions'][2]['route_name']);
        $this->assertSame('club.finance-engine.income.store', $actionables['cashbox']['actions'][3]['route_name']);
        $this->assertSame('club.finance-engine.expenses.store', $actionables['cashbox']['actions'][4]['route_name']);
        $this->assertSame('club.director.finance.accounting', $actionables['accounting']['actions'][0]['route_name']);
        $this->assertSame('club.finance-engine.accounting', $actionables['accounting']['actions'][1]['route_name']);
        $this->assertSame('club.finance-engine.corrections.payments.reverse', $actionables['accounting']['actions'][5]['route_name']);
        $this->assertSame('club.finance-engine.movements', $actionables['reports']['actions'][0]['route_name']);

        $this->actingAs($director)
            ->getJson(route('club.finance-engine.cashbox', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.club.id', $club->id)
            ->assertJsonPath('data.engine_version', 'finance_engine_v1_bootstrap');

        $this->actingAs($director)
            ->getJson(route('club.finance-engine.accounting', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.club.id', $club->id)
            ->assertJsonPath('data.engine_version', 'finance_engine_v1_bootstrap');

        $conceptResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.concepts.store'), [
                'club_id' => $club->id,
                'concept' => 'Monthly dues',
                'payment_expected_by' => '2026-05-31',
                'amount' => 25,
                'reusable' => false,
                'type' => 'mandatory',
                'pay_to' => 'club_budget',
                'status' => 'active',
                'scopes' => [
                    ['scope_type' => 'club_wide', 'club_id' => $club->id],
                    ['scope_type' => 'class', 'class_id' => $class->id],
                ],
            ])
            ->assertCreated();

        $conceptId = $conceptResponse->json('data.id');
        $this->assertDatabaseHas('payment_concept_scopes', [
            'payment_concept_id' => $conceptId,
            'scope_type' => 'class',
            'class_id' => $class->id,
        ]);

        $scopedConceptResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.concepts.store'), [
                'club_id' => $club->id,
                'concept' => 'Scoped support fee',
                'payment_expected_by' => '2026-05-31',
                'amount' => 10,
                'reusable' => true,
                'type' => 'optional',
                'pay_to' => 'club_budget',
                'status' => 'active',
                'scopes' => [
                    ['scope_type' => 'member', 'member_id' => $member->id],
                    ['scope_type' => 'staff', 'staff_id' => $staff->id],
                    ['scope_type' => 'staff_wide', 'club_id' => $club->id],
                ],
            ])
            ->assertCreated();

        $scopedConceptId = $scopedConceptResponse->json('data.id');
        $this->assertDatabaseHas('payment_concept_scopes', [
            'payment_concept_id' => $scopedConceptId,
            'scope_type' => 'member',
            'member_id' => $member->id,
        ]);
        $this->assertDatabaseHas('payment_concept_scopes', [
            'payment_concept_id' => $scopedConceptId,
            'scope_type' => 'staff',
            'staff_id' => $staff->id,
        ]);

        $conceptPaymentResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.income.store'), [
                'club_id' => $club->id,
                'payment_concept_id' => $conceptId,
                'member_id' => $member->id,
                'amount_paid' => 25,
                'payment_date' => '2026-05-13',
                'payment_type' => 'cash',
            ])
            ->assertCreated();
        $this->assertStringStartsWith('RCPT-', $conceptPaymentResponse->json('data.receipt.receipt_number'));

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.income.store'), [
                'club_id' => $club->id,
                'concept_text' => 'Manual snack donation',
                'pay_to' => 'club_budget',
                'member_id' => $member->id,
                'amount_paid' => 7.50,
                'payment_date' => '2026-05-14',
                'payment_type' => 'cash',
            ])
            ->assertCreated();

        $guestDonationResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.income.store'), [
                'club_id' => $club->id,
                'concept_text' => 'Guest donation',
                'pay_to' => 'club_budget',
                'payer_name' => 'Donante Invitado',
                'amount_paid' => 12.50,
                'payment_date' => '2026-05-14',
                'payment_type' => 'cash',
            ])
            ->assertCreated()
            ->assertJsonPath('data.payer_name', 'Donante Invitado');
        $this->assertStringStartsWith('RCPT-', $guestDonationResponse->json('data.receipt.receipt_number'));

        $this->assertSame('45.00', Account::query()->where('club_id', $club->id)->where('pay_to', 'club_budget')->value('balance'));
        $this->assertDatabaseCount('payment_receipts', 3);
        $this->assertDatabaseHas('payment_receipts', [
            'issued_to_type' => 'external_payer',
        ]);

        $conceptReport = $this->actingAs($director)
            ->getJson(route('financial.report', [
                'mode' => 'concept',
                'club_id' => $club->id,
                'concept_id' => $conceptId,
            ]))
            ->assertOk();
        $this->assertSame(25.0, (float) $conceptReport->json('data.summary.amount_paid_sum'));

        $scopeReport = $this->actingAs($director)
            ->getJson(route('financial.report', [
                'mode' => 'scope',
                'club_id' => $club->id,
                'scope_type' => 'class',
                'scope_id' => $class->id,
            ]))
            ->assertOk();
        $this->assertSame('Class: Friends', $scopeReport->json('data.scopes.0.scope.label'));

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'income',
            ]))
            ->assertOk();

        $movements = collect($engine->json('data.movements'));
        $this->assertNotNull($movements->firstWhere('concept', 'Monthly dues')['receipt']['number'] ?? null);
        $this->assertSame(7.50, (float) $movements->firstWhere('concept', 'Manual snack donation')['amount']);
        $this->assertSame('Donante Invitado', $movements->firstWhere('concept', 'Guest donation')['counterparty']);
    }

    public function test_cashbox_records_expenses_with_receipts_and_report_proof_references(): void
    {
        Storage::fake('public');

        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 100);
        $payment = Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Opening cash',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 100,
            'payment_date' => '2026-05-01',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);
        app(PaymentReceiptService::class)->syncForPayment($payment);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.expenses.store'), [
                'club_id' => $club->id,
                'pay_to' => 'club_budget',
                'funds_location' => 'cash',
                'amount' => 35.25,
                'expense_date' => '2026-05-13',
                'description' => 'Club supplies',
                'receipt_image' => UploadedFile::fake()->image('supplies.jpg'),
            ])
            ->assertCreated()
            ->assertJsonPath('message', 'Expense recorded');

        $expense = Expense::query()->firstWhere('description', 'Club supplies');
        $this->assertNotNull($expense);
        $this->assertSame('completed', $expense->status);
        Storage::disk('public')->assertExists($expense->receipt_path);
        $this->assertSame('64.75', Account::findOrFail($account->id)->balance);

        $accountReport = $this->actingAs($director)
            ->getJson(route('financial.accounts', ['club_id' => $club->id]))
            ->assertOk();
        $expenseRow = collect($accountReport->json('data.expenses'))->firstWhere('description', 'Club supplies');
        $this->assertStringStartsWith('EXP-', $expenseRow['receipt_ref']);
        $this->assertStringContainsString('expense-receipts', $expenseRow['receipt_url']);

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'expense',
            ]))
            ->assertOk();
        $movement = collect($engine->json('data.movements'))->firstWhere('concept', 'Club supplies');
        $this->assertSame('expense_receipt', $movement['proof']['type']);
        $this->assertSame('cash', $movement['location']);
    }

    public function test_treasury_transfers_cover_cash_bank_origin_matrix_and_reports(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $clubBudget = $this->createAccount($club, 'club_budget', 'Club Budget', 240);
        $this->createAccount($club, 'church_budget', 'Church Budget');
        $this->createBankInfo($club);

        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Cash reserve',
            'pay_to' => 'club_budget',
            'account_id' => $clubBudget->id,
            'amount_paid' => 120,
            'payment_date' => '2026-05-01',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Bank reserve',
            'pay_to' => 'club_budget',
            'account_id' => $clubBudget->id,
            'amount_paid' => 120,
            'payment_date' => '2026-05-01',
            'payment_type' => 'transfer',
            'received_by_user_id' => $director->id,
        ]);

        $this->storeTreasuryMovement($director, $club, [
            'movement_type' => 'cash_deposit',
            'pay_to' => 'club_budget',
            'amount' => 15,
            'movement_date' => '2026-05-02',
            'reference' => 'DEP-001',
        ]);
        $this->storeTreasuryMovement($director, $club, [
            'movement_type' => 'cash_withdrawal',
            'pay_to' => 'club_budget',
            'amount' => 5,
            'movement_date' => '2026-05-03',
            'reference' => 'WDR-001',
        ]);

        foreach ([
            ['cash', 'cash', 10, 'LOCAL-CASH-CASH'],
            ['bank', 'bank', 20, 'LOCAL-BANK-BANK'],
            ['cash', 'bank', 30, 'LOCAL-CASH-BANK'],
            ['bank', 'cash', 40, 'LOCAL-BANK-CASH'],
        ] as [$fromLocation, $toLocation, $amount, $reference]) {
            $this->storeTreasuryMovement($director, $club, [
                'movement_type' => 'account_transfer',
                'from_pay_to' => 'club_budget',
                'to_pay_to' => 'church_budget',
                'from_location' => $fromLocation,
                'to_location' => $toLocation,
                'amount' => $amount,
                'movement_date' => '2026-05-04',
                'reference' => $reference,
            ]);
        }

        $treasury = $this->actingAs($director)
            ->getJson(route('club.director.treasury.data', ['club_id' => $club->id]))
            ->assertOk();
        $clubBudgetSummary = collect($treasury->json('summary.accounts'))->firstWhere('account', 'club_budget');
        $churchBudgetSummary = collect($treasury->json('summary.accounts'))->firstWhere('account', 'church_budget');

        $this->assertSame(70.0, (float) $clubBudgetSummary['cash_balance']);
        $this->assertSame(70.0, (float) $clubBudgetSummary['bank_balance']);
        $this->assertSame(50.0, (float) $churchBudgetSummary['cash_balance']);
        $this->assertSame(50.0, (float) $churchBudgetSummary['bank_balance']);

        $accountReport = $this->actingAs($director)
            ->getJson(route('financial.report', [
                'mode' => 'account',
                'club_id' => $club->id,
            ]))
            ->assertOk();
        $ledgerAccount = collect($accountReport->json('data.accounts'))->firstWhere('pay_to', 'club_budget');
        $this->assertContains('treasury_movement', collect($ledgerAccount['entries'])->pluck('entry_type')->all());

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'transfer',
            ]))
            ->assertOk();
        $this->assertCount(6, $engine->json('data.movements'));
        $this->assertNotNull(collect($engine->json('data.movements'))->firstWhere('kind', 'cash_deposit'));
        $this->assertNotNull(collect($engine->json('data.movements'))->firstWhere('reference', 'LOCAL-BANK-CASH'));
    }

    public function test_upstream_event_transfer_creates_settlement_receipt_and_engine_transfer_movement(): void
    {
        [$director, $club, $association, $associationDirector] = $this->makeAssociationEventFixture();
        $member = $this->makePathfinderMember($club, 'Event Camper');
        $this->createAccount($club, 'club_budget', 'Club Budget');
        $this->createBankInfo($club);
        $this->createBankInfo($association, 'association_budget');

        $event = Event::create([
            'club_id' => $club->id,
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'target_club_types' => ['pathfinders'],
            'created_by_user_id' => $associationDirector->id,
            'title' => 'Association Camporee',
            'description' => 'Finance engine upstream test',
            'event_type' => 'camp',
            'start_at' => now()->addDays(20),
            'end_at' => now()->addDays(22),
            'timezone' => 'America/New_York',
            'status' => 'draft',
            'requires_approval' => false,
            'is_mandatory' => true,
            'is_payable' => false,
        ]);
        $event->targetClubs()->sync([$club->id]);

        EventParticipant::create([
            'event_id' => $event->id,
            'member_id' => $member->id,
            'participant_name' => 'Event Camper',
            'role' => 'kid',
            'status' => 'confirmed',
        ]);

        $finance = app(EventFinanceService::class);
        $finance->syncFeeComponents($event, [
            ['label' => 'Registration', 'amount' => 50, 'is_required' => true],
        ]);
        $finance->syncPaymentConcepts($event->fresh(), $associationDirector->id);

        $concept = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('club_id', $club->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.income.store'), [
                'club_id' => $club->id,
                'payment_concept_id' => $concept->id,
                'member_id' => $member->id,
                'amount_paid' => 50,
                'payment_date' => '2026-05-10',
                'payment_type' => 'transfer',
            ])
            ->assertCreated();

        $settlementResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.event-settlements.store', $event), [
                'club_id' => $club->id,
                'deposited_at' => '2026-05-11 09:00:00',
                'reference' => 'ASSOC-UP-001',
            ])
            ->assertCreated();
        $this->assertStringStartsWith('EVTDEP-', $settlementResponse->json('data.receipt_number'));

        $settlement = EventClubSettlement::query()->firstOrFail();
        $this->assertSame('50.00', $settlement->amount);
        $this->assertDatabaseHas('treasury_movements', [
            'club_id' => $club->id,
            'movement_type' => TreasuryMovement::TYPE_EVENT_SETTLEMENT,
            'event_club_settlement_id' => $settlement->id,
            'from_location' => TreasuryMovement::LOCATION_BANK,
            'to_location' => TreasuryMovement::LOCATION_EXTERNAL,
        ]);

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'transfer',
            ]))
            ->assertOk();
        $settlementMovement = collect($engine->json('data.movements'))->firstWhere('kind', TreasuryMovement::TYPE_EVENT_SETTLEMENT);
        $this->assertSame($settlement->receipt_number, $settlementMovement['receipt']['number']);
        $this->assertSame('Association Camporee', $settlementMovement['concept']);
    }

    public function test_accounting_corrections_link_cancelled_movements_and_reports_show_reversals(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 60);

        $payment = Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Duplicate payment',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 40,
            'payment_date' => '2026-05-10',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);
        app(PaymentReceiptService::class)->syncForPayment($payment);

        $expense = Expense::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'funds_location' => 'cash',
            'amount' => 20,
            'expense_date' => '2026-05-10',
            'description' => 'Duplicate expense',
            'created_by_user_id' => $director->id,
            'status' => 'completed',
        ]);

        $paymentReverse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.corrections.payments.reverse', $payment), [
                'correction_date' => '2026-05-13',
                'reason' => 'Duplicate income',
            ])
            ->assertCreated()
            ->json('data.reversal_id');

        $expenseReverse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.corrections.expenses.reverse', $expense), [
                'correction_date' => '2026-05-13',
                'reason' => 'Duplicate expense',
            ])
            ->assertCreated()
            ->json('data.reversal_id');

        $this->assertDatabaseHas('payments', [
            'id' => $payment->id,
            'is_cancelled' => true,
            'related_canceled_movement_id' => $paymentReverse,
        ]);
        $this->assertDatabaseHas('payments', [
            'id' => $paymentReverse,
            'canceling_id' => $payment->id,
        ]);
        $cancellationReceipt = PaymentReceipt::query()
            ->where('payment_id', $paymentReverse)
            ->first();
        $this->assertNotNull($cancellationReceipt);
        $this->assertStringStartsWith('RCPT-', $cancellationReceipt->receipt_number);
        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'is_cancelled' => true,
            'related_canceled_movement_id' => $expenseReverse,
        ]);
        $this->assertDatabaseHas('expenses', [
            'id' => $expenseReverse,
            'canceling_id' => $expense->id,
        ]);

        $report = $this->actingAs($director)
            ->getJson(route('financial.accounts', ['club_id' => $club->id]))
            ->assertOk();
        $paymentRows = collect($report->json('data.payments'));
        $expenseRows = collect($report->json('data.expenses'));
        $this->assertTrue((bool) $paymentRows->firstWhere('id', $payment->id)['is_cancelled']);
        $this->assertSame($payment->id, $paymentRows->firstWhere('id', $paymentReverse)['canceling_id']);
        $this->assertSame($cancellationReceipt->receipt_number, $paymentRows->firstWhere('id', $paymentReverse)['payment_receipt_number']);
        $this->assertContains(
            "Recibo #{$cancellationReceipt->id} - {$cancellationReceipt->receipt_number}",
            $paymentRows->firstWhere('id', $paymentReverse)['receipt_refs']
        );
        $this->assertTrue((bool) $expenseRows->firstWhere('id', $expense->id)['is_cancelled']);
        $this->assertSame($expense->id, $expenseRows->firstWhere('id', $expenseReverse)['canceling_id']);

        $pdfResponse = $this->actingAs($director)
            ->get(route('financial.accounts.pdf', ['club_id' => $club->id]))
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfResponse->headers->get('content-type'));

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', ['club_id' => $club->id]))
            ->assertOk();
        $movements = collect($engine->json('data.movements'));
        $this->assertSame('cancelled', $movements->firstWhere('movement_id', "payment:{$payment->id}")['status']);
        $this->assertSame('cancellation', $movements->firstWhere('movement_id', "payment:{$paymentReverse}")['status']);
        $this->assertSame('cancelled', $movements->firstWhere('movement_id', "expense:{$expense->id}")['status']);
        $this->assertSame('cancellation', $movements->firstWhere('movement_id', "expense:{$expenseReverse}")['status']);
        $this->assertSame("payment:{$paymentReverse}", $movements->firstWhere('movement_id', "payment:{$payment->id}")['related_canceled_movement_key']);
        $this->assertSame("payment:{$payment->id}", $movements->firstWhere('movement_id', "payment:{$paymentReverse}")['canceling_movement_key']);
        $this->assertSame("expense:{$expenseReverse}", $movements->firstWhere('movement_id', "expense:{$expense->id}")['related_canceled_movement_key']);
        $this->assertSame("expense:{$expense->id}", $movements->firstWhere('movement_id', "expense:{$expenseReverse}")['canceling_movement_key']);

        $treasury = $this->actingAs($director)
            ->getJson(route('club.director.treasury.data', ['club_id' => $club->id]))
            ->assertOk();
        $this->assertSame(0.0, (float) $treasury->json('summary.cash_balance'));
        $this->assertSame(0.0, (float) $treasury->json('summary.bank_balance'));
        $this->assertSame(0.0, (float) $treasury->json('summary.total_available'));
    }

    public function test_staff_held_money_can_be_remitted_validated_and_read_by_engine(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget');
        $member = $this->makePathfinderMember($club, 'Dues Member');
        [$staffUser] = $this->makeStaffForClub($club, 'Class Staff');

        $payment = Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Cuota de asistencia - 2026-05-13',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'member_id' => $member->id,
            'amount_paid' => 12.25,
            'expected_amount' => 12.25,
            'balance_due_after' => 0,
            'payment_date' => '2026-05-13',
            'payment_type' => 'cash',
            'received_by_user_id' => $staffUser->id,
            'source_type' => AttendanceDuesPaymentService::SOURCE_TYPE,
            'source_id' => 1,
            'source_line_id' => 1,
            'custody_status' => AttendanceDuesPaymentService::CUSTODY_HELD_BY_STAFF,
            'held_by_user_id' => $staffUser->id,
        ]);
        app(PaymentReceiptService::class)->syncForPayment($payment);

        $this->actingAs($staffUser)
            ->getJson(route('club.personal.money-custody.data', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('held_total', 12.25)
            ->assertJsonPath('held_payments.0.receipt_number', PaymentReceipt::query()->where('payment_id', $payment->id)->value('receipt_number'));

        $batchId = $this->actingAs($staffUser)
            ->postJson(route('club.personal.money-custody.remit'), [
                'club_id' => $club->id,
                'payment_ids' => [$payment->id],
                'remittance_method' => 'transfer',
                'remittance_date' => '2026-05-14',
                'remittance_reference' => 'TRF-STAFF-001',
            ])
            ->assertOk()
            ->assertJsonPath('amount', 12.25)
            ->json('remittance_batch_id');

        $pendingEngine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'income',
            ]))
            ->assertOk();
        $pendingMovement = collect($pendingEngine->json('data.movements'))->firstWhere('movement_id', "payment:{$payment->id}");
        $this->assertSame(AttendanceDuesPaymentService::CUSTODY_REMITTED_PENDING, $pendingMovement['status']);
        $this->assertSame('staff_custody', $pendingMovement['location']);
        $this->assertFalse((bool) $pendingMovement['is_counted_in_balance']);

        $this->actingAs($director)
            ->getJson(route('club.director.treasury.data', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('pending_staff_remittances.0.batch_id', $batchId)
            ->assertJsonPath('pending_staff_remittances.0.amount', 12.25);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.staff-remittances.validate'), [
                'club_id' => $club->id,
                'remittance_batch_id' => $batchId,
            ])
            ->assertOk()
            ->assertJsonPath('amount', 12.25);

        $payment->refresh();
        $this->assertSame(AttendanceDuesPaymentService::CUSTODY_CLUB_RECEIVED, $payment->custody_status);
        $this->assertSame('transfer', $payment->payment_type);
        $this->assertSame('12.25', Account::findOrFail($account->id)->balance);

        $validatedEngine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'income',
            ]))
            ->assertOk();
        $validatedMovement = collect($validatedEngine->json('data.movements'))->firstWhere('movement_id', "payment:{$payment->id}");
        $this->assertSame(AttendanceDuesPaymentService::CUSTODY_CLUB_RECEIVED, $validatedMovement['status']);
        $this->assertSame('bank', $validatedMovement['location']);
        $this->assertTrue((bool) $validatedMovement['is_counted_in_balance']);
    }

    private function storeTreasuryMovement(User $director, Club $club, array $payload): void
    {
        $this->actingAs($director)
            ->postJson(route('club.finance-engine.transfers.store'), ['club_id' => $club->id, ...$payload])
            ->assertCreated();
    }

    private function makeDirectorAndClub(): array
    {
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => 'Finance Test Club',
            'church_name' => 'Finance Test Church',
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'pastor_name' => 'Pastor Test',
            'conference_name' => 'Test Association',
            'conference_region' => '1',
            'club_type' => 'pathfinders',
            'status' => 'active',
        ]);

        $director->update([
            'club_id' => $club->id,
            'scope_id' => $club->id,
        ]);

        return [$director->fresh(), $club->fresh()];
    }

    private function makeAssociationEventFixture(): array
    {
        $union = Union::create(['name' => 'Finance Union', 'status' => 'active']);
        $association = Association::create(['name' => 'Finance Association', 'union_id' => $union->id, 'status' => 'active']);
        $district = District::create(['name' => 'Finance District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'Finance Church', 'email' => 'finance-church@example.com', 'district_id' => $district->id]);

        [$director, $club] = $this->makeDirectorAndClub();
        $club->update([
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'district_id' => $district->id,
            'conference_name' => $association->name,
        ]);

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        return [$director->fresh(), $club->fresh(), $association->fresh(), $associationDirector];
    }

    private function makePathfinderMember(Club $club, string $name): Member
    {
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $profile = MemberPathfinder::create([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $club->director_name,
            'church_name' => $club->church_name,
            'applicant_name' => $name,
            'birthdate' => '2012-01-01',
            'father_guardian_name' => 'Guardian Test',
            'father_guardian_phone' => '555-0100',
            'email_address' => $parent->email,
            'status' => 'active',
        ]);

        $member = Member::create([
            'type' => 'pathfinders',
            'id_data' => $profile->id,
            'club_id' => $club->id,
            'class_id' => null,
            'parent_id' => $parent->id,
            'assigned_staff_id' => null,
            'status' => 'active',
            'is_sda' => true,
        ]);

        $profile->update(['member_id' => $member->id]);

        return $member->fresh();
    }

    private function makeStaffForClub(Club $club, string $name): array
    {
        $staffUser = User::factory()->create([
            'name' => $name,
            'profile_type' => 'club_personal',
            'role_key' => 'club_personal',
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'club_id' => $club->id,
            'sub_role' => 'staff',
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $staff = Staff::create([
            'type' => $club->club_type,
            'id_data' => 1,
            'club_id' => $club->id,
            'user_id' => $staffUser->id,
            'status' => 'active',
        ]);

        return [$staffUser, $staff->fresh()];
    }

    private function createAccount(Club $club, string $payTo, string $label, float $balance = 0): Account
    {
        return Account::create([
            'club_id' => $club->id,
            'pay_to' => $payTo,
            'label' => $label,
            'balance' => $balance,
        ]);
    }

    private function createBankInfo($bankable, string $payTo = 'club_budget'): BankInfo
    {
        return BankInfo::create([
            'bankable_type' => $bankable::class,
            'bankable_id' => $bankable->id,
            'pay_to' => $payTo,
            'label' => 'Cuenta bancaria',
            'bank_name' => 'Test Bank',
            'account_holder' => $bankable instanceof Club ? $bankable->club_name : $bankable->name,
            'account_number' => '123456789',
            'routing_number' => '021000021',
            'zelle_phone' => '555-0100',
            'is_active' => true,
            'accepts_parent_deposits' => true,
            'requires_receipt_upload' => true,
        ]);
    }
}
