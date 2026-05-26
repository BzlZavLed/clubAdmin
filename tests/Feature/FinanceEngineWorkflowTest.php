<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Association;
use App\Models\BankInfo;
use App\Models\Church;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\DocumentValidation;
use App\Models\District;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\EventParticipant;
use App\Models\Expense;
use App\Models\FinanceReimbursementPayee;
use App\Models\FundraiserEventPartner;
use App\Models\FundraiserInvestmentReceipt;
use App\Models\FundraiserPartnerTransfer;
use App\Models\FundraiserProduct;
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
use App\Services\Finance\FinanceFundraiserService;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
            'club.reports.finances' => 'club.director.finance.reports',
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
            ->getJson(route('financial.report.pdf', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('file_name', 'finance-ledger.pdf');

        $this->actingAs($director)
            ->getJson(route('club.finance-engine.accounting.pdf', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('file_name', 'account-balances.pdf');
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
        $this->assertArrayHasKey('fundraisers', $actionables);
        $this->assertArrayHasKey('reports', $actionables);
        $this->assertSame('club.finance-engine.cashbox', $actionables['cashbox']['actions'][1]['route_name']);
        $this->assertSame('club.finance-engine.concepts.store', $actionables['cashbox']['actions'][2]['route_name']);
        $this->assertSame('club.finance-engine.income.store', $actionables['cashbox']['actions'][3]['route_name']);
        $this->assertSame('club.finance-engine.expenses.store', $actionables['cashbox']['actions'][4]['route_name']);
        $this->assertSame('club.director.finance.accounting', $actionables['accounting']['actions'][0]['route_name']);
        $this->assertSame('club.finance-engine.accounting', $actionables['accounting']['actions'][1]['route_name']);
        $this->assertSame('club.finance-engine.corrections.payments.reverse', $actionables['accounting']['actions'][5]['route_name']);
        $this->assertSame('club.finance-engine.fundraisers', $actionables['fundraisers']['actions'][1]['route_name']);
        $this->assertSame('club.finance-engine.fundraisers.sales.store', $actionables['fundraisers']['actions'][4]['route_name']);
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
        $guestDonationMovement = $movements->firstWhere('concept', 'Guest donation');
        $this->assertSame('Donante Invitado', $guestDonationMovement['counterparty']);
        $this->assertSame(45.0, (float) $guestDonationMovement['balance_after']['account_balance']);
        $this->assertMatchesRegularExpression('/^2026-05-14T\d{2}:\d{2}:\d{2}\\+00:00$/', $guestDonationMovement['occurred_at']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\\+00:00$/', $guestDonationMovement['created_at']);

        $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements.pdf', [
                'club_id' => $club->id,
                'domain' => 'income',
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('file_name', 'finance-ledger.pdf');
    }

    public function test_finance_ledger_receipt_appendix_orders_attached_and_missing_sections_by_reference(): void
    {
        Storage::fake('public');

        $club = (object) [
            'club_name' => 'Test Club',
            'church_name' => 'Test Church',
        ];

        $report = [
            'summary' => [],
            'filters' => [],
            'movements' => [
                [
                    'movement_id' => 'expense:10',
                    'domain' => 'expense',
                    'kind' => 'expense',
                    'date' => '2026-01-10',
                    'concept' => 'No receipt expense ten',
                    'amount' => 10,
                    'status' => 'posted',
                ],
                [
                    'movement_id' => 'expense:8',
                    'domain' => 'expense',
                    'kind' => 'expense',
                    'date' => '2026-01-08',
                    'concept' => 'No receipt expense eight',
                    'amount' => 8,
                    'status' => 'posted',
                ],
                [
                    'movement_id' => 'expense:13',
                    'domain' => 'expense',
                    'kind' => 'expense',
                    'date' => '2026-01-13',
                    'concept' => 'Attached expense thirteen',
                    'amount' => 13,
                    'status' => 'posted',
                    'proofs' => [[
                        'type' => 'expense_receipt',
                        'url' => 'https://example.test/receipts/EXP-13.jpg',
                        'path' => 'expense-receipts/EXP-13.jpg',
                    ]],
                ],
                [
                    'movement_id' => 'expense:12',
                    'domain' => 'expense',
                    'kind' => 'expense',
                    'date' => '2026-01-12',
                    'concept' => 'Attached expense twelve',
                    'amount' => 12,
                    'status' => 'posted',
                    'proofs' => [[
                        'type' => 'expense_receipt',
                        'url' => 'https://example.test/receipts/EXP-12.jpg',
                        'path' => 'expense-receipts/EXP-12.jpg',
                    ]],
                ],
                [
                    'movement_id' => 'expense:32',
                    'domain' => 'expense',
                    'kind' => 'expense',
                    'date' => '2026-05-08',
                    'concept' => 'Reembolso a Benjamin Zavala Ledesma',
                    'amount' => 9.35,
                    'account' => 'reimbursement_to',
                    'status' => 'pending_reimbursement',
                    'reimbursement_origin_expense_id' => 35,
                    'reimbursement_group' => [
                        'role' => 'pending_reimbursement',
                        'origin_expense_id' => 35,
                    ],
                ],
                [
                    'movement_id' => 'payment:60',
                    'domain' => 'income',
                    'kind' => 'income',
                    'date' => '2026-05-08',
                    'concept' => 'Reembolso a Benjamin Zavala Ledesma',
                    'amount' => 9.35,
                    'account' => 'reimbursement_to',
                    'status' => 'posted',
                    'settles_expense_id' => 32,
                    'reimbursement_group' => ['key' => 'reimbursement:32'],
                    'receipt' => [
                        'number' => 'RCPT-60',
                        'url' => 'https://example.test/payment-receipts/60',
                    ],
                ],
                [
                    'movement_id' => 'expense:35',
                    'domain' => 'expense',
                    'kind' => 'expense',
                    'date' => '2026-05-08',
                    'concept' => 'Reembolso a Gabriela Jose Marcano',
                    'counterparty' => 'Gabriela Jose Marcano',
                    'amount' => 9.35,
                    'account' => 'club_budget',
                    'status' => 'posted',
                    'settles_expense_id' => 32,
                    'proofs' => [[
                        'type' => 'reimbursement_receipt',
                        'url' => 'https://adminmyclub.com/storage/reimbursement-receipts/AIH4ZWcurwx3LAAPvqay8tjcYAu0bt52mHRwkqmj.jpg',
                        'path' => 'reimbursement-receipts/AIH4ZWcurwx3LAAPvqay8tjcYAu0bt52mHRwkqmj.jpg',
                    ]],
                ],
                [
                    'movement_id' => 'payment:61',
                    'domain' => 'income',
                    'kind' => 'income',
                    'date' => '2026-05-09',
                    'concept' => 'Monthly dues',
                    'amount' => 25,
                    'account' => 'club_budget',
                    'status' => 'posted',
                    'receipt' => [
                        'number' => 'RCPT-61',
                        'url' => 'https://example.test/payment-receipts/61',
                    ],
                ],
            ],
        ];

        $receiptAnnexes = [
            [
                'anchor' => 'exp-13',
                'reference' => 'EXP-13',
                'title' => 'Comprobante de gasto EXP-13',
                'url' => 'https://example.test/receipts/EXP-13.jpg',
                'document_type' => 'expense_receipt',
                'movement' => ['movement_id' => 'expense:13', 'date' => '2026-01-13', 'concept' => 'Attached expense thirteen', 'amount' => 13],
            ],
            [
                'anchor' => 'exp-12',
                'reference' => 'EXP-12',
                'title' => 'Comprobante de gasto EXP-12',
                'url' => 'https://example.test/receipts/EXP-12.jpg',
                'document_type' => 'expense_receipt',
                'movement' => ['movement_id' => 'expense:12', 'date' => '2026-01-12', 'concept' => 'Attached expense twelve', 'amount' => 12],
            ],
        ];

        $html = view('reports.finance_engine_movements', [
            'club' => $club,
            'report' => $report,
            'generatedAt' => now(),
            'clubLogoDataUri' => null,
            'validationUrl' => 'https://example.test/validate',
            'qrCodeDataUri' => null,
            'receiptAnnexes' => $receiptAnnexes,
            'annexOnly' => true,
        ])->render();

        $withSection = strpos($html, 'Movimientos con recibos o comprobantes');
        $withoutSection = strpos($html, 'Movimientos sin recibos o comprobantes');

        $this->assertNotFalse($withSection);
        $this->assertNotFalse($withoutSection);
        $this->assertLessThan($withoutSection, $withSection);
        $this->assertLessThan(strpos($html, 'Comprobante de gasto EXP-13'), strpos($html, 'Comprobante de gasto EXP-12'));
        $this->assertLessThan(strpos($html, 'EXP-10 - No receipt expense ten'), strpos($html, 'EXP-8 - No receipt expense eight'));
        $this->assertStringNotContainsString('REIMB-32 - Reembolso a Benjamin Zavala Ledesma', $html);
        $this->assertStringContainsString('REIMB-35 - Reembolso a Gabriela Jose Marcano', $html);
        $this->assertStringNotContainsString('RCPT-60 - Reembolso a Benjamin Zavala Ledesma', $html);
        $this->assertStringNotContainsString('RCPT-61 - Monthly dues', $html);
        $this->assertStringNotContainsString('Referencia RCPT-60', $html);

        Storage::disk('public')->put('expense-receipts/EXP-13.jpg', 'test receipt 13');
        Storage::disk('public')->put('expense-receipts/EXP-12.jpg', 'test receipt 12');

        $controller = app(\App\Http\Controllers\FinanceEngineController::class);
        $method = new \ReflectionMethod($controller, 'ledgerReceiptAnnexes');
        $method->setAccessible(true);

        $generatedAnnexes = $method->invoke($controller, $report);

        $this->assertSame(['EXP-13', 'EXP-12'], array_column($generatedAnnexes, 'reference'));

        $generatedAnnexesWithIncomeReceipts = $method->invoke($controller, $report, true);
        $generatedAnnexReferences = array_column($generatedAnnexesWithIncomeReceipts, 'reference');

        $this->assertContains('RCPT-61', $generatedAnnexReferences);
        $this->assertNotContains('RCPT-60', $generatedAnnexReferences);

        $htmlWithIncomeReceipts = view('reports.finance_engine_movements', [
            'club' => $club,
            'report' => $report,
            'generatedAt' => now(),
            'clubLogoDataUri' => null,
            'validationUrl' => 'https://example.test/validate',
            'qrCodeDataUri' => null,
            'receiptAnnexes' => $generatedAnnexesWithIncomeReceipts,
            'annexOnly' => true,
            'includeIncomeReceiptAnnexes' => true,
        ])->render();

        $this->assertStringContainsString('Recibo RCPT-61', $htmlWithIncomeReceipts);

        $ledgerHtml = view('reports.finance_engine_movements', [
            'club' => $club,
            'report' => $report,
            'generatedAt' => now(),
            'clubLogoDataUri' => null,
            'validationUrl' => 'https://example.test/validate',
            'qrCodeDataUri' => null,
            'receiptAnnexes' => [],
            'ledgerOnly' => true,
        ])->render();

        $this->assertStringContainsString('Comprobante de gasto EXP-13', $ledgerHtml);
        $this->assertStringContainsString('Comprobante de reembolso REIMB-35', $ledgerHtml);
        $this->assertStringNotContainsString('<a href="https://example.test/receipts/EXP-13.jpg">Comprobante de gasto EXP-13</a>', $ledgerHtml);
    }

    public function test_fundraiser_pos_records_sales_with_receipts_inventory_and_event_totals(): void
    {
        Storage::fake('public');

        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 200);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Opening fundraiser cash',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 200,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.engine_version', 'finance_engine_v1_fundraisers')
            ->assertJsonPath('data.accounts.0.pay_to', 'club_budget')
            ->assertJsonPath('data.account_balances.0.account', 'club_budget');

        $eventResponse = $this->actingAs($director)
            ->post(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Lunch sale',
                'fundraiser_type' => 'food',
                'event_date' => '2026-05-19',
                'pay_to' => 'club_budget',
                'investment_total' => 20,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'investment_receipt_images' => [
                    UploadedFile::fake()->image('setup-a.jpg'),
                    UploadedFile::fake()->image('setup-b.jpg'),
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('event.name', 'Lunch sale');

        $eventId = $eventResponse->json('event.id');
        $this->assertNotNull($eventResponse->json('event.kitchen_url'));
        $eventInvestment = Expense::query()->firstWhere('description', 'Inversion fundraiser: Lunch sale');
        $this->assertNotNull($eventInvestment);
        $this->assertSame('20.00', $eventInvestment->amount);
        Storage::disk('public')->assertExists($eventInvestment->receipt_path);
        $this->assertSame(2, FundraiserInvestmentReceipt::query()->where('expense_id', $eventInvestment->id)->count());

        $this->actingAs($director)
            ->post(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Lunch plate',
                'sale_price' => 8,
                'investment_amount' => 120,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'quantity_available' => 30,
                'receipt_image' => UploadedFile::fake()->image('plate-supplies.jpg'),
            ])
            ->assertCreated();

        $plate = FundraiserProduct::query()->firstWhere('name', 'Lunch plate');
        $this->assertNotNull($plate);
        $this->assertSame('4.00', $plate->unit_cost);
        $this->assertSame('120.00', $plate->investment_amount);
        $this->assertNotNull($plate->investment_expense_id);
        Storage::disk('public')->assertExists($plate->investmentExpense->receipt_path);

        $saleResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                'customer_name' => 'Lunch Buyer',
                'sale_date' => '2026-05-19',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $plate->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total_amount', 16)
            ->assertJsonPath('sale.total_cost', 8)
            ->assertJsonPath('sale.gain_amount', 8);

        $this->assertStringStartsWith('RCPT-', $saleResponse->json('receipt.number'));
        $this->assertStringContainsString('/payment-receipts/', $saleResponse->json('receipt.public_url'));
        $this->assertStringContainsString('/payment-receipts/', $saleResponse->json('receipt.qr_url'));
        $paymentId = $saleResponse->json('sale.payment_id');
        $saleId = $saleResponse->json('sale.id');

        auth()->logout();
        $qrResponse = $this->get($saleResponse->json('receipt.qr_url'))->assertOk();
        $this->assertStringContainsString('image/svg+xml', $qrResponse->headers->get('content-type'));
        $this->assertStringContainsString('<svg', $qrResponse->getContent());
        $this->getJson($saleResponse->json('receipt.public_url'))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('file_name', $saleResponse->json('receipt.number') . '.pdf');
        $receiptValidation = DocumentValidation::query()->latest('id')->first();
        $this->assertNotNull($receiptValidation);
        $fundraiserOrderSnapshot = $receiptValidation->document_snapshot['snapshot']['fundraiser_order'] ?? [];
        $this->assertSame('Lunch sale', $fundraiserOrderSnapshot['event_name'] ?? null);
        $this->assertSame('Lunch Buyer', $fundraiserOrderSnapshot['customer_name'] ?? null);
        $this->assertSame('Lunch plate', $fundraiserOrderSnapshot['items'][0]['name'] ?? null);
        $this->assertSame(2, $fundraiserOrderSnapshot['items'][0]['quantity'] ?? null);
        $this->assertSame(16.0, (float) ($fundraiserOrderSnapshot['total_amount'] ?? 0));

        $this->get($eventResponse->json('event.kitchen_url'))->assertOk();
        $kitchenOrders = $this->getJson(URL::signedRoute('fundraisers.kitchen.orders', ['fundraiserEvent' => $eventId]))
            ->assertOk()
            ->assertJsonPath('data.pending_orders.0.id', $saleId)
            ->assertJsonPath('data.pending_orders.0.items.0.item_name', 'Lunch plate');
        $this->postJson($kitchenOrders->json('data.pending_orders.0.finish_url'))
            ->assertOk()
            ->assertJsonPath('data.finished_orders.0.id', $saleId);
        $this->assertDatabaseHas('fundraiser_sales', [
            'id' => $saleId,
            'kitchen_status' => 'finished',
        ]);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'source_type' => FinanceFundraiserService::SOURCE_TYPE,
            'source_id' => $saleId,
            'concept_text' => 'Fundraiser: Lunch sale',
            'payer_name' => 'Lunch Buyer',
            'pay_to' => 'club_budget',
        ]);
        $this->assertDatabaseHas('payment_receipts', ['payment_id' => $paymentId]);
        $this->assertSame('76.00', Account::findOrFail($account->id)->balance);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Club shirt',
                'sale_price' => 12,
                'investment_amount' => 15,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'tracks_inventory' => true,
                'quantity_available' => 3,
            ])
            ->assertCreated();

        $shirt = FundraiserProduct::query()->firstWhere('name', 'Club shirt');
        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                'sale_date' => '2026-05-19',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $shirt->id, 'quantity' => 2],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total_amount', 24)
            ->assertJsonPath('sale.total_cost', 10)
            ->assertJsonPath('sale.gain_amount', 14);

        $shirt->refresh();
        $this->assertSame(2, (int) $shirt->quantity_sold);
        $this->assertSame('85.00', Account::findOrFail($account->id)->balance);
        $this->assertDatabaseCount('payment_receipts', 2);
        $this->assertDatabaseCount('expenses', 3);

        $merchEventId = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Merch sale',
                'fundraiser_type' => 'products',
                'event_date' => '2026-05-20',
                'pay_to' => 'club_budget',
            ])
            ->assertCreated()
            ->json('event.id');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $merchEventId), [
                'name' => 'Sticker pack',
                'sale_price' => 5,
                'investment_amount' => 20,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'quantity_available' => 10,
                'tracks_inventory' => false,
            ])
            ->assertCreated();

        $stickerPack = FundraiserProduct::query()->firstWhere('name', 'Sticker pack');
        $this->assertSame('2.00', $stickerPack->unit_cost);
        $this->assertFalse((bool) $stickerPack->tracks_inventory);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $merchEventId), [
                'name' => 'Limited mug',
                'sale_price' => 10,
                'tracks_inventory' => true,
                'quantity_available' => 1,
            ])
            ->assertCreated();

        $limitedMug = FundraiserProduct::query()->firstWhere('name', 'Limited mug');
        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $merchEventId), [
                'sale_date' => '2026-05-20',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $limitedMug->id, 'quantity' => 2],
                ],
            ])
            ->assertStatus(422);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $merchEventId), [
                'sale_date' => '2026-05-20',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $stickerPack->id, 'quantity' => 12],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total_amount', 60)
            ->assertJsonPath('sale.total_cost', 24)
            ->assertJsonPath('sale.gain_amount', 36);

        $this->assertSame('125.00', Account::findOrFail($account->id)->balance);
        $this->assertDatabaseCount('payment_receipts', 3);
        $this->assertDatabaseCount('expenses', 4);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $merchEventId), [
                'name' => 'Display banner',
                'sale_price' => 25,
                'investment_amount' => 150,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'quantity_available' => 5,
            ])
            ->assertCreated();

        $displayBanner = FundraiserProduct::query()->firstWhere('name', 'Display banner');
        $this->assertNotNull($displayBanner);
        $this->assertSame('30.00', $displayBanner->unit_cost);
        $this->assertSame('0.00', Account::findOrFail($account->id)->balance);
        $this->assertDatabaseHas('expenses', [
            'pay_to' => 'club_budget',
            'amount' => 125,
            'description' => 'Inversion fundraiser: Merch sale - Display banner',
        ]);
        $this->assertDatabaseHas('expenses', [
            'pay_to' => 'reimbursement_to',
            'amount' => 25,
            'status' => 'pending_reimbursement',
            'description' => 'Reembolso pendiente por inversion fundraiser con saldo insuficiente.',
        ]);
        $this->assertSame(
            '-25.00',
            Account::query()->where('club_id', $club->id)->where('pay_to', 'reimbursement_to')->value('balance')
        );
        $this->assertDatabaseCount('expenses', 6);

        $fundraisers = $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk();
        $event = collect($fundraisers->json('data.events'))->firstWhere('id', $eventId);
        $this->assertSame(40.0, (float) $event['totals']['revenue']);
        $this->assertSame(18.0, (float) $event['totals']['allocated_cost']);
        $this->assertSame(22.0, (float) $event['totals']['gross_gain']);
        $this->assertSame(155.0, (float) $event['totals']['investment_total']);
        $this->assertSame(20.0, (float) $event['totals']['net_gain']);
        $this->assertNotNull($event['investment_expense']['receipt_url']);
        $this->assertCount(2, $event['investment_receipts']);
        $this->assertSame(40.0, (float) $event['report']['summary']['total_sales']);
        $this->assertSame(20.0, (float) $event['report']['summary']['total_expenses']);
        $this->assertSame(20.0, (float) $event['report']['summary']['total_earnings']);
        $this->assertSame(40.0, (float) $event['report']['income_breakdown']['cash']);
        $this->assertSame(0.0, (float) $event['report']['income_breakdown']['bank']);
        $this->assertNotNull($event['report']['sale_receipts'][0]['receipt']['qr_url']);
        $this->assertNotNull(collect($event['products'])->firstWhere('name', 'Lunch plate')['investment_expense']['receipt_url']);
        $this->assertSame(1, (int) collect($event['products'])->firstWhere('name', 'Club shirt')['quantity_remaining']);

        $merchEvent = collect($fundraisers->json('data.events'))->firstWhere('id', $merchEventId);
        $this->assertNull($merchEvent['kitchen_url']);
        $stickerPayload = collect($merchEvent['products'])->firstWhere('name', 'Sticker pack');
        $this->assertSame(10, (int) $stickerPayload['quantity_available']);
        $this->assertSame(0, (int) $stickerPayload['quantity_remaining']);
        $this->assertFalse((bool) $stickerPayload['tracks_inventory']);
        $balancePayload = collect($fundraisers->json('data.account_balances'))->firstWhere('account', 'club_budget');
        $reimbursementBalancePayload = collect($fundraisers->json('data.account_balances'))->firstWhere('account', 'reimbursement_to');
        $this->assertSame(0.0, (float) $balancePayload['cash_balance']);
        $this->assertSame(-25.0, (float) $reimbursementBalancePayload['cash_balance']);

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'income',
            ]))
            ->assertOk();
        $fundraiserMovements = collect($engine->json('data.movements'))
            ->where('source_type', FinanceFundraiserService::SOURCE_TYPE)
            ->values();
        $movementConcepts = $fundraiserMovements->pluck('concept')->sort()->values()->all();
        $this->assertSame(['Fundraiser: Lunch sale', 'Fundraiser: Lunch sale', 'Fundraiser: Merch sale'], $movementConcepts);
        $this->assertTrue($fundraiserMovements->every(fn (array $movement) => !empty($movement['receipt']['number'])));
    }

    public function test_fundraiser_partnership_records_contributions_and_earnings_between_clubs(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $church = Church::create(['church_name' => 'Partner Finance Church']);
        $club->update([
            'church_id' => $church->id,
            'church_name' => $church->church_name,
        ]);

        $partnerDirector = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $partnerClub = Club::create([
            'user_id' => $partnerDirector->id,
            'club_name' => 'Partner Finance Club',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $partnerDirector->name,
            'creation_date' => now()->toDateString(),
            'pastor_name' => 'Pastor Test',
            'conference_name' => 'Test Association',
            'conference_region' => '1',
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $partnerDirector->update([
            'club_id' => $partnerClub->id,
            'scope_id' => $partnerClub->id,
        ]);

        $operativeAccount = $this->createAccount($club->fresh(), 'club_budget', 'Club Budget', 200);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Opening operative cash',
            'pay_to' => 'club_budget',
            'account_id' => $operativeAccount->id,
            'amount_paid' => 200,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $partnerAccount = $this->createAccount($partnerClub, 'club_budget', 'Club Budget', 0);

        $fundraiserBootstrap = $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk();
        $this->assertSame('Partner Finance Club', $fundraiserBootstrap->json('data.partner_clubs.0.club_name'));

        $eventResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Shared food sale',
                'fundraiser_type' => 'food',
                'event_date' => '2026-05-19',
                'pay_to' => 'club_budget',
                'investment_total' => 250,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'partner_club_id' => $partnerClub->id,
                'partner_investment_share_percent' => 50,
                'partner_earnings_share_percent' => 50,
                'partner_notes' => 'Split food fundraiser',
            ])
            ->assertCreated();
        $eventId = $eventResponse->json('event.id');

        $partnerId = FundraiserEventPartner::query()->where('partner_club_id', $partnerClub->id)->value('id');
        $this->assertNotNull($partnerId);
        $this->assertSame(125.0, (float) ($eventResponse->json('event.partners.0.investment_due') ?? 0));
        $this->assertDatabaseHas('expenses', [
            'club_id' => $club->id,
            'pay_to' => 'reimbursement_to',
            'status' => 'pending_reimbursement',
            'amount' => 50,
        ]);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Shared plate',
                'sale_price' => 20,
                'quantity_available' => 10,
            ])
            ->assertCreated();

        $plate = FundraiserProduct::query()->firstWhere('name', 'Shared plate');

        $contribution = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.partners.contribution', $partnerId), [
                'transfer_date' => '2026-05-19',
                'funds_location' => 'cash',
                'payment_type' => 'transfer',
            ])
            ->assertCreated()
            ->assertJsonPath('transfer.transfer_type', FundraiserPartnerTransfer::TYPE_INVESTMENT_CONTRIBUTION)
            ->assertJsonPath('transfer.amount', 125);

        $this->assertNotNull($contribution->json('transfer.receipt.number'));
        $this->assertSame('0.00', Account::findOrFail($partnerAccount->id)->balance);
        $this->assertSame('125.00', Account::findOrFail($operativeAccount->id)->balance);
        $this->assertSame('-125.00', Account::query()->where('club_id', $partnerClub->id)->where('pay_to', 'reimbursement_to')->value('balance'));
        $this->assertSame('-50.00', Account::query()->where('club_id', $club->id)->where('pay_to', 'reimbursement_to')->value('balance'));
        $this->assertDatabaseHas('expenses', [
            'club_id' => $partnerClub->id,
            'pay_to' => 'reimbursement_to',
            'status' => 'pending_reimbursement',
            'amount' => 125,
        ]);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                'sale_date' => '2026-05-19',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $plate->id, 'quantity' => 10],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total_amount', 200)
            ->assertJsonPath('sale.total_cost', 250)
            ->assertJsonPath('sale.gain_amount', -50);

        $closeResponse = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.close', $eventId), [
                'close_date' => '2026-05-19',
                'funds_location' => 'cash',
                'payment_type' => 'cash',
            ])
            ->assertOk()
            ->assertJsonPath('transfers.0.transfer_type', FundraiserPartnerTransfer::TYPE_EARNINGS_DISTRIBUTION)
            ->assertJsonPath('transfers.0.amount', 100);

        $this->assertNotNull($closeResponse->json('transfers.0.receipt.number'));
        $this->assertSame('225.00', Account::findOrFail($operativeAccount->id)->balance);
        $this->assertSame('100.00', Account::findOrFail($partnerAccount->id)->balance);
        $this->assertDatabaseCount('fundraiser_partner_transfers', 2);
        $this->assertDatabaseHas('payments', [
            'club_id' => $club->id,
            'source_type' => FinanceFundraiserService::PARTNER_TRANSFER_SOURCE_TYPE,
            'concept_text' => 'Aporte de Partner Finance Club para fundraiser: Shared food sale',
            'amount_paid' => 125,
        ]);
        $this->assertDatabaseHas('payments', [
            'club_id' => $partnerClub->id,
            'source_type' => FinanceFundraiserService::PARTNER_TRANSFER_SOURCE_TYPE,
            'concept_text' => 'Ingresos compartidos fundraiser Shared food sale de Finance Test Club',
            'amount_paid' => 100,
        ]);
        $this->assertDatabaseHas('fundraiser_events', [
            'id' => $eventId,
            'status' => 'closed',
        ]);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                'sale_date' => '2026-05-19',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $plate->id, 'quantity' => 1],
                ],
            ])
            ->assertStatus(422);

        $fundraisers = $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk();
        $event = collect($fundraisers->json('data.events'))->firstWhere('id', $eventId);
        $partner = $event['partners'][0];
        $this->assertSame(125.0, (float) $partner['investment_due']);
        $this->assertSame(125.0, (float) $partner['contribution_recorded']);
        $this->assertSame(100.0, (float) $partner['earnings_due']);
        $this->assertSame(100.0, (float) $partner['earnings_distributed']);
        $this->assertSame(125.0, (float) $event['totals']['partner_contributions_recorded']);
        $this->assertSame(100.0, (float) $event['totals']['partner_earnings_distributed']);
        $this->assertSame(200.0, (float) $event['totals']['partner_split_base']);
    }

    public function test_fundraiser_report_breaks_income_into_cash_and_bank_locations(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $this->createAccount($club, 'club_budget', 'Club Budget', 0);
        $this->createBankInfo($club);

        $eventId = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Mixed payments sale',
                'fundraiser_type' => 'products',
                'event_date' => '2026-05-20',
                'pay_to' => 'club_budget',
            ])
            ->assertCreated()
            ->json('event.id');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Ticket',
                'sale_price' => 10,
                'quantity_available' => 20,
            ])
            ->assertCreated();

        $ticket = FundraiserProduct::query()->firstWhere('name', 'Ticket');
        foreach ([
            ['payment_type' => 'cash', 'unit_price' => 10],
            ['payment_type' => 'check', 'unit_price' => 12],
            ['payment_type' => 'zelle', 'unit_price' => 15, 'zelle_phone' => '555-0199'],
            ['payment_type' => 'transfer', 'unit_price' => 20],
        ] as $sale) {
            $this->actingAs($director)
                ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                    'sale_date' => '2026-05-20',
                    'payment_type' => $sale['payment_type'],
                    'zelle_phone' => $sale['zelle_phone'] ?? null,
                    'items' => [
                        ['fundraiser_product_id' => $ticket->id, 'quantity' => 1, 'unit_price' => $sale['unit_price']],
                    ],
                ])
                ->assertCreated();
        }

        $fundraisers = $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk();

        $event = collect($fundraisers->json('data.events'))->firstWhere('id', $eventId);
        $this->assertSame(57.0, (float) $event['report']['summary']['total_sales']);
        $this->assertSame(22.0, (float) $event['report']['income_breakdown']['cash']);
        $this->assertSame(35.0, (float) $event['report']['income_breakdown']['bank']);
        $this->assertSame(10.0, (float) $event['report']['income_breakdown']['payment_types']['cash']);
        $this->assertSame(12.0, (float) $event['report']['income_breakdown']['payment_types']['check']);
        $this->assertSame(15.0, (float) $event['report']['income_breakdown']['payment_types']['zelle']);
        $this->assertSame(20.0, (float) $event['report']['income_breakdown']['payment_types']['transfer']);
    }

    public function test_closed_fundraiser_accepts_additional_initial_investment_receipts(): void
    {
        Storage::fake('public');

        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 100);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Opening fundraiser cash',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 100,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $eventId = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Closed receipt sale',
                'fundraiser_type' => 'products',
                'event_date' => '2026-05-20',
                'pay_to' => 'club_budget',
                'investment_total' => 30,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
            ])
            ->assertCreated()
            ->json('event.id');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.close', $eventId), [
                'close_date' => '2026-05-20',
                'funds_location' => 'cash',
                'payment_type' => 'cash',
            ])
            ->assertOk();

        $uploadResponse = $this->actingAs($director)
            ->post(route('club.finance-engine.fundraisers.investment-receipts.store', $eventId), [
                'investment_receipt_images' => [
                    UploadedFile::fake()->image('market-setup-a.jpg'),
                    UploadedFile::fake()->image('market-setup-b.jpg'),
                ],
            ], ['Accept' => 'application/json'])
            ->assertOk();

        $expense = Expense::query()->firstWhere('description', 'Inversion fundraiser: Closed receipt sale');
        $this->assertNotNull($expense);
        $this->assertSame('completed', $expense->fresh()->status);
        $this->assertSame(2, FundraiserInvestmentReceipt::query()->where('fundraiser_event_id', $eventId)->count());
        FundraiserInvestmentReceipt::query()
            ->where('fundraiser_event_id', $eventId)
            ->get()
            ->each(fn (FundraiserInvestmentReceipt $receipt) => Storage::disk('public')->assertExists($receipt->path));

        $event = collect($uploadResponse->json('data.events'))->firstWhere('id', $eventId);
        $this->assertCount(2, $event['investment_receipts']);
        $this->assertCount(2, $event['report']['initial_expenses'][0]['receipts']);
    }

    public function test_fundraiser_investments_use_total_account_balance_before_reimbursement(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 200);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Cash fundraiser funds',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 50,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Bank fundraiser funds',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 150,
            'payment_date' => '2026-05-18',
            'payment_type' => 'transfer',
            'received_by_user_id' => $director->id,
        ]);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Bake sale',
                'fundraiser_type' => 'food',
                'event_date' => '2026-05-19',
                'pay_to' => 'club_budget',
                'investment_total' => 120,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('expenses', [
            'pay_to' => 'club_budget',
            'funds_location' => 'cash',
            'amount' => 120,
            'description' => 'Inversion fundraiser: Bake sale',
        ]);
        $this->assertDatabaseMissing('expenses', [
            'pay_to' => 'reimbursement_to',
            'status' => 'pending_reimbursement',
        ]);
        $this->assertDatabaseHas('treasury_movements', [
            'movement_type' => TreasuryMovement::TYPE_ACCOUNT_TRANSFER,
            'from_pay_to' => 'club_budget',
            'to_pay_to' => 'club_budget',
            'from_location' => 'bank',
            'to_location' => 'cash',
            'amount' => 70,
            'reference' => 'AUTO-EXPENSE-FUNDING',
        ]);
        $this->assertSame('80.00', Account::findOrFail($account->id)->balance);

        $fundraisers = $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk();
        $balance = collect($fundraisers->json('data.account_balances'))->firstWhere('account', 'club_budget');
        $this->assertSame(0.0, (float) $balance['cash_balance']);
        $this->assertSame(80.0, (float) $balance['bank_balance']);
    }

    public function test_food_fundraiser_sales_extend_planned_quantity_when_ingredients_remain(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 0);

        $eventId = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Pupusa sale',
                'fundraiser_type' => 'food',
                'event_date' => '2026-05-19',
                'pay_to' => 'club_budget',
            ])
            ->assertCreated()
            ->json('event.id');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Cheese pupusa',
                'sale_price' => 4,
                'tracks_inventory' => true,
                'quantity_available' => 2,
            ])
            ->assertCreated();

        $plate = FundraiserProduct::query()->firstWhere('name', 'Cheese pupusa');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                'sale_date' => '2026-05-19',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $plate->id, 'quantity' => 5],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total_amount', 20);

        $plate->refresh();
        $this->assertSame(5, (int) $plate->quantity_available);
        $this->assertSame(5, (int) $plate->quantity_sold);
        $this->assertSame('20.00', Account::findOrFail($account->id)->balance);
    }

    public function test_fundraiser_general_investment_allocates_sold_cost_when_products_have_no_unit_cost(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 100);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Starting fundraiser cash',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 100,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $eventId = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Breakfast sale',
                'fundraiser_type' => 'food',
                'event_date' => '2026-05-19',
                'pay_to' => 'club_budget',
                'investment_total' => 100,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
            ])
            ->assertCreated()
            ->json('event.id');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Breakfast plate',
                'sale_price' => 8,
                'quantity_available' => 10,
            ])
            ->assertCreated();

        $plate = FundraiserProduct::query()->firstWhere('name', 'Breakfast plate');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.sales.store', $eventId), [
                'sale_date' => '2026-05-19',
                'payment_type' => 'cash',
                'items' => [
                    ['fundraiser_product_id' => $plate->id, 'quantity' => 3],
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('sale.total_amount', 24)
            ->assertJsonPath('sale.total_cost', 30)
            ->assertJsonPath('sale.gain_amount', -6);

        $fundraisers = $this->actingAs($director)
            ->getJson(route('club.finance-engine.fundraisers', ['club_id' => $club->id]))
            ->assertOk();
        $event = collect($fundraisers->json('data.events'))->firstWhere('id', $eventId);

        $this->assertSame(30.0, (float) $event['totals']['allocated_cost']);
        $this->assertSame(10.0, (float) $event['sales'][0]['items'][0]['unit_cost']);
    }

    public function test_fundraiser_products_can_be_updated_inline_without_rewriting_posted_investments(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 100);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Fundraiser starting cash',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 100,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $eventId = $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.store'), [
                'club_id' => $club->id,
                'name' => 'Water sale',
                'fundraiser_type' => 'products',
                'event_date' => '2026-05-20',
                'pay_to' => 'club_budget',
            ])
            ->assertCreated()
            ->json('event.id');

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.fundraisers.products.store', $eventId), [
                'name' => 'Water',
                'sale_price' => 2,
                'unit_cost' => 0.50,
                'tracks_inventory' => true,
                'quantity_available' => 10,
                'is_active' => true,
            ])
            ->assertCreated();

        $product = FundraiserProduct::query()->firstWhere('name', 'Water');
        $this->assertNotNull($product);
        $this->assertNull($product->investment_expense_id);

        $this->actingAs($director)
            ->patchJson(route('club.finance-engine.fundraisers.products.update', $product), [
                'name' => 'Water bottle',
                'description' => 'Cold bottles',
                'sale_price' => 3,
                'unit_cost' => 0.50,
                'investment_amount' => 30,
                'investment_pay_to' => 'club_budget',
                'investment_funds_location' => 'cash',
                'tracks_inventory' => true,
                'quantity_available' => 15,
                'is_active' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.events.0.products.0.name', 'Water bottle')
            ->assertJsonPath('data.events.0.products.0.is_active', false);

        $product->refresh();
        $postedInvestmentExpenseId = $product->investment_expense_id;
        $this->assertSame('Water bottle', $product->name);
        $this->assertSame('3.00', $product->sale_price);
        $this->assertSame('2.00', $product->unit_cost);
        $this->assertSame('30.00', $product->investment_amount);
        $this->assertNotNull($postedInvestmentExpenseId);
        $this->assertFalse((bool) $product->is_active);
        $this->assertSame('70.00', Account::findOrFail($account->id)->balance);
        $this->assertDatabaseHas('expenses', [
            'id' => $postedInvestmentExpenseId,
            'pay_to' => 'club_budget',
            'amount' => 30,
            'description' => 'Inversion fundraiser: Water sale - Water bottle',
        ]);

        $this->actingAs($director)
            ->patchJson(route('club.finance-engine.fundraisers.products.update', $product), [
                'name' => 'Water bottle',
                'sale_price' => 4,
                'unit_cost' => 2,
                'investment_amount' => 40,
                'tracks_inventory' => true,
                'quantity_available' => 20,
                'is_active' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('investment_amount');

        $this->actingAs($director)
            ->patchJson(route('club.finance-engine.fundraisers.products.update', $product), [
                'name' => 'Water bottle',
                'description' => 'Updated label',
                'sale_price' => 4,
                'unit_cost' => 2,
                'investment_amount' => 30,
                'tracks_inventory' => true,
                'quantity_available' => 20,
                'is_active' => true,
            ])
            ->assertOk();

        $product->refresh();
        $this->assertSame('4.00', $product->sale_price);
        $this->assertSame('1.50', $product->unit_cost);
        $this->assertSame($postedInvestmentExpenseId, $product->investment_expense_id);
        $this->assertTrue((bool) $product->is_active);
        $this->assertDatabaseCount('expenses', 1);
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

    public function test_cashbox_expenses_use_total_account_balance_before_reimbursement(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 100);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Cash reserve',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 20,
            'payment_date' => '2026-05-18',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Bank reserve',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 80,
            'payment_date' => '2026-05-18',
            'payment_type' => 'transfer',
            'received_by_user_id' => $director->id,
        ]);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.expenses.store'), [
                'club_id' => $club->id,
                'pay_to' => 'club_budget',
                'funds_location' => 'cash',
                'amount' => 70,
                'expense_date' => '2026-05-19',
                'description' => 'Cash purchase covered by bank balance',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('expenses', [
            'pay_to' => 'club_budget',
            'funds_location' => 'cash',
            'amount' => 70,
            'description' => 'Cash purchase covered by bank balance',
        ]);
        $this->assertDatabaseMissing('expenses', [
            'pay_to' => 'reimbursement_to',
            'status' => 'pending_reimbursement',
        ]);
        $this->assertDatabaseHas('treasury_movements', [
            'movement_type' => TreasuryMovement::TYPE_ACCOUNT_TRANSFER,
            'from_pay_to' => 'club_budget',
            'to_pay_to' => 'club_budget',
            'from_location' => 'bank',
            'to_location' => 'cash',
            'amount' => 50,
            'reference' => 'AUTO-EXPENSE-FUNDING',
        ]);
        $this->assertSame('30.00', Account::findOrFail($account->id)->balance);

        $cashbox = $this->actingAs($director)
            ->getJson(route('club.finance-engine.cashbox', ['club_id' => $club->id]))
            ->assertOk();
        $balance = collect($cashbox->json('data.engine_report.summary.accounts'))->firstWhere('account', 'club_budget');
        $this->assertSame(0.0, (float) $balance['cash_balance']);
        $this->assertSame(30.0, (float) $balance['bank_balance']);
    }

    public function test_cashbox_manages_late_expense_proofs_and_reimbursement_settlement_through_engine(): void
    {
        Storage::fake('public');

        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget', 40);
        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Cash reserve',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 40,
            'payment_date' => '2026-05-01',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.expenses.store'), [
                'club_id' => $club->id,
                'pay_to' => 'club_budget',
                'funds_location' => 'cash',
                'amount' => 65,
                'expense_date' => '2026-05-13',
                'description' => 'Out of pocket supplies',
                'reimbursement_target_mode' => 'new',
                'reimbursement_payee_name' => 'Guest Sponsor',
                'reimbursement_payee_phone' => '555-0199',
                'reimbursement_payee_email' => 'sponsor@example.com',
            ])
            ->assertCreated();

        $normalExpense = Expense::query()->where('pay_to', 'club_budget')->firstOrFail();
        $pendingReimbursement = Expense::query()->where('pay_to', 'reimbursement_to')->firstOrFail();
        $this->assertSame('working', $normalExpense->status);
        $this->assertSame('pending_reimbursement', $pendingReimbursement->status);
        $this->assertSame('Guest Sponsor', $pendingReimbursement->reimbursed_to);
        $this->assertSame($normalExpense->id, $pendingReimbursement->reimbursement_origin_expense_id);
        $payee = FinanceReimbursementPayee::query()->firstOrFail();
        $this->assertSame('Guest Sponsor', $payee->name);
        $this->assertSame('555-0199', $payee->phone);
        $this->assertSame('sponsor@example.com', $payee->email);
        $this->assertSame($payee->id, $pendingReimbursement->reimbursement_payee_id);
        $this->assertDatabaseHas('payment_concepts', [
            'id' => $pendingReimbursement->payment_concept_id,
            'concept' => 'Reembolso a Guest Sponsor',
            'payee_type' => FinanceReimbursementPayee::class,
            'payee_id' => $payee->id,
        ]);

        $cashbox = $this->actingAs($director)
            ->getJson(route('club.finance-engine.cashbox', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('data.reimbursement_payees.0.id', $payee->id)
            ->assertJsonPath('data.reimbursement_payees.0.name', 'Guest Sponsor')
            ->assertJsonPath('data.reimbursement_payees.0.phone', '555-0199')
            ->assertJsonPath('data.reimbursement_payees.0.email', 'sponsor@example.com');
        $cashboxAccounts = collect($cashbox->json('data.engine_report.summary.accounts'));
        $clubBudgetSummary = $cashboxAccounts->firstWhere('account', 'club_budget');
        $reimbursementSummary = $cashboxAccounts->firstWhere('account', 'reimbursement_to');
        $this->assertSame(0.0, (float) $clubBudgetSummary['cash_balance']);
        $this->assertSame(-25.0, (float) $reimbursementSummary['cash_balance']);
        $this->assertSame(-25.0, (float) $reimbursementSummary['total_available']);
        $this->assertSame(-25.0, (float) $cashbox->json('data.engine_report.summary.total_available'));
        $cashboxExpenses = collect($cashbox->json('data.expenses'));
        $this->assertSame($normalExpense->id, $cashboxExpenses->firstWhere('id', $pendingReimbursement->id)['reimbursement_origin_expense']['id']);
        $this->assertSame($pendingReimbursement->id, $cashboxExpenses->firstWhere('id', $normalExpense->id)['generated_reimbursement_expense']['id']);

        $this->actingAs($director)
            ->post(route('club.finance-engine.expenses.receipt.upload', $normalExpense), [
                'receipt_image' => UploadedFile::fake()->image('late-proof.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Receipt uploaded');

        $normalExpense->refresh();
        $normalReceiptPath = $normalExpense->receipt_path;
        $this->assertSame('completed', $normalExpense->status);
        Storage::disk('public')->assertExists($normalReceiptPath);

        $this->actingAs($director)
            ->deleteJson(route('club.finance-engine.expenses.receipt.remove', $normalExpense))
            ->assertOk()
            ->assertJsonPath('message', 'Receipt removed');

        $normalExpense->refresh();
        $this->assertSame('working', $normalExpense->status);
        $this->assertNull($normalExpense->receipt_path);
        Storage::disk('public')->assertMissing($normalReceiptPath);

        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Later cash',
            'pay_to' => 'club_budget',
            'account_id' => $account->id,
            'amount_paid' => 30,
            'payment_date' => '2026-05-15',
            'payment_type' => 'cash',
            'received_by_user_id' => $director->id,
        ]);
        $account->increment('balance', 30);

        $settlementResponse = $this->actingAs($director)
            ->post(route('club.finance-engine.expenses.reimburse', $pendingReimbursement), [
                'pay_to' => 'club_budget',
                'funds_location' => 'cash',
                'reimbursement_date' => '2026-05-16',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Reimbursement recorded');

        $pendingReimbursement->refresh();
        $this->assertSame('completed', $pendingReimbursement->status);
        $this->assertNull($pendingReimbursement->reimbursement_receipt_path);
        $this->assertNotNull($pendingReimbursement->reimbursement_receipt_token);
        $this->assertNull($pendingReimbursement->reimbursement_payment_proof_path);
        $this->assertNotEmpty($settlementResponse->json('data.reimbursement_confirmation_url'));
        $this->assertNotEmpty($settlementResponse->json('data.reimbursement_confirmation_qr_url'));

        $this->actingAs($director)
            ->post(route('club.finance-engine.expenses.reimbursement-payment-proof.upload', $pendingReimbursement), [
                'payment_proof_file' => UploadedFile::fake()->image('zelle-confirmation.jpg'),
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Reimbursement payment proof uploaded');

        $pendingReimbursement->refresh();
        $this->assertNotNull($pendingReimbursement->reimbursement_payment_proof_path);
        Storage::disk('public')->assertExists($pendingReimbursement->reimbursement_payment_proof_path);

        $receiptRouteParams = [
            'expense' => $pendingReimbursement,
            'token' => $pendingReimbursement->reimbursement_receipt_token,
        ];

        $this->get(route('reimbursement-receipts.show', $receiptRouteParams))
            ->assertOk();

        $qrResponse = $this->get(route('reimbursement-receipts.qr', $receiptRouteParams))
            ->assertOk();
        $this->assertStringContainsString('<svg', $qrResponse->getContent());

        $this->postJson(route('reimbursement-receipts.signature', $receiptRouteParams), [
            'signer_name' => 'Guest Sponsor',
            'signature_data' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/p9sAAAAASUVORK5CYII=',
            'acknowledged' => true,
        ])
            ->assertOk()
            ->assertJsonPath('message', 'Recibo firmado.')
            ->assertJsonPath('data.signer_name', 'Guest Sponsor')
            ->assertJsonPath('data.acknowledged', true)
            ->assertJsonPath('data.download_url', route('reimbursement-receipts.download', $receiptRouteParams));

        $pendingReimbursement->refresh();
        $this->assertNotNull($pendingReimbursement->reimbursement_receipt_signed_at);
        $this->assertSame('Guest Sponsor', $pendingReimbursement->reimbursement_receipt_signer_name);
        $this->assertTrue((bool) $pendingReimbursement->reimbursement_receipt_acknowledged);
        Storage::disk('public')->assertExists($pendingReimbursement->reimbursement_receipt_signature_path);
        $this->assertNotNull($pendingReimbursement->reimbursement_receipt_path);
        $this->assertNotNull($pendingReimbursement->reimbursement_receipt_validation_checksum);
        Storage::disk('public')->assertExists($pendingReimbursement->reimbursement_receipt_path);
        $this->assertDatabaseHas('document_validations', [
            'checksum' => $pendingReimbursement->reimbursement_receipt_validation_checksum,
            'document_type' => 'reimbursement_receipt',
            'title' => 'Recibo de reembolso #' . $pendingReimbursement->id,
        ]);

        $this->get(route('reimbursement-receipts.download', $receiptRouteParams))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        $settledCashbox = $this->actingAs($director)
            ->getJson(route('club.finance-engine.cashbox', ['club_id' => $club->id]))
            ->assertOk();
        $settledAccounts = collect($settledCashbox->json('data.engine_report.summary.accounts'));
        $settledClubBudgetSummary = $settledAccounts->firstWhere('account', 'club_budget');
        $settledReimbursementSummary = $settledAccounts->firstWhere('account', 'reimbursement_to');
        $this->assertSame(5.0, (float) $settledClubBudgetSummary['cash_balance']);
        $this->assertSame(0.0, (float) $settledReimbursementSummary['cash_balance']);
        $this->assertSame(5.0, (float) $settledCashbox->json('data.engine_report.summary.total_available'));

        $settlementPayment = Payment::query()
            ->where('settles_expense_id', $pendingReimbursement->id)
            ->where('payment_type', 'internal')
            ->firstOrFail();
        $settlementExpense = Expense::query()
            ->where('settles_expense_id', $pendingReimbursement->id)
            ->firstOrFail();

        $this->assertSame('Liquidacion de reembolso', $settlementPayment->concept_text);
        $this->assertSame($normalExpense->id, $settlementExpense->reimbursement_origin_expense_id);
        $this->assertSame($pendingReimbursement->reimbursement_receipt_path, $settlementExpense->receipt_path);
        $this->assertDatabaseHas('payment_receipts', [
            'payment_id' => $settlementPayment->id,
        ]);

        $engine = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', [
                'club_id' => $club->id,
                'domain' => 'expense',
            ]))
            ->assertOk();

        $reimbursementMovement = collect($engine->json('data.movements'))->firstWhere('movement_id', "expense:{$pendingReimbursement->id}");
        $this->assertSame('reimbursement', $reimbursementMovement['correction_type']);
        $this->assertTrue((bool) $reimbursementMovement['can_reverse']);
        $this->assertTrue(collect($reimbursementMovement['proofs'])->contains(
            fn (array $proof) => ($proof['type'] ?? null) === 'reimbursement_receipt'
        ));
        $this->assertTrue(collect($reimbursementMovement['proofs'])->contains(
            fn (array $proof) => ($proof['type'] ?? null) === 'reimbursement_payment_proof'
        ));
        $this->assertFalse(collect($reimbursementMovement['proofs'])->contains(
            fn (array $proof) => ($proof['type'] ?? null) === 'reimbursement_signed_receipt'
        ));
        $originMovement = collect($engine->json('data.movements'))->firstWhere('movement_id', "expense:{$normalExpense->id}");
        $settlementMovement = collect($engine->json('data.movements'))->firstWhere('movement_id', "expense:{$settlementExpense->id}");
        $this->assertSame($normalExpense->id, $reimbursementMovement['reimbursement_group']['origin_expense_id']);
        $this->assertSame($pendingReimbursement->id, $reimbursementMovement['reimbursement_group']['reimbursement_expense_id']);
        $this->assertSame($reimbursementMovement['reimbursement_group']['key'], $originMovement['reimbursement_group']['key']);
        $this->assertSame($reimbursementMovement['reimbursement_group']['key'], $settlementMovement['reimbursement_group']['key']);

        $allMovements = $this->actingAs($director)
            ->getJson(route('club.finance-engine.movements', ['club_id' => $club->id]))
            ->assertOk();
        $settlementPaymentMovement = collect($allMovements->json('data.movements'))->firstWhere('movement_id', "payment:{$settlementPayment->id}");
        $this->assertSame($reimbursementMovement['reimbursement_group']['key'], $settlementPaymentMovement['reimbursement_group']['key']);
    }

    public function test_reimbursements_can_settle_from_source_account_even_when_overall_total_is_negative(): void
    {
        [$director, $club] = $this->makeDirectorAndClub();
        $account = $this->createAccount($club, 'club_budget', 'Club Budget');

        foreach ([500, 250] as $amount) {
            $this->actingAs($director)
                ->postJson(route('club.finance-engine.expenses.store'), [
                    'club_id' => $club->id,
                    'pay_to' => 'club_budget',
                    'funds_location' => 'cash',
                    'amount' => $amount,
                    'expense_date' => '2026-05-13',
                    'description' => "Overflow expense {$amount}",
                    'reimbursement_target_mode' => 'new',
                    'reimbursement_payee_name' => "Sponsor {$amount}",
                ])
                ->assertCreated();
        }

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.income.store'), [
                'club_id' => $club->id,
                'concept_text' => 'Fund income after overflow',
                'pay_to' => 'club_budget',
                'amount_paid' => 600,
                'payment_date' => '2026-05-14',
                'payment_type' => 'cash',
                'payer_name' => 'Guest donor',
            ])
            ->assertCreated();
        $account->increment('balance', 600);

        $cashbox = $this->actingAs($director)
            ->getJson(route('club.finance-engine.cashbox', ['club_id' => $club->id]))
            ->assertOk();
        $accounts = collect($cashbox->json('data.engine_report.summary.accounts'));
        $this->assertSame(600.0, (float) $accounts->firstWhere('account', 'club_budget')['cash_balance']);
        $this->assertSame(-750.0, (float) $accounts->firstWhere('account', 'reimbursement_to')['cash_balance']);
        $this->assertSame(-150.0, (float) $cashbox->json('data.engine_report.summary.total_available'));

        $this->actingAs($director)
            ->postJson(route('club.finance-engine.income.store'), [
                'club_id' => $club->id,
                'concept_text' => 'Wrong liability income',
                'pay_to' => 'reimbursement_to',
                'amount_paid' => 10,
                'payment_date' => '2026-05-14',
                'payment_type' => 'cash',
                'payer_name' => 'Guest donor',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('pay_to');

        $reimbursement = Expense::query()
            ->where('club_id', $club->id)
            ->where('pay_to', 'reimbursement_to')
            ->where('amount', 500)
            ->firstOrFail();

        $this->actingAs($director)
            ->post(route('club.finance-engine.expenses.reimburse', $reimbursement), [
                'pay_to' => 'club_budget',
                'funds_location' => 'cash',
                'reimbursement_date' => '2026-05-15',
            ])
            ->assertOk()
            ->assertJsonPath('message', 'Reimbursement recorded');

        $settledCashbox = $this->actingAs($director)
            ->getJson(route('club.finance-engine.cashbox', ['club_id' => $club->id]))
            ->assertOk();
        $settledAccounts = collect($settledCashbox->json('data.engine_report.summary.accounts'));
        $this->assertSame(100.0, (float) $settledAccounts->firstWhere('account', 'club_budget')['cash_balance']);
        $this->assertSame(-250.0, (float) $settledAccounts->firstWhere('account', 'reimbursement_to')['cash_balance']);
        $this->assertSame(-150.0, (float) $settledCashbox->json('data.engine_report.summary.total_available'));
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
        $bankCashMovement = collect($engine->json('data.movements'))->firstWhere('reference', 'LOCAL-BANK-CASH');
        $this->assertNotNull($bankCashMovement);
        $this->assertSame(140.0, (float) $bankCashMovement['balance_after']['from']['account_balance']);
        $this->assertSame(100.0, (float) $bankCashMovement['balance_after']['to']['account_balance']);
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

        $this->actingAs($director)
            ->getJson(route('financial.accounts.pdf', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('file_name', 'account-balances.pdf');

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
        $paymentCancellationMovement = $movements->firstWhere('movement_id', "payment:{$paymentReverse}");
        $this->assertSame('cash', $paymentCancellationMovement['location']);
        $this->assertSame('cash', $paymentCancellationMovement['balance_payment_type']);
        $this->assertNotNull($paymentCancellationMovement['balance_after']);
        $finalMovement = $movements->sortBy(fn (array $movement) => sprintf(
            '%s-%010d-%s',
            $movement['occurred_at'] ?? $movement['date'] ?? '0000-00-00 00:00:00',
            $movement['id'] ?? 0,
            $movement['movement_id'] ?? ''
        ))->last();
        $this->assertSame(0.0, (float) ($finalMovement['balance_after']['account_balance'] ?? 0));

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
