<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\BankInfo;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\DocumentValidation;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\Expense;
use App\Models\EventTask;
use App\Models\EventTaskAssignment;
use App\Models\Member;
use App\Models\MemberPathfinder;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentConcept;
use App\Models\PaymentReceipt;
use App\Models\Staff;
use App\Models\Union;
use App\Models\UnionClubCatalog;
use App\Models\User;
use App\Models\EventPlan;
use App\Services\EventFinanceService;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HierarchicalEventFinanceFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_association_event_flows_down_to_targeted_clubs_and_supports_incremental_redeposit_receipts(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $districtNorth = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $districtSouth = District::create(['name' => 'South District', 'association_id' => $association->id, 'status' => 'active']);

        $churchNorth = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $districtNorth->id]);
        $churchSouth = Church::create(['church_name' => 'South Church', 'email' => 'south@example.com', 'district_id' => $districtSouth->id]);
        $churchAdventurer = Church::create(['church_name' => 'Adventure Church', 'email' => 'adventurer@example.com', 'district_id' => $districtNorth->id]);

        [$clubDirectorNorth, $pathfinderClubNorth] = $this->createClubWithDirector($churchNorth, $districtNorth, 'pathfinders', 'North Pathfinders');
        [$clubDirectorSouth, $pathfinderClubSouth] = $this->createClubWithDirector($churchSouth, $districtSouth, 'pathfinders', 'South Pathfinders');
        [$adventurerDirector, $adventurerClub] = $this->createClubWithDirector($churchAdventurer, $districtNorth, 'adventurers', 'Adventure Club');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $title = 'Association Camporee 2026';

        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$pathfinderClubNorth, $pathfinderClubSouth],
            ['pathfinders'],
            $title,
            [
                ['label' => 'Insurance', 'amount' => 50],
                ['label' => 'T-Shirt', 'amount' => 50],
                ['label' => 'Signup', 'amount' => 100],
            ],
            true
        );

        $this->assertSame('association', $event->scope_type);
        $this->assertEqualsCanonicalizing(
            [$pathfinderClubNorth->id, $pathfinderClubSouth->id],
            $event->targetClubs()->pluck('clubs.id')->all()
        );

        $this->actingAs($clubDirectorNorth)
            ->get(route('events.show', $event))
            ->assertOk();

        $this->actingAs($adventurerDirector)
            ->get(route('events.show', $event))
            ->assertForbidden();

        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $member = $this->createPathfinderMember($pathfinderClubNorth, $parent, 'North Camper');

        $this->actingAs($clubDirectorNorth)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $member->id,
                'participant_name' => 'North Camper',
                'role' => 'kid',
                'status' => 'confirmed',
                'permission_received' => true,
                'medical_form_received' => true,
            ])
            ->assertOk();

        $event->refresh();
        $this->assertSame(
            'signed_up',
            $event->targetClubs()->where('clubs.id', $pathfinderClubNorth->id)->firstOrFail()->pivot->signup_status
        );

        $concepts = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->orderBy('club_id')
            ->orderBy('id')
            ->get();

        $this->assertCount(6, $concepts);
        $this->assertSame(3, $concepts->where('club_id', $pathfinderClubNorth->id)->where('status', 'active')->count());
        $this->assertSame(3, $concepts->where('club_id', $pathfinderClubSouth->id)->where('status', 'inactive')->count());
        $this->assertSame(0, $concepts->where('club_id', $adventurerClub->id)->count());

        $clubConcepts = $concepts
            ->where('club_id', $pathfinderClubNorth->id)
            ->keyBy('concept');

        $insuranceConcept = $clubConcepts->first(fn (PaymentConcept $concept) => str_contains($concept->concept, 'Insurance'));
        $shirtConcept = $clubConcepts->first(fn (PaymentConcept $concept) => str_contains($concept->concept, 'T-Shirt'));
        $signupConcept = $clubConcepts->first(fn (PaymentConcept $concept) => str_contains($concept->concept, 'Signup'));

        $this->assertNotNull($insuranceConcept);
        $this->assertNotNull($shirtConcept);
        $this->assertNotNull($signupConcept);

        $insurancePayment = $this->recordMemberPayment($insuranceConcept, $member, $clubDirectorNorth, 50, '2026-05-01');
        $shirtPayment = $this->recordMemberPayment($shirtConcept, $member, $clubDirectorNorth, 50, '2026-05-01');

        $this->assertDatabaseCount('payment_receipts', 2);
        $this->assertSame(
            'parent',
            PaymentReceipt::query()->where('payment_id', $insurancePayment->id)->value('issued_to_type')
        );

        $service = app(EventFinanceService::class);
        $summary = collect($service->clubSignupSummary($event))->firstWhere('club_id', $pathfinderClubNorth->id);

        $this->assertSame(200.0, $summary['expected_amount']);
        $this->assertSame(100.0, $summary['paid_amount']);
        $this->assertSame(100.0, $summary['remaining_amount']);
        $this->assertSame(0.0, $summary['deposited_amount']);
        $this->assertSame(100.0, $summary['pending_settlement_amount']);
        $this->assertCount(2, $summary['pending_settlement_breakdown']);

        $this->actingAs($clubDirectorNorth)
            ->from(route('events.show', $event))
            ->post(route('event-club-settlements.store', $event), [
                'club_id' => $pathfinderClubNorth->id,
                'deposited_at' => '2026-05-02 10:00:00',
                'reference' => 'ASSOC-DEP-001',
                'notes' => 'First transfer to association',
            ])
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHas('success');

        $this->assertSame(1, EventClubSettlement::query()->where('event_id', $event->id)->where('club_id', $pathfinderClubNorth->id)->count());
        $this->assertSame('100.00', EventClubSettlement::query()->where('event_id', $event->id)->where('club_id', $pathfinderClubNorth->id)->firstOrFail()->amount);

        $signupPayment = $this->recordMemberPayment($signupConcept, $member, $clubDirectorNorth, 100, '2026-05-03');

        $this->assertDatabaseCount('payment_receipts', 3);
        $this->assertNotNull(PaymentReceipt::query()->where('payment_id', $signupPayment->id)->value('receipt_number'));

        $summaryAfterLateClubPayment = collect($service->clubSignupSummary($event))->firstWhere('club_id', $pathfinderClubNorth->id);
        $this->assertSame(200.0, $summaryAfterLateClubPayment['paid_amount']);
        $this->assertSame(0.0, $summaryAfterLateClubPayment['remaining_amount']);
        $this->assertSame(100.0, $summaryAfterLateClubPayment['deposited_amount']);
        $this->assertSame(100.0, $summaryAfterLateClubPayment['pending_settlement_amount']);
        $this->assertCount(1, $summaryAfterLateClubPayment['pending_settlement_breakdown']);
        $this->assertSame('Signup', $summaryAfterLateClubPayment['pending_settlement_breakdown'][0]['label']);

        $this->actingAs($clubDirectorNorth)
            ->from(route('events.show', $event))
            ->post(route('event-club-settlements.store', $event), [
                'club_id' => $pathfinderClubNorth->id,
                'deposited_at' => '2026-05-04 14:00:00',
                'reference' => 'ASSOC-DEP-002',
                'notes' => 'Late accepted payment redeposit',
            ])
            ->assertRedirect(route('events.show', $event))
            ->assertSessionHas('success');

        $settlements = EventClubSettlement::query()
            ->where('event_id', $event->id)
            ->where('club_id', $pathfinderClubNorth->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $settlements);
        $this->assertSame([100.0, 100.0], $settlements->map(fn (EventClubSettlement $settlement) => (float) $settlement->amount)->all());
        $this->assertNotSame($settlements[0]->receipt_number, $settlements[1]->receipt_number);

        $associationView = collect($service->clubSignupSummary($event))->firstWhere('club_id', $pathfinderClubNorth->id);
        $this->assertSame('North District', $associationView['district_name']);
        $this->assertSame(200.0, $associationView['deposited_amount']);
        $this->assertSame(0.0, $associationView['pending_settlement_amount']);
        $this->assertCount(2, $associationView['settlement_receipts']);
    }

    public function test_event_breakdown_can_be_paid_as_one_payment_with_component_allocations(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$club],
            ['pathfinders'],
            'Association Camporee Bundle',
            [
                ['label' => 'Insurance', 'amount' => 50],
                ['label' => 'T-Shirt', 'amount' => 50],
                ['label' => 'Signup', 'amount' => 100],
            ],
            true
        );

        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $member = $this->createPathfinderMember($club, $parent, 'North Camper');

        $this->actingAs($clubDirector)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $member->id,
                'participant_name' => 'North Camper',
                'role' => 'kid',
                'status' => 'confirmed',
            ])
            ->assertOk();

        app(EventFinanceService::class)->syncPaymentConcepts($event->fresh(), $associationDirector->id);

        $conceptIds = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('club_id', $club->id)
            ->where('status', 'active')
            ->orderBy('event_fee_component_id')
            ->pluck('id')
            ->all();

        $this->assertCount(3, $conceptIds);

        $this->actingAs($clubDirector)
            ->postJson(route('club.payments.store'), [
                'club_id' => $club->id,
                'event_concept_ids' => $conceptIds,
                'member_id' => $member->id,
                'amount_paid' => 200,
                'payment_date' => '2026-05-05',
                'payment_type' => 'cash',
            ])
            ->assertCreated();

        $payment = Payment::query()->where('member_id', $member->id)->firstOrFail();

        $this->assertNull($payment->payment_concept_id);
        $this->assertSame('200.00', $payment->amount_paid);
        $this->assertSame(1, Payment::query()->where('member_id', $member->id)->count());
        $this->assertSame(1, PaymentReceipt::query()->where('payment_id', $payment->id)->count());
        $this->assertEqualsCanonicalizing([50.0, 50.0, 100.0], PaymentAllocation::query()
            ->where('payment_id', $payment->id)
            ->pluck('amount')
            ->map(fn ($amount) => (float) $amount)
            ->all());

        $summary = collect(app(EventFinanceService::class)->clubSignupSummary($event->fresh()))->firstWhere('club_id', $club->id);

        $this->assertSame(200.0, $summary['paid_amount']);
        $this->assertSame(0.0, $summary['remaining_amount']);
        $this->assertSame(200.0, $summary['pending_settlement_amount']);
        $this->assertCount(3, $summary['pending_settlement_breakdown']);
    }

    public function test_event_optional_components_do_not_count_member_as_enrolled_until_required_fee_is_paid(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $member = $this->createPathfinderMember($club, $parent, 'North Camper');

        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$club],
            ['pathfinders'],
            'Association Camporee Optional Shirt',
            [
                ['label' => 'Inscripción', 'amount' => 50, 'is_required' => true],
                ['label' => 'Camiseta', 'amount' => 35, 'is_required' => false],
            ],
            true
        );

        $this->actingAs($clubDirector)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $member->id,
                'participant_name' => 'North Camper',
                'role' => 'kid',
                'status' => 'confirmed',
            ])
            ->assertOk();

        $concepts = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('club_id', $club->id)
            ->with('eventFeeComponent:id,label,is_required')
            ->get();

        $registration = $concepts->first(fn (PaymentConcept $concept) => $concept->eventFeeComponent?->label === 'Inscripción');
        $shirt = $concepts->first(fn (PaymentConcept $concept) => $concept->eventFeeComponent?->label === 'Camiseta');

        $this->assertNotNull($registration);
        $this->assertNotNull($shirt);
        $this->assertTrue((bool) $registration->eventFeeComponent->is_required);
        $this->assertFalse((bool) $shirt->eventFeeComponent->is_required);

        $this->actingAs($clubDirector)
            ->postJson(route('club.payments.store'), [
                'club_id' => $club->id,
                'event_concept_ids' => [$shirt->id],
                'member_id' => $member->id,
                'amount_paid' => 35,
                'payment_date' => '2026-05-05',
                'payment_type' => 'cash',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_concept_ids');

        $this->actingAs($clubDirector)
            ->postJson(route('club.payments.store'), [
                'club_id' => $club->id,
                'event_concept_ids' => [$registration->id],
                'member_id' => $member->id,
                'amount_paid' => 50,
                'payment_date' => '2026-05-05',
                'payment_type' => 'cash',
            ])
            ->assertCreated();

        $summaryAfterRegistration = collect(app(EventFinanceService::class)->clubSignupSummary($event->fresh()))->firstWhere('club_id', $club->id);
        $this->assertSame(50.0, $summaryAfterRegistration['expected_amount']);
        $this->assertSame(50.0, $summaryAfterRegistration['required_paid_amount']);
        $this->assertSame(0.0, $summaryAfterRegistration['optional_paid_amount']);
        $this->assertSame(0.0, $summaryAfterRegistration['remaining_amount']);
        $this->assertSame(50.0, $summaryAfterRegistration['pending_settlement_amount']);
        $this->assertSame(['Inscripción'], collect($summaryAfterRegistration['pending_settlement_breakdown'])->pluck('label')->all());

        $ownerResponse = $this->actingAs($associationDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $ownerProps = $ownerResponse->viewData('page')['props'];
        $clubSummary = collect($ownerProps['participantClubSummary'])->firstWhere('club_id', $club->id);
        $this->assertSame(1, $clubSummary['paid_member_count']);
        $this->assertSame(1, $clubSummary['confirmed_paid_member_count']);
        $this->assertSame(0, $clubSummary['confirmed_unpaid_member_count']);
        $this->assertTrue($clubSummary['has_required_payment']);
        $this->assertSame(50.0, $clubSummary['expected_member_payment']);
        $rosterRow = collect($ownerProps['participantRoster'])->firstWhere('member_id', $member->id);
        $this->assertNotNull($rosterRow);
        $this->assertTrue($rosterRow['is_confirmed']);
        $this->assertTrue($rosterRow['is_enrolled']);
        $this->assertSame(50.0, $rosterRow['total_paid']);
        $this->assertSame('not_paid', $rosterRow['optional_status']);
        $this->assertSame('Camiseta', $rosterRow['optional_components'][0]['label']);
        $this->assertFalse($rosterRow['optional_components'][0]['is_paid']);

        $this->actingAs($clubDirector)
            ->postJson(route('club.payments.store'), [
                'club_id' => $club->id,
                'event_concept_ids' => [$shirt->id],
                'member_id' => $member->id,
                'amount_paid' => 35,
                'payment_date' => '2026-05-06',
                'payment_type' => 'cash',
            ])
            ->assertCreated();

        $summaryAfterOptional = collect(app(EventFinanceService::class)->clubSignupSummary($event->fresh()))->firstWhere('club_id', $club->id);
        $this->assertSame(85.0, $summaryAfterOptional['paid_amount']);
        $this->assertSame(50.0, $summaryAfterOptional['required_paid_amount']);
        $this->assertSame(35.0, $summaryAfterOptional['optional_paid_amount']);
        $this->assertSame(85.0, $summaryAfterOptional['pending_settlement_amount']);
        $this->assertEqualsCanonicalizing(
            ['Inscripción', 'Camiseta'],
            collect($summaryAfterOptional['pending_settlement_breakdown'])->pluck('label')->all()
        );
        $ownerAfterOptional = $this->actingAs($associationDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $rosterAfterOptional = collect($ownerAfterOptional->viewData('page')['props']['participantRoster'])->firstWhere('member_id', $member->id);
        $this->assertSame(85.0, $rosterAfterOptional['total_paid']);
        $this->assertSame(35.0, $rosterAfterOptional['optional_paid']);
        $this->assertSame('paid', $rosterAfterOptional['optional_status']);
        $this->assertTrue($rosterAfterOptional['optional_components'][0]['is_paid']);

        $overdueClubTask = EventTask::create([
            'event_id' => $event->id,
            'title' => 'Cargar permisos medicos',
            'description' => 'Tarea vencida usada para validar estado de preparacion.',
            'due_at' => now()->subDay(),
            'status' => 'todo',
            'responsibility_level' => 'club',
        ]);
        EventTaskAssignment::create([
            'event_task_id' => $overdueClubTask->id,
            'scope_type' => 'club',
            'scope_id' => $club->id,
            'status' => 'todo',
        ]);

        $readinessResponse = $this->actingAs($associationDirector)
            ->get(route('events.readiness', $event))
            ->assertOk();
        $readiness = $readinessResponse->viewData('page')['props']['readiness'];
        $this->assertSame(1, $readiness['totals']['clubs']);
        $this->assertSame(0, $readiness['totals']['blocked_clubs']);
        $this->assertSame(1, $readiness['totals']['enrolled_members']);
        $this->assertSame('placeholder', $readiness['reminder_processor']['status']);
        $this->assertNotEmpty($readiness['reminders']);
        $this->assertSame('not_ready', $readiness['closeout']['status']);
        $this->assertSame('Pendientes por completar', $readiness['clubs'][0]['status_label']);
        $taskBlocker = collect($readiness['clubs'][0]['blockers'])->firstWhere('type', 'tasks_pending');
        $this->assertSame('pending', $taskBlocker['severity']);
        $financialReport = $readiness['financial_report'];
        $this->assertEqualsCanonicalizing(['Inscripción', 'Camiseta'], collect($financialReport['components'])->pluck('label')->all());
        $this->assertSame(1, $financialReport['totals']['clubs']);
        $this->assertSame(1, $financialReport['totals']['participants']);
        $this->assertSame(85.0, $financialReport['totals']['paid_amount']);
        $financeClub = collect($financialReport['clubs'])->firstWhere('club_id', $club->id);
        $registrationComponent = collect($financialReport['components'])->firstWhere('label', 'Inscripción');
        $shirtComponent = collect($financialReport['components'])->firstWhere('label', 'Camiseta');
        $this->assertSame(50.0, $financeClub['component_amounts'][(string) $registrationComponent['id']]['paid_amount']);
        $this->assertSame(35.0, $financeClub['component_amounts'][(string) $shirtComponent['id']]['paid_amount']);
        $financeParticipant = collect($financialReport['participants'])->firstWhere('member_id', $member->id);
        $this->assertSame(50.0, $financeParticipant['component_amounts'][(string) $registrationComponent['id']]['paid_amount']);
        $this->assertSame(35.0, $financeParticipant['component_amounts'][(string) $shirtComponent['id']]['paid_amount']);

        $readinessPdfResponse = $this->actingAs($associationDirector)
            ->get(route('events.readiness.pdf', $event))
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $readinessPdfResponse->headers->get('content-type'));
        $this->assertTrue(DocumentValidation::query()->where('document_type', 'event_readiness_report')->exists());

        $financialPdfResponse = $this->actingAs($associationDirector)
            ->get(route('events.readiness.financial.pdf', ['event' => $event, 'include_targeted' => 0, 'include_breakdown' => 0]))
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $financialPdfResponse->headers->get('content-type'));
        $this->assertTrue(DocumentValidation::query()->where('document_type', 'event_financial_report')->exists());
        $financialValidation = DocumentValidation::query()->where('document_type', 'event_financial_report')->latest('id')->firstOrFail();
        $this->assertFalse((bool) data_get($financialValidation->document_snapshot, 'snapshot.include_participant_breakdown'));
    }

    public function test_readiness_marks_targeted_club_with_no_activity_as_attention_critical(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$club],
            ['pathfinders'],
            'Association Camporee No Activity',
            [
                ['label' => 'Inscripción', 'amount' => 50, 'is_required' => true],
            ],
            true
        );

        $readiness = $this->actingAs($associationDirector)
            ->get(route('events.readiness', $event))
            ->assertOk()
            ->viewData('page')['props']['readiness'];

        $this->assertSame(1, $readiness['totals']['blocked_clubs']);
        $this->assertSame('Atencion critica requerida', $readiness['clubs'][0]['status_label']);
        $signupBlocker = collect($readiness['clubs'][0]['blockers'])->firstWhere('type', 'signup_pending');
        $this->assertSame('blocking', $signupBlocker['severity']);
        $this->assertSame('Club sin avance', $signupBlocker['label']);
        $this->assertSame(1, $readiness['financial_report']['totals']['clubs']);
        $this->assertSame(0.0, $readiness['financial_report']['totals']['paid_amount']);
        $this->assertSame($club->id, $readiness['financial_report']['clubs'][0]['club_id']);
    }

    public function test_owner_participant_summary_counts_required_payment_as_member_enrollment_without_manual_confirmation(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $member = $this->createPathfinderMember($club, $parent, 'North Camper');
        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$club],
            ['pathfinders'],
            'Association Camporee Registration',
            [
                ['label' => 'Inscripción', 'amount' => 50, 'is_required' => true],
            ],
            true
        );

        $this->actingAs($clubDirector)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $member->id,
                'participant_name' => 'North Camper',
                'role' => 'kid',
                'status' => 'invited',
            ])
            ->assertOk();

        $registration = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('club_id', $club->id)
            ->where('status', 'active')
            ->firstOrFail();

        $this->actingAs($clubDirector)
            ->postJson(route('club.payments.store'), [
                'club_id' => $club->id,
                'event_concept_ids' => [$registration->id],
                'member_id' => $member->id,
                'amount_paid' => 50,
                'payment_date' => '2026-05-05',
                'payment_type' => 'cash',
            ])
            ->assertCreated();

        $ownerResponse = $this->actingAs($associationDirector)
            ->get(route('events.show', $event))
            ->assertOk();

        $clubSummary = collect($ownerResponse->viewData('page')['props']['participantClubSummary'])->firstWhere('club_id', $club->id);
        $this->assertSame(1, $clubSummary['enrolled_member_count']);
        $this->assertSame(0, $clubSummary['manual_confirmed_member_count']);
        $this->assertSame(0, $clubSummary['confirmed_unpaid_member_count']);
        $this->assertSame(1, $clubSummary['paid_member_count']);
        $this->assertTrue($clubSummary['has_required_payment']);
    }

    public function test_owner_participant_summary_separates_staff_confirmation_from_staff_enrollment_payment(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');
        $staff = $this->createStaffForClub($club, 'North Staff');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$club],
            ['pathfinders'],
            'Association Staff Registration',
            [
                ['label' => 'Inscripción', 'amount' => 50, 'is_required' => true],
            ],
            true
        );
        $event->targetClubs()->updateExistingPivot($club->id, [
            'signup_status' => 'signed_up',
            'signed_up_at' => now(),
        ]);

        $this->actingAs($clubDirector)
            ->postJson(route('event-participants.store', $event), [
                'staff_id' => $staff->id,
                'participant_name' => 'North Staff',
                'role' => 'staff',
                'status' => 'confirmed',
            ])
            ->assertOk();

        $ownerBeforePayment = $this->actingAs($associationDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $beforeRow = collect($ownerBeforePayment->viewData('page')['props']['participantClubSummary'])->firstWhere('club_id', $club->id);
        $this->assertSame(1, $beforeRow['confirmed_staff_count']);
        $this->assertSame(1, $beforeRow['confirmed_unpaid_staff_count']);
        $this->assertSame(0, $beforeRow['enrolled_staff_count']);
        $beforeRosterStaff = collect($ownerBeforePayment->viewData('page')['props']['participantRoster'])->firstWhere('staff_id', $staff->id);
        $this->assertSame('staff', $beforeRosterStaff['participant_type']);
        $this->assertTrue($beforeRosterStaff['is_confirmed']);
        $this->assertFalse($beforeRosterStaff['is_enrolled']);
        $this->assertSame(50.0, $beforeRosterStaff['required_expected']);

        $readinessBeforePayment = $this->actingAs($associationDirector)
            ->get(route('events.readiness', $event))
            ->assertOk()
            ->viewData('page')['props']['readiness'];
        $this->assertSame(0, $readinessBeforePayment['totals']['blocked_clubs']);
        $this->assertSame('Pendientes por completar', $readinessBeforePayment['clubs'][0]['status_label']);
        $staffPaymentBlocker = collect($readinessBeforePayment['clubs'][0]['blockers'])->firstWhere('type', 'staff_payment_missing');
        $this->assertSame('pending', $staffPaymentBlocker['severity']);

        $concept = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('club_id', $club->id)
            ->firstOrFail();
        $this->recordStaffPayment($concept, $staff, $clubDirector, 50, '2026-05-06');

        $ownerAfterPayment = $this->actingAs($associationDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $afterRow = collect($ownerAfterPayment->viewData('page')['props']['participantClubSummary'])->firstWhere('club_id', $club->id);
        $this->assertSame(1, $afterRow['confirmed_staff_count']);
        $this->assertSame(0, $afterRow['confirmed_unpaid_staff_count']);
        $this->assertSame(1, $afterRow['enrolled_staff_count']);
        $this->assertSame(1, $afterRow['paid_staff_count']);
        $this->assertSame(1, $afterRow['confirmed_paid_staff_count']);
        $afterRosterStaff = collect($ownerAfterPayment->viewData('page')['props']['participantRoster'])->firstWhere('staff_id', $staff->id);
        $this->assertTrue($afterRosterStaff['is_confirmed']);
        $this->assertTrue($afterRosterStaff['is_enrolled']);
        $this->assertSame(50.0, $afterRosterStaff['total_paid']);
        $this->assertSame(50.0, $afterRosterStaff['required_paid']);

        $pdfResponse = $this->actingAs($associationDirector)
            ->get(route('events.participant-roster.pdf', $event))
            ->assertOk();
        $this->assertStringContainsString('application/pdf', $pdfResponse->headers->get('content-type'));
    }

    public function test_parent_portal_and_event_deposit_module_use_separate_bank_info(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        $associationDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $association->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $member = $this->createPathfinderMember($club, $parent, 'North Camper');

        BankInfo::query()->where('bankable_type', Club::class)
            ->where('bankable_id', $club->id)
            ->where('pay_to', 'club_budget')
            ->firstOrFail()
            ->update([
                'label' => 'Cuenta del club',
                'bank_name' => 'Club Bank',
                'account_holder' => 'North Pathfinders',
                'account_number' => '123456789',
                'routing_number' => '021000021',
                'is_active' => true,
                'accepts_parent_deposits' => true,
                'accepts_event_deposits' => false,
                'requires_receipt_upload' => true,
            ]);

        BankInfo::create([
            'bankable_type' => Association::class,
            'bankable_id' => $association->id,
            'pay_to' => 'association_budget',
            'label' => 'Cuenta de asociación',
            'bank_name' => 'Association Bank',
            'account_holder' => $association->name,
            'account_number' => '987654321',
            'routing_number' => '111000025',
            'is_active' => true,
            'accepts_parent_deposits' => false,
            'accepts_event_deposits' => true,
            'requires_receipt_upload' => true,
        ]);

        $event = $this->createHierarchicalEvent(
            $associationDirector,
            'association',
            $association->id,
            [$club],
            ['pathfinders'],
            'Association Camporee 2026',
            [['label' => 'Registration', 'amount' => 25]],
            true
        );

        $this->actingAs($clubDirector)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $member->id,
                'participant_name' => 'North Camper',
                'role' => 'kid',
                'status' => 'confirmed',
            ])
            ->assertOk();

        $parentResponse = $this->actingAs($parent)
            ->get(route('parent.payments.index'))
            ->assertOk();
        $parentProps = $parentResponse->viewData('page')['props'];
        $charge = collect($parentProps['expected_payments'])->firstWhere('event_title', 'Association Camporee 2026');

        $this->assertNotNull($charge);
        $this->assertSame('Club Bank', $charge['deposit_account']['bank_name']);
        $this->assertSame('123456789', $charge['deposit_account']['account_number']);

        $concept = PaymentConcept::query()
            ->where('event_id', $event->id)
            ->where('club_id', $club->id)
            ->firstOrFail();
        $this->recordMemberPayment($concept, $member, $clubDirector, 25, '2026-05-06');

        $settlementResponse = $this->actingAs($clubDirector)
            ->getJson(route('club.director.event-settlements.index', ['club_id' => $club->id]))
            ->assertOk();

        $this->assertSame('Association Bank', $settlementResponse->json('data.0.organizer_bank_info.bank_name'));
        $this->assertSame(25, $settlementResponse->json('data.0.pending_settlement_amount'));
        $this->assertSame(1, $settlementResponse->json('data.0.paid_members_count'));
        $this->assertSame('North Camper', $settlementResponse->json('data.0.paid_members.0.name'));
        $this->assertSame(25, $settlementResponse->json('data.0.paid_members.0.total_paid'));
        $this->assertSame('Registration', $settlementResponse->json('data.0.paid_members.0.breakdown.0.label'));
    }

    public function test_treasury_tracks_cash_bank_locations_and_local_movements(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Cash dues',
            'pay_to' => 'club_budget',
            'amount_paid' => 100,
            'payment_date' => '2026-05-01',
            'payment_type' => 'cash',
            'received_by_user_id' => $clubDirector->id,
        ]);

        Payment::create([
            'club_id' => $club->id,
            'concept_text' => 'Electronic dues',
            'pay_to' => 'club_budget',
            'amount_paid' => 50,
            'payment_date' => '2026-05-01',
            'payment_type' => 'transfer',
            'received_by_user_id' => $clubDirector->id,
        ]);

        Expense::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'funds_location' => 'cash',
            'amount' => 15,
            'expense_date' => '2026-05-01',
            'description' => 'Cash snacks',
            'created_by_user_id' => $clubDirector->id,
            'status' => 'completed',
        ]);

        Expense::create([
            'club_id' => $club->id,
            'pay_to' => 'club_budget',
            'funds_location' => 'bank',
            'amount' => 10,
            'expense_date' => '2026-05-01',
            'description' => 'Bank supplies',
            'created_by_user_id' => $clubDirector->id,
            'status' => 'completed',
        ]);

        $treasuryResponse = $this->actingAs($clubDirector)
            ->getJson(route('club.director.treasury.data', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('summary.cash_balance', 85)
            ->assertJsonPath('summary.bank_balance', 40);
        $this->assertSame('club_budget', $treasuryResponse->json('income_rows.0.pay_to'));
        $this->assertNotEmpty($treasuryResponse->json('income_rows.0.account_label'));

        $this->actingAs($clubDirector)
            ->postJson(route('club.director.treasury.movements.store'), [
                'club_id' => $club->id,
                'movement_type' => 'cash_deposit',
                'amount' => 40,
                'movement_date' => '2026-05-02',
                'reference' => 'DEP-001',
            ])
            ->assertCreated();

        $this->actingAs($clubDirector)
            ->getJson(route('club.director.treasury.data', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('summary.cash_balance', 45)
            ->assertJsonPath('summary.bank_balance', 80);

        $this->actingAs($clubDirector)
            ->postJson(route('club.director.treasury.movements.store'), [
                'club_id' => $club->id,
                'movement_type' => 'cash_withdrawal',
                'amount' => 10,
                'movement_date' => '2026-05-03',
                'reference' => 'WDR-001',
            ])
            ->assertCreated();

        $this->actingAs($clubDirector)
            ->getJson(route('club.director.treasury.data', ['club_id' => $club->id]))
            ->assertOk()
            ->assertJsonPath('summary.cash_balance', 55)
            ->assertJsonPath('summary.bank_balance', 70);

        $report = $this->actingAs($clubDirector)
            ->getJson(route('financial.accounts', ['club_id' => $club->id]))
            ->assertOk();

        $clubBudget = collect($report->json('data.accounts'))->firstWhere('account', 'club_budget');
        $this->assertSame(55.0, (float) $clubBudget['cash_balance']);
        $this->assertSame(70.0, (float) $clubBudget['bank_balance']);
        $this->assertSame(25.0, (float) $clubBudget['expenses']);
        $this->assertSame(125.0, (float) $clubBudget['balance']);
        $this->assertSame('cash', collect($report->json('data.expenses'))->firstWhere('description', 'Cash snacks')['location']);
        $this->assertSame('bank', collect($report->json('data.expenses'))->firstWhere('description', 'Bank supplies')['location']);

        $ledger = $this->actingAs($clubDirector)
            ->getJson(route('financial.report', [
                'mode' => 'account',
                'club_id' => $club->id,
            ]))
            ->assertOk();

        $ledgerAccount = collect($ledger->json('data.accounts'))->firstWhere('pay_to', 'club_budget');
        $this->assertSame(55.0, (float) $ledgerAccount['totals']['cash_balance']);
        $this->assertSame(70.0, (float) $ledgerAccount['totals']['bank_balance']);
        $this->assertSame(50.0, (float) $ledgerAccount['totals']['movements']);
        $this->assertContains('treasury_movement', collect($ledgerAccount['entries'])->pluck('entry_type')->all());

        $cashLedger = $this->actingAs($clubDirector)
            ->getJson(route('financial.report', [
                'mode' => 'account',
                'club_id' => $club->id,
                'location' => 'cash',
            ]))
            ->assertOk();
        $cashEntries = collect($cashLedger->json('data.accounts.0.entries'));
        $this->assertSame(['cash'], $cashEntries->where('entry_type', 'payment')->pluck('location')->unique()->values()->all());
        $this->assertSame(['cash'], $cashEntries->where('entry_type', 'expense')->pluck('location')->unique()->values()->all());

        $bankLedger = $this->actingAs($clubDirector)
            ->getJson(route('financial.report', [
                'mode' => 'account',
                'club_id' => $club->id,
                'location' => 'bank',
            ]))
            ->assertOk();
        $bankEntries = collect($bankLedger->json('data.accounts.0.entries'));
        $this->assertSame(['bank'], $bankEntries->where('entry_type', 'payment')->pluck('location')->unique()->values()->all());
        $this->assertSame(['bank'], $bankEntries->where('entry_type', 'expense')->pluck('location')->unique()->values()->all());
    }

    public function test_superadmin_can_load_treasury_and_event_settlement_data_from_active_club_context(): void
    {
        [$union, $association] = $this->seedUnionAndAssociation();
        $this->seedClubCatalogs($union);

        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
        [, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

        $superadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'scope_type' => 'global',
            'scope_id' => null,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $session = ['superadmin_context' => ['club_id' => $club->id]];

        $this->actingAs($superadmin)
            ->withSession($session)
            ->getJson(route('club.director.treasury.data'))
            ->assertOk()
            ->assertJsonPath('club.id', $club->id);

        $this->actingAs($superadmin)
            ->withSession($session)
            ->getJson(route('club.director.event-settlements.index'))
            ->assertOk()
            ->assertJsonPath('club.id', $club->id);
    }

	    public function test_zelle_payments_store_sender_phone_separately_from_club_receiver_phone(): void
	    {
	        [$union, $association] = $this->seedUnionAndAssociation();
	        $this->seedClubCatalogs($union);

	        $district = District::create(['name' => 'North District', 'association_id' => $association->id, 'status' => 'active']);
	        $church = Church::create(['church_name' => 'North Church', 'email' => 'north@example.com', 'district_id' => $district->id]);
	        [$clubDirector, $club] = $this->createClubWithDirector($church, $district, 'pathfinders', 'North Pathfinders');

	        $parent = User::factory()->create([
	            'profile_type' => 'parent',
	            'role_key' => 'parent',
	            'sub_role' => null,
	            'status' => 'active',
	            'email_verified_at' => now(),
	        ]);
	        $member = $this->createPathfinderMember($club, $parent, 'North Camper');

	        $concept = PaymentConcept::create([
	            'club_id' => $club->id,
	            'concept' => 'Monthly dues',
	            'amount' => 20,
	            'payment_expected_by' => '2026-05-31',
	            'type' => 'mandatory',
	            'status' => 'active',
	            'pay_to' => 'club_budget',
	            'created_by' => $clubDirector->id,
	        ]);

	        $payload = [
	            'club_id' => $club->id,
	            'payment_concept_id' => $concept->id,
	            'member_id' => $member->id,
	            'amount_paid' => 20,
	            'payment_date' => '2026-05-01',
	            'payment_type' => 'zelle',
	        ];

	        $this->actingAs($clubDirector)
	            ->postJson(route('club.payments.store'), $payload)
	            ->assertStatus(422)
	            ->assertJsonPath('message', 'Ingresa el teléfono Zelle desde donde se envió el dinero.');

	        $this->actingAs($clubDirector)
	            ->postJson(route('club.payments.store'), $payload + ['zelle_phone' => '555-2121'])
	            ->assertCreated();

	        $this->assertDatabaseHas('payments', [
	            'club_id' => $club->id,
	            'payment_concept_id' => $concept->id,
	            'payment_type' => 'zelle',
	            'zelle_phone' => '555-2121',
	        ]);

	        $this->assertSame('555-0100', BankInfo::query()->where('bankable_id', $club->id)->value('zelle_phone'));
	    }

	    public function test_union_event_summary_tracks_expected_paid_and_deposited_amounts_across_districts_and_associations(): void
    {
        $union = Union::create(['name' => 'Continental Union', 'status' => 'active']);
        $this->seedClubCatalogs($union);

        $associationEast = Association::create(['name' => 'East Association', 'union_id' => $union->id, 'status' => 'active']);
        $associationWest = Association::create(['name' => 'West Association', 'union_id' => $union->id, 'status' => 'active']);

        $districtEast = District::create(['name' => 'East District', 'association_id' => $associationEast->id, 'status' => 'active']);
        $districtWest = District::create(['name' => 'West District', 'association_id' => $associationWest->id, 'status' => 'active']);

        $churchEast = Church::create(['church_name' => 'East Church', 'email' => 'east@example.com', 'district_id' => $districtEast->id]);
        $churchWest = Church::create(['church_name' => 'West Church', 'email' => 'west@example.com', 'district_id' => $districtWest->id]);
        $churchAdventurer = Church::create(['church_name' => 'West Adventurer Church', 'email' => 'west-adv@example.com', 'district_id' => $districtWest->id]);

        [$directorEast, $clubEast] = $this->createClubWithDirector($churchEast, $districtEast, 'pathfinders', 'East Pathfinders');
        [$directorWest, $clubWest] = $this->createClubWithDirector($churchWest, $districtWest, 'pathfinders', 'West Pathfinders');
        [, $adventurerClub] = $this->createClubWithDirector($churchAdventurer, $districtWest, 'adventurers', 'West Adventurers');

        $unionDirector = User::factory()->create([
            'profile_type' => 'union_youth_director',
            'role_key' => 'union_youth_director',
            'scope_type' => 'union',
            'scope_id' => $union->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $associationEastDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $associationEast->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $associationWestDirector = User::factory()->create([
            'profile_type' => 'association_youth_director',
            'role_key' => 'association_youth_director',
            'scope_type' => 'association',
            'scope_id' => $associationWest->id,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $title = 'Union Mega Camporee 2026';

        $event = $this->createHierarchicalEvent(
            $unionDirector,
            'union',
            $union->id,
            [$clubEast, $clubWest],
            ['pathfinders'],
            $title,
            [
                ['label' => 'Insurance', 'amount' => 60],
                ['label' => 'Registration', 'amount' => 140],
            ],
            true
        );

        $this->assertEqualsCanonicalizing(
            [$clubEast->id, $clubWest->id],
            $event->targetClubs()->pluck('clubs.id')->all()
        );

        $eastParent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        $westParent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $eastMember = $this->createPathfinderMember($clubEast, $eastParent, 'East Participant');
        $westMember = $this->createPathfinderMember($clubWest, $westParent, 'West Participant');

        $this->actingAs($directorEast)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $eastMember->id,
                'participant_name' => 'East Participant',
                'role' => 'kid',
                'status' => 'confirmed',
            ])
            ->assertOk();

        $this->actingAs($directorWest)
            ->postJson(route('event-participants.store', $event), [
                'member_id' => $westMember->id,
                'participant_name' => 'West Participant',
                'role' => 'kid',
                'status' => 'confirmed',
            ])
            ->assertOk();

        $eastConcepts = PaymentConcept::query()->where('event_id', $event->id)->where('club_id', $clubEast->id)->get()->keyBy('concept');
        $westConcepts = PaymentConcept::query()->where('event_id', $event->id)->where('club_id', $clubWest->id)->get()->keyBy('concept');

        $eastInsurance = $eastConcepts->first(fn (PaymentConcept $concept) => str_contains($concept->concept, 'Insurance'));
        $eastRegistration = $eastConcepts->first(fn (PaymentConcept $concept) => str_contains($concept->concept, 'Registration'));
        $westInsurance = $westConcepts->first(fn (PaymentConcept $concept) => str_contains($concept->concept, 'Insurance'));

        $this->recordMemberPayment($eastInsurance, $eastMember, $directorEast, 60, '2026-05-10');
        $this->recordMemberPayment($eastRegistration, $eastMember, $directorEast, 140, '2026-05-10');
        $this->recordMemberPayment($westInsurance, $westMember, $directorWest, 60, '2026-05-10');

        $eastAssociationResponse = $this->actingAs($associationEastDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $eastAssociationProps = $eastAssociationResponse->viewData('page')['props'];
        $this->assertFalse($eastAssociationProps['canEditEvent']);
        $this->assertSame([$clubEast->id], collect($eastAssociationProps['clubSignupSummary'])->pluck('club_id')->all());
        $this->assertSame(200.0, collect($eastAssociationProps['clubSignupSummary'])->first()['pending_settlement_amount']);
        $eastParticipantRow = collect($eastAssociationProps['participantClubSummary'])->first();
        $this->assertSame($clubEast->id, $eastParticipantRow['club_id']);
        $this->assertSame(1, $eastParticipantRow['paid_member_count']);
        $this->assertSame(0, $eastParticipantRow['confirmed_unpaid_member_count']);

        $westAssociationResponse = $this->actingAs($associationWestDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $westAssociationProps = $westAssociationResponse->viewData('page')['props'];
        $this->assertFalse($westAssociationProps['canEditEvent']);
        $this->assertSame([$clubWest->id], collect($westAssociationProps['clubSignupSummary'])->pluck('club_id')->all());
        $this->assertSame(60.0, collect($westAssociationProps['clubSignupSummary'])->first()['pending_settlement_amount']);
        $westParticipantRow = collect($westAssociationProps['participantClubSummary'])->first();
        $this->assertSame($clubWest->id, $westParticipantRow['club_id']);
        $this->assertSame(0, $westParticipantRow['paid_member_count']);
        $this->assertSame(1, $westParticipantRow['confirmed_unpaid_member_count']);

        $unionOwnerResponse = $this->actingAs($unionDirector)
            ->get(route('events.show', $event))
            ->assertOk();
        $unionOwnerProps = $unionOwnerResponse->viewData('page')['props'];
        $this->assertTrue($unionOwnerProps['canEditEvent']);
        $unionRoster = collect($unionOwnerProps['participantRoster']);
        $this->assertCount(2, $unionRoster);
        $eastRoster = $unionRoster->firstWhere('member_id', $eastMember->id);
        $westRoster = $unionRoster->firstWhere('member_id', $westMember->id);
        $this->assertSame('East Association', $eastRoster['association_name']);
        $this->assertSame('West Association', $westRoster['association_name']);
        $this->assertTrue($eastRoster['is_confirmed']);
        $this->assertTrue($eastRoster['is_enrolled']);
        $this->assertSame(200.0, $eastRoster['total_paid']);
        $this->assertTrue($westRoster['is_confirmed']);
        $this->assertFalse($westRoster['is_enrolled']);
        $this->assertSame(60.0, $westRoster['total_paid']);
        $this->assertSame(200.0, $westRoster['required_expected']);

        $this->actingAs($directorEast)
            ->from(route('events.show', $event))
            ->post(route('event-club-settlements.store', $event), [
                'club_id' => $clubEast->id,
                'deposited_at' => '2026-05-11 09:00:00',
                'reference' => 'UNION-EAST-001',
            ])
            ->assertRedirect(route('events.show', $event));

        $this->actingAs($directorWest)
            ->from(route('events.show', $event))
            ->post(route('event-club-settlements.store', $event), [
                'club_id' => $clubWest->id,
                'deposited_at' => '2026-05-11 09:15:00',
                'reference' => 'UNION-WEST-001',
            ])
            ->assertRedirect(route('events.show', $event));

        $summary = collect(app(EventFinanceService::class)->clubSignupSummary($event));

        $this->assertCount(2, $summary);
        $this->assertEqualsCanonicalizing(['East District', 'West District'], $summary->pluck('district_name')->all());

        $eastRow = $summary->firstWhere('club_id', $clubEast->id);
        $westRow = $summary->firstWhere('club_id', $clubWest->id);

        $this->assertSame(200.0, $eastRow['expected_amount']);
        $this->assertSame(200.0, $eastRow['paid_amount']);
        $this->assertSame(0.0, $eastRow['remaining_amount']);
        $this->assertSame(200.0, $eastRow['deposited_amount']);
        $this->assertSame(0.0, $eastRow['pending_settlement_amount']);
        $this->assertCount(1, $eastRow['settlement_receipts']);

        $this->assertSame(200.0, $westRow['expected_amount']);
        $this->assertSame(60.0, $westRow['paid_amount']);
        $this->assertSame(140.0, $westRow['remaining_amount']);
        $this->assertSame(60.0, $westRow['deposited_amount']);
        $this->assertSame(0.0, $westRow['pending_settlement_amount']);
        $this->assertCount(1, $westRow['settlement_receipts']);
        $this->assertSame(60.0, $westRow['settlement_receipts'][0]['amount']);

        $this->actingAs($unionDirector)
            ->get(route('events.show', $event))
            ->assertOk();
    }

    protected function seedUnionAndAssociation(): array
    {
        $union = Union::create(['name' => 'Atlantic Union', 'status' => 'active']);
        $association = Association::create(['name' => 'Central Association', 'union_id' => $union->id, 'status' => 'active']);

        return [$union, $association];
    }

    protected function seedClubCatalogs(Union $union): void
    {
        UnionClubCatalog::create([
            'union_id' => $union->id,
            'name' => 'Pathfinders',
            'club_type' => 'pathfinders',
            'sort_order' => 1,
            'status' => 'active',
        ]);

        UnionClubCatalog::create([
            'union_id' => $union->id,
            'name' => 'Adventurers',
            'club_type' => 'adventurers',
            'sort_order' => 2,
            'status' => 'active',
        ]);
    }

    protected function createClubWithDirector(Church $church, District $district, string $clubType, string $clubName): array
    {
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'scope_type' => 'club',
            'scope_id' => null,
            'sub_role' => null,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => $clubName,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'district_id' => $district->id,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'pastor_name' => 'Pastor ' . $district->name,
            'conference_name' => $district->association->name,
            'conference_region' => '1',
            'club_type' => $clubType,
            'status' => 'active',
        ]);

        $director->update([
            'club_id' => $club->id,
            'scope_id' => $club->id,
        ]);

        BankInfo::create([
            'bankable_type' => Club::class,
            'bankable_id' => $club->id,
            'pay_to' => 'club_budget',
            'label' => 'Cuenta del club',
            'bank_name' => 'Test Bank',
            'account_holder' => $clubName,
            'account_number' => '123456789',
            'routing_number' => '021000021',
            'zelle_phone' => '555-0100',
            'is_active' => true,
            'accepts_parent_deposits' => true,
            'requires_receipt_upload' => true,
        ]);

        return [$director->fresh(), $club->fresh()];
    }

    protected function createHierarchicalEvent(
        User $creator,
        string $scopeType,
        int $scopeId,
        array $targetClubs,
        array $targetClubTypes,
        string $title,
        array $feeComponents,
        bool $isMandatory = true
    ): Event {
        $anchorClub = collect($targetClubs)->first();

        $event = Event::create([
            'club_id' => $anchorClub->id,
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'target_club_types' => $targetClubTypes,
            'created_by_user_id' => $creator->id,
            'title' => $title,
            'description' => 'Test hierarchy event',
            'event_type' => 'camp',
            'start_at' => now()->addDays(10),
            'end_at' => now()->addDays(12),
            'timezone' => 'America/New_York',
            'status' => 'draft',
            'requires_approval' => false,
            'is_mandatory' => $isMandatory,
            'is_payable' => false,
            'payment_amount' => null,
        ]);

        $event->targetClubs()->sync(collect($targetClubs)->map(fn (Club $club) => $club->id)->all());

        EventPlan::create([
            'event_id' => $event->id,
            'schema_version' => 1,
            'plan_json' => ['sections' => []],
            'missing_items_json' => [],
            'conversation_json' => [],
        ]);

        $finance = app(EventFinanceService::class);
        $finance->syncFeeComponents($event, $feeComponents);
        $finance->syncPaymentConcepts($event->fresh(), $creator->id);

        return $event->fresh();
    }

    protected function createPathfinderMember(Club $club, User $parent, string $applicantName): Member
    {
        $profile = MemberPathfinder::create([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $club->director_name,
            'church_name' => $club->church_name,
            'applicant_name' => $applicantName,
            'birthdate' => '2012-01-01',
            'father_guardian_name' => 'Test Guardian',
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
        ]);

        $profile->update(['member_id' => $member->id]);

        return $member->fresh();
    }

    protected function createStaffForClub(Club $club, string $name): Staff
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

        return Staff::create([
            'type' => $club->club_type,
            'id_data' => 1,
            'club_id' => $club->id,
            'user_id' => $staffUser->id,
            'status' => 'active',
        ])->fresh();
    }

    protected function recordMemberPayment(PaymentConcept $concept, Member $member, User $receiver, float $amount, string $paymentDate): Payment
    {
        $payment = Payment::create([
            'club_id' => $concept->club_id,
            'payment_concept_id' => $concept->id,
            'concept_text' => $concept->concept,
            'pay_to' => $concept->pay_to,
            'account_id' => null,
            'member_id' => $member->id,
            'staff_id' => null,
            'amount_paid' => $amount,
            'expected_amount' => $concept->amount,
            'payment_date' => $paymentDate,
            'payment_type' => 'transfer',
            'zelle_phone' => null,
            'balance_due_after' => max((float) $concept->amount - $amount, 0),
            'check_image_path' => null,
            'received_by_user_id' => $receiver->id,
            'notes' => 'Test event payment',
        ]);

        app(PaymentReceiptService::class)->syncForPayment($payment);

        return $payment->fresh();
    }

    protected function recordStaffPayment(PaymentConcept $concept, Staff $staff, User $receiver, float $amount, string $paymentDate): Payment
    {
        $payment = Payment::create([
            'club_id' => $concept->club_id,
            'payment_concept_id' => $concept->id,
            'concept_text' => $concept->concept,
            'pay_to' => $concept->pay_to,
            'account_id' => null,
            'member_id' => null,
            'staff_id' => $staff->id,
            'amount_paid' => $amount,
            'expected_amount' => $concept->amount,
            'payment_date' => $paymentDate,
            'payment_type' => 'transfer',
            'zelle_phone' => null,
            'balance_due_after' => max((float) $concept->amount - $amount, 0),
            'check_image_path' => null,
            'received_by_user_id' => $receiver->id,
            'notes' => 'Test event staff payment',
        ]);

        app(PaymentReceiptService::class)->syncForPayment($payment);

        return $payment->fresh();
    }
}
