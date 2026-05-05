<?php

namespace Tests\Feature;

use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Event;
use App\Models\EventClubSettlement;
use App\Models\Member;
use App\Models\MemberPathfinder;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\PaymentReceipt;
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
            'payment_type' => 'cash',
            'zelle_phone' => null,
            'balance_due_after' => max((float) $concept->amount - $amount, 0),
            'check_image_path' => null,
            'received_by_user_id' => $receiver->id,
            'notes' => 'Test event payment',
        ]);

        app(PaymentReceiptService::class)->syncForPayment($payment);

        return $payment->fresh();
    }
}
