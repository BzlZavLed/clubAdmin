<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\Member;
use App\Models\Payment;
use App\Models\PaymentConcept;
use App\Models\User;
use App\Services\PaymentReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PaymentReceiptAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_receipt_download_enforces_active_role_and_club_access(): void
    {
        Queue::fake();
        [$receipt, $owner, $club, $church] = $this->receiptContext();

        $this->actingAs($owner)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertOk()
            ->assertJsonPath('success', true);

        $owner->forceFill(['status' => 'inactive'])->save();
        $this->actingAs($owner->fresh())
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertForbidden();

        $directorActivatedParent = User::factory()->unverified()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'parent_activation_method' => 'director',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $receipt->forceFill(['parent_user_id' => $directorActivatedParent->id])->save();

        $this->actingAs($directorActivatedParent)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertOk()
            ->assertJsonPath('success', true);

        $unrelatedParent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $this->actingAs($unrelatedParent)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertForbidden();

        $treasurer = User::factory()->create([
            'profile_type' => 'treasurer',
            'role_key' => 'treasurer',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $this->actingAs($treasurer)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertOk()
            ->assertJsonPath('success', true);

        $unverifiedTreasurer = User::factory()->unverified()->create([
            'profile_type' => 'treasurer',
            'role_key' => 'treasurer',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $this->actingAs($unverifiedTreasurer)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertForbidden();

        [, $otherClub, $otherChurch] = $this->clubContext('Other');
        $otherDirector = User::factory()->create([
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'club_id' => $otherClub->id,
            'church_id' => $otherChurch->id,
            'status' => 'active',
        ]);
        $this->actingAs($otherDirector)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertForbidden();

        $inactiveSuperadmin = User::factory()->create([
            'profile_type' => 'superadmin',
            'role_key' => 'superadmin',
            'status' => 'inactive',
        ]);
        $this->actingAs($inactiveSuperadmin)
            ->getJson(route('payment-receipts.download', $receipt))
            ->assertForbidden();
    }

    private function receiptContext(): array
    {
        [$director, $club, $church] = $this->clubContext('Receipt');
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $member = Member::query()->create([
            'type' => 'adventurers',
            'id_data' => 710001,
            'club_id' => $club->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
        $concept = PaymentConcept::query()->create([
            'concept' => 'Receipt authorization test',
            'type' => 'mandatory',
            'pay_to' => 'club_budget',
            'created_by' => $director->id,
            'status' => 'active',
            'club_id' => $club->id,
            'amount' => 25,
            'reusable' => false,
        ]);
        $payment = Payment::query()->create([
            'club_id' => $club->id,
            'payment_concept_id' => $concept->id,
            'concept_text' => $concept->concept,
            'pay_to' => $concept->pay_to,
            'member_id' => $member->id,
            'amount_paid' => 25,
            'expected_amount' => 25,
            'balance_due_after' => 0,
            'payment_date' => now()->toDateString(),
            'payment_type' => 'transfer',
            'received_by_user_id' => $director->id,
        ]);
        $receipt = app(PaymentReceiptService::class)->syncForPayment($payment);

        return [$receipt, $parent, $club, $church];
    }

    private function clubContext(string $prefix): array
    {
        $church = Church::query()->create([
            'church_name' => "$prefix Church",
            'email' => str($prefix)->slug().'-church@example.test',
        ]);
        $director = User::factory()->create([
            'name' => "$prefix Director",
            'email' => str($prefix)->slug().'-director@example.test',
            'profile_type' => 'club_director',
            'role_key' => 'club_director',
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $club = Club::query()->create([
            'user_id' => $director->id,
            'club_name' => "$prefix Club",
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => 'adventurers',
            'status' => 'active',
        ]);
        $director->forceFill(['club_id' => $club->id])->save();

        return [$director, $club, $church];
    }
}
