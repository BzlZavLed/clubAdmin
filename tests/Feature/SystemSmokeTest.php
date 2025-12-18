<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\ChurchInviteCode;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\Payment;
use App\Models\Staff;
use App\Models\StaffAdventurer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SystemSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_church_creation_generates_invite_code(): void
    {
        $response = $this->postJson('/churches', [
            'church_name' => 'Pacto de amor',
            'address' => '14230 Scaggsville Road',
            'ethnicity' => 'Hispanic',
            'phone_number' => '4438786759',
            'email' => 'pactodeamor@gmail.com',
            'pastor_name' => 'Orlando Cruz',
            'pastor_email' => 'ocruz@chesapeake.org',
            'conference' => 'Chesapeake',
        ]);

        $response->assertOk();
        $churchId = $response->json('church.id');
        $this->assertNotNull($churchId);

        $this->assertDatabaseHas('church_invite_codes', [
            'church_id' => $churchId,
            'status' => 'active',
        ]);
    }

    public function test_registration_requires_valid_church_invite_code(): void
    {
        $church = Church::create([
            'church_name' => 'Pacto de amor',
            'email' => 'pactodeamor@gmail.com',
        ]);

        $invite = ChurchInviteCode::create([
            'church_id' => $church->id,
            'code' => 'TESTCODE01',
            'uses_left' => null,
            'status' => 'active',
        ]);

        $response = $this->post('/register', [
            'name' => 'Director',
            'email' => 'director@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'profile_type' => 'club_director',
            'sub_role' => null,
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => 'new',
            'invite_code' => $invite->code,
        ]);

        $response->assertStatus(302);

        $this->assertDatabaseHas('users', [
            'email' => 'director@example.com',
            'profile_type' => 'club_director',
            'status' => 'active',
            'church_id' => $church->id,
        ]);
    }

    public function test_director_can_setup_club_classes_workplan_members_and_payments(): void
    {
        // Church + invite code
        $church = Church::create([
            'church_name' => 'Pacto de amor',
            'email' => 'pactodeamor@gmail.com',
        ]);

        // Director user (verified so club_director routes work)
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);

        // Create club
        $clubResponse = $this->actingAs($director)->post('/club', [
            'club_name' => 'Valdenses aventureros',
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'pastor_name' => 'Orlando Cruz',
            'conference_name' => 'Chesapeake',
            'conference_region' => '1',
            'club_type' => 'adventurers',
            'church_id' => $church->id,
        ]);
        $clubResponse->assertStatus(302);

        $club = Club::withoutGlobalScopes()->where('user_id', $director->id)->firstOrFail();
        $director->refresh();
        $this->assertSame($club->id, $director->club_id);

        // Create class
        $classResponse = $this->actingAs($director)->post('/club-classes', [
            'club_id' => $club->id,
            'class_order' => 1,
            'class_name' => 'Little Lambs',
        ]);
        $classResponse->assertStatus(302);

        $class = ClubClass::where('club_id', $club->id)->where('class_name', 'Little Lambs')->firstOrFail();

        // Create staff user + staff_adventurer detail + staff record
        $staffUser = User::factory()->create([
            'profile_type' => 'club_personal',
            'sub_role' => 'staff',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'club_id' => $club->id,
            'status' => 'active',
            'email_verified_at' => now(),
        ]);
        DB::table('club_user')->updateOrInsert(
            ['user_id' => $staffUser->id, 'club_id' => $club->id],
            ['status' => 'active', 'created_at' => now(), 'updated_at' => now()]
        );

        $staffAdv = StaffAdventurer::create([
            'name' => $staffUser->name,
            'email' => $staffUser->email,
            'applicant_signature' => $staffUser->name,
            'application_signed_date' => now()->toDateString(),
        ]);

        $staff = Staff::create([
            'type' => 'adventurers',
            'id_data' => $staffAdv->id,
            'club_id' => $club->id,
            'user_id' => $staffUser->id,
            'status' => 'active',
        ]);

        // Assign staff to class (updates staff.assigned_class + class_staff pivot)
        $this->actingAs($director)
            ->putJson(route('staff.update-class'), [
                'staff_id' => $staff->id,
                'class_id' => $class->id,
            ])
            ->assertOk();

        $staff->refresh();
        $this->assertSame($class->id, $staff->assigned_class);

        // Create workplan for club
        $workplanPayload = [
            'start_date' => now()->startOfMonth()->toDateString(),
            'end_date' => now()->addMonth()->endOfMonth()->toDateString(),
            'timezone' => 'America/New_York',
            'default_sabbath_location' => 'Church',
            'default_sunday_location' => 'Park',
            'default_sabbath_start_time' => '09:00',
            'default_sabbath_end_time' => '11:00',
            'default_sunday_start_time' => '10:00',
            'default_sunday_end_time' => '12:00',
            'rules' => [
                ['meeting_type' => 'sabbath', 'nth_week' => 1, 'note' => 'First sabbath'],
                ['meeting_type' => 'sunday', 'nth_week' => 3, 'note' => 'Third sunday'],
            ],
        ];
        $this->actingAs($director)->postJson(route('club.workplan.confirm'), $workplanPayload)
            ->assertOk()
            ->assertJsonPath('workplan.club_id', $club->id);

        // Create payment concept (class scope)
        $conceptRes = $this->actingAs($director)->postJson(route('clubs.payment-concepts.store', ['club' => $club->id]), [
            'concept' => 'Registration fee',
            'payment_expected_by' => now()->addWeeks(1)->toDateString(),
            'amount' => 25.00,
            'type' => 'mandatory',
            'pay_to' => 'club_budget',
            'status' => 'active',
            'scopes' => [
                ['scope_type' => 'class', 'class_id' => $class->id],
            ],
        ]);
        $conceptRes->assertStatus(201);
        $conceptId = $conceptRes->json('data.id');
        $this->assertNotNull($conceptId);

        // Create member (adventurer) + mirror into members table
        $memberPost = $this->actingAs($director)->post('/members', [
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'director_name' => $director->name,
            'church_name' => $church->church_name,
            'applicant_name' => 'Test Kid',
            'birthdate' => '2017-01-01',
            'age' => 8,
            'grade' => '3',
            'mailing_address' => '123 Test St',
            'cell_number' => '555-111-2222',
            'emergency_contact' => 'Mom',
            'investiture_classes' => ['Little Lambs'],
            'allergies' => 'None',
            'physical_restrictions' => 'None',
            'health_history' => 'None',
            'parent_name' => 'Parent',
            'parent_cell' => '555-333-4444',
            'home_address' => '123 Test St',
            'email_address' => 'parent@example.com',
            'signature' => 'Parent',
        ]);
        $memberPost->assertStatus(302);

        $memberAdv = MemberAdventurer::where('club_id', $club->id)->where('applicant_name', 'Test Kid')->firstOrFail();
        $memberRow = Member::where('club_id', $club->id)->where('type', 'adventurers')->where('id_data', $memberAdv->id)->firstOrFail();

        // Assign member to class (writes assignment + sets members.class_id)
        $this->actingAs($director)->postJson(route('members.assign'), [
            'member_id' => $memberRow->id,
            'club_class_id' => $class->id,
            'role' => 'student',
            'assigned_at' => now()->toDateString(),
        ])->assertOk();

        $memberRow->refresh();
        $this->assertSame($class->id, $memberRow->class_id);

        // Record payment as staff user (club-personal flow)
        $payRes = $this->actingAs($staffUser)->post(route('club.payments.store'), [
            'payment_concept_id' => $conceptId,
            'member_id' => $memberRow->id,
            'amount_paid' => 10.00,
            'payment_date' => now()->toDateString(),
            'payment_type' => 'cash',
            'notes' => 'Test payment',
        ]);
        $payRes->assertStatus(201);
        $paymentId = $payRes->json('data.id');
        $this->assertNotNull($paymentId);

        $this->assertDatabaseHas('payments', [
            'id' => $paymentId,
            'club_id' => $club->id,
            'payment_concept_id' => $conceptId,
            'member_id' => $memberRow->id,
        ]);

        // Change password endpoint works
        $this->actingAs($director)->put(route('users.updatePassword', ['id' => $staffUser->id]), [
            'password' => 'newpassword123',
        ])->assertOk();

        $staffUser->refresh();
        $this->assertTrue(password_verify('newpassword123', $staffUser->password));
    }
}

