<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\ParentPaymentSubmission;
use App\Models\PaymentReceipt;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ParentProfileDataOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_lists_only_relationships_with_other_churches(): void
    {
        [$homeChurch, $homeClub] = $this->churchAndClub('Home Church', 'Home Adventurers');
        [$otherChurch, $otherClub] = $this->churchAndClub('Other Church', 'Other Pathfinders', 'pathfinders');
        $parent = $this->parent($homeChurch, $homeClub);

        $this->child($homeClub, $parent, 'Home Child');
        $foreignChild = $this->child($otherClub, $parent, 'Foreign Child');
        $foreignChild->forceFill(['created_at' => '2026-01-15 10:00:00'])->saveQuietly();

        $this->actingAs($parent)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Profile')
                ->has('related_churches', 1)
                ->where('related_churches.0.church_name', $otherChurch->church_name)
                ->where('related_churches.0.club_name', $otherClub->club_name)
                ->where('related_churches.0.club_type', 'pathfinders')
                ->where('related_churches.0.children_count', 1)
                ->where('related_churches.0.related_since', '2026-01-15'));
    }

    public function test_family_data_and_then_parent_account_are_deleted_in_two_separate_stages(): void
    {
        Storage::fake('public');
        Storage::fake('local');
        [$church, $club] = $this->churchAndClub('Ownership Church', 'Ownership Club');
        $parent = $this->parent($church, $club);
        $child = $this->child($club, $parent, 'Private Child', 'signatures/private-child.png');
        Storage::disk('public')->put('signatures/private-child.png', 'signature');
        $proofPath = 'parent-payment-proofs/private-child.png';
        Storage::disk('local')->put($proofPath, 'payment-proof');
        ParentPaymentSubmission::query()->create([
            'club_id' => $club->id,
            'member_id' => $child->id,
            'parent_user_id' => $parent->id,
            'amount' => 25,
            'payment_date' => now()->toDateString(),
            'payment_type' => 'transfer',
            'receipt_image_path' => $proofPath,
            'receipt_image_disk' => 'local',
            'status' => 'pending',
        ]);
        $historicalStaffReceipt = PaymentReceipt::query()->create([
            'payment_id' => null,
            'club_id' => $club->id,
            'staff_user_id' => $parent->id,
            'receipt_number' => 'RCPT-2026-ACCOUNT-DELETION',
            'issued_to_type' => 'staff',
            'issued_to_email' => $parent->email,
            'issued_at' => now(),
            'delivery_status' => 'delivered',
        ]);

        $this->actingAs($parent)
            ->deleteJson(route('parent.profile.family-data.destroy'), [
                'current_password' => 'wrong-password',
                'confirmation' => 'DELETE MY CHILDREN',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('current_password');

        $stageOne = $this->deleteJson(route('parent.profile.family-data.destroy'), [
            'current_password' => 'password',
            'confirmation' => 'DELETE MY CHILDREN',
        ])->assertOk()
            ->assertJsonPath('children_deleted', 1);

        $this->assertDatabaseMissing('members', ['id' => $child->id]);
        $this->assertDatabaseMissing('members_adventurers', ['id' => $child->id_data]);
        Storage::disk('public')->assertMissing('signatures/private-child.png');
        Storage::disk('local')->assertMissing($proofPath);
        $this->assertNotNull($parent->fresh());
        $this->assertAuthenticatedAs($parent);

        $token = $stageOne->json('account_deletion_token');

        $this->deleteJson(route('parent.profile.account.destroy'), [
            'current_password' => 'password',
            'confirmation' => 'DELETE MY ACCOUNT',
            'deletion_token' => 'invalid-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('deletion_token');

        $this->deleteJson(route('parent.profile.account.destroy'), [
            'current_password' => 'password',
            'confirmation' => 'DELETE MY ACCOUNT',
            'deletion_token' => $token,
        ])->assertOk()
            ->assertJsonPath('redirect_url', route('login'));

        $this->assertDatabaseMissing('users', ['id' => $parent->id]);
        $this->assertDatabaseHas('payment_receipts', [
            'id' => $historicalStaffReceipt->id,
            'staff_user_id' => null,
            'issued_to_email' => null,
            'issued_to_type' => 'deleted_account',
        ]);
        $this->assertGuest();
    }

    public function test_parent_cannot_bypass_the_two_stage_flow_with_the_generic_profile_endpoint(): void
    {
        [$church, $club] = $this->churchAndClub('Protected Church', 'Protected Club');
        $parent = $this->parent($church, $club);
        $child = $this->child($club, $parent, 'Protected Child');

        $this->actingAs($parent)
            ->delete(route('profile.destroy'), ['password' => 'password'])
            ->assertForbidden();

        $this->assertDatabaseHas('users', ['id' => $parent->id]);
        $this->assertDatabaseHas('members', ['id' => $child->id]);
    }

    public function test_deleting_a_user_nulls_receipt_user_links_without_deleting_the_receipt(): void
    {
        [$church, $club] = $this->churchAndClub('Receipt History Church', 'Receipt History Club');
        $user = $this->parent($church, $club);
        $receipt = PaymentReceipt::query()->create([
            'payment_id' => null,
            'club_id' => $club->id,
            'parent_user_id' => $user->id,
            'staff_user_id' => $user->id,
            'receipt_number' => 'RCPT-2026-FK-SET-NULL',
            'issued_to_type' => 'parent',
            'issued_to_email' => $user->email,
            'issued_at' => now(),
            'delivery_status' => 'delivered',
        ]);

        User::query()->whereKey($user->id)->delete();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertDatabaseHas('payment_receipts', [
            'id' => $receipt->id,
            'parent_user_id' => null,
            'staff_user_id' => null,
        ]);
    }

    private function churchAndClub(string $churchName, string $clubName, string $clubType = 'adventurers'): array
    {
        $church = Church::create(['church_name' => $churchName]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $churchName,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => $clubName,
            'church_id' => $church->id,
            'church_name' => $churchName,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => $clubType,
            'status' => 'active',
        ]);

        return [$church, $club];
    }

    private function parent(Church $church, Club $club): User
    {
        return User::factory()->create([
            'name' => 'Ownership Parent',
            'email' => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'profile_type' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
    }

    private function child(Club $club, User $parent, string $name, ?string $signaturePath = null): Member
    {
        $detail = MemberAdventurer::create([
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $club->director_name,
            'church_name' => $club->church_name,
            'applicant_name' => $name,
            'birthdate' => '2017-01-01',
            'age' => 9,
            'grade' => '3',
            'mailing_address' => '1 Main Street',
            'cell_number' => '555-0100',
            'emergency_contact' => $parent->name,
            'parent_name' => $parent->name,
            'parent_cell' => '555-0101',
            'home_address' => '1 Main Street',
            'email_address' => $parent->email,
            'signature_type' => $signaturePath ? 'drawn' : 'typed',
            'signature_path' => $signaturePath,
            'signature' => $parent->name,
            'status' => 'active',
        ]);

        return Member::create([
            'type' => 'adventurers',
            'id_data' => $detail->id,
            'club_id' => $club->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
    }
}
