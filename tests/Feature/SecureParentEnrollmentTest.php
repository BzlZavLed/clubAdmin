<?php

namespace Tests\Feature;

use App\Models\Church;
use App\Models\Club;
use App\Models\ClubParentEnrollmentLink;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\User;
use App\Services\DocumentExportService;
use App\Mail\ParentEmailVerificationMail;
use App\Mail\ParentPasswordResetMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SecureParentEnrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_secure_link_holds_parent_for_email_confirmation_then_opens_the_portal(): void
    {
        Storage::fake('public');
        Mail::fake();
        [$director, $club] = $this->directorAndClub();

        $response = $this->actingAs($director)->postJson(route('club.settings.enrollment.secure-link.regenerate'), [
            'club_id' => $club->id,
        ])->assertOk();
        $link = ClubParentEnrollmentLink::query()->where('club_id', $club->id)->firstOrFail();
        $this->assertSame(route('parent.register.secure', $link->token), $response->json('data.url'));
        $this->assertSame(route('club.settings.enrollment.secure-link.qr', $link), $response->json('data.qr_url'));

        $qrResponse = $this->get(route('club.settings.enrollment.secure-link.qr', $link))
            ->assertOk()
            ->assertHeader('content-type', 'image/png');
        $this->assertStringStartsWith("\x89PNG", $qrResponse->getContent());
        $qrDimensions = getimagesizefromstring($qrResponse->getContent());
        $this->assertIsArray($qrDimensions);
        $this->assertGreaterThan($qrDimensions[0], $qrDimensions[1], 'The QR image should include a caption below the square code.');
        $this->get(route('club.settings.enrollment.secure-link.qr', ['link' => $link, 'download' => 1]))
            ->assertOk()
            ->assertDownload('secure-parent-enrollment-club-' . $club->id . '.png');

        auth()->logout();
        $this->get(route('parent.register.secure', $link->token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/RegisterParent')
                ->where('secure_enrollment.club.id', $club->id)
                ->where('secure_enrollment.club.club_name', $club->club_name));

        $this->from(route('parent.register.secure', $link->token))
            ->post(route('parent.register.secure.store', $link->token), [])
            ->assertRedirect(route('parent.register.secure', $link->token))
            ->assertSessionHasErrors(['name', 'email', 'password']);
        $this->assertGuest();

        $this->post(route('parent.register.secure.store', $link->token), [
            'name' => 'Secure Parent',
            'email' => 'secure-parent@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertRedirect(route('verification.notice'));

        $parent = User::query()->where('email', 'secure-parent@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($parent);
        $this->assertSame('active', $parent->status);
        $this->assertSame($link->id, $parent->secure_enrollment_link_id);
        $this->assertNull($parent->enrollment_confirmed_at);
        $this->assertNull($parent->email_verified_at);

        $verificationUrl = null;
        Mail::assertSent(ParentEmailVerificationMail::class, function (ParentEmailVerificationMail $mail) use (&$verificationUrl, $parent) {
            $verificationUrl = $mail->actionUrl;

            return $mail->hasTo($parent->email);
        });
        $this->assertNotNull($verificationUrl);

        $this->get(route('parent.dashboard'))
            ->assertRedirect(route('verification.notice'));

        $this->get($verificationUrl)
            ->assertRedirect(route('parent.dashboard'));
        $parent->refresh();
        $this->assertNotNull($parent->email_verified_at);
        $this->assertSame('email', $parent->parent_activation_method);
        $this->assertNotNull($parent->enrollment_confirmed_at);
        $this->assertNull($parent->enrollment_confirmed_by);

        $this->get(route('parent.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Dashboard')
                ->where('registration_success', true)
                ->where('auth.user.account_church_id', $club->church_id)
                ->where('auth.user.account_church_name', $club->church->church_name));

        $this->get(route('parent.apply'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Apply')
                ->has('clubs', 1)
                ->where('clubs.0.id', $club->id));

        $this->post(route('parent.apply.submit'), [
            'club_id' => $club->id,
            'club_name' => $club->club_name,
            'church_name' => $club->church_name,
            'director_name' => $director->name,
            'applicant_name' => 'Secure Child',
            'birthdate' => '2018-01-01',
            'age' => 8,
            'grade' => '2',
            'mailing_address' => '1 Main Street',
            'cell_number' => '555-111-2222',
            'emergency_contact' => 'Secure Parent',
            'parent_name' => 'Secure Parent',
            'parent_cell' => '555-111-2222',
            'home_address' => '1 Main Street',
            'email_address' => 'secure-parent@example.com',
            'signature_type' => 'drawn',
            'signature_data' => $this->signaturePngDataUri(),
        ])->assertRedirect(route('parent.dashboard'));

        $member = Member::query()->where('parent_id', $parent->id)->firstOrFail();
        $memberDetails = MemberAdventurer::query()->findOrFail($member->id_data);
        $this->assertSame('drawn', $memberDetails->signature_type);
        $this->assertSame('Secure Parent', $memberDetails->signature);
        Storage::disk('public')->assertExists($memberDetails->signature_path);
        $this->assertSame($link->id, $member->secure_enrollment_link_id);
        $this->assertNull($member->enrollment_confirmed_at);

        $this->get(route('parent-links.index.parent'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Children')
                ->where('children.0.club_name', $club->club_name)
                ->where('children.0.church_name', $club->church->church_name));

        $this->actingAs($director)
            ->get(route('club.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('enrollment_confirmation_requests.total', 0)
                ->has('enrollment_confirmation_requests.parents', 0));
    }

    public function test_revoked_secure_link_cannot_be_used_and_other_directors_cannot_confirm_requests(): void
    {
        [$director, $club] = $this->directorAndClub();
        [$otherDirector] = $this->directorAndClub('Other');

        $this->actingAs($director)->postJson(route('club.settings.enrollment.secure-link.regenerate'), ['club_id' => $club->id])->assertOk();
        $link = ClubParentEnrollmentLink::query()->where('club_id', $club->id)->firstOrFail();
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'club_id' => $club->id,
            'status' => 'active',
            'secure_enrollment_link_id' => $link->id,
        ]);

        $this->actingAs($otherDirector)
            ->postJson(route('club.enrollment-confirmations.parents.confirm', $parent))
            ->assertForbidden();
        $this->get(route('club.settings.enrollment.secure-link.qr', $link))->assertForbidden();

        $this->actingAs($director)
            ->deleteJson(route('club.settings.enrollment.secure-link.revoke'), ['club_id' => $club->id])
            ->assertOk();

        auth()->logout();
        $this->get(route('parent.register.secure', $link->token))->assertNotFound();

        $this->actingAs($director)
            ->get(route('club.settings.enrollment.secure-link.qr', $link))
            ->assertNotFound();
    }

    public function test_director_activation_is_a_portal_only_fallback_without_password_self_service(): void
    {
        [$director, $club] = $this->directorAndClub('Fallback');
        $link = ClubParentEnrollmentLink::create([
            'club_id' => $club->id,
            'token' => str_repeat('f', 64),
            'created_by' => $director->id,
        ]);
        $parent = User::factory()->unverified()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $club->church_id,
            'status' => 'active',
            'secure_enrollment_link_id' => $link->id,
            'parent_activation_method' => null,
            'enrollment_confirmed_at' => null,
        ]);
        $parent->clubs()->attach($club->id, ['status' => 'active']);

        $this->actingAs($parent)->get(route('parent.dashboard'))->assertRedirect(route('verification.notice'));

        $this->actingAs($director)
            ->get(route('club.dashboard'))
            ->assertInertia(fn (Assert $page) => $page
                ->where('enrollment_confirmation_requests.total', 1)
                ->where('enrollment_confirmation_requests.parents.0.email_status', 'waiting'));

        $this->postJson(route('club.enrollment-confirmations.parents.confirm', $parent))
            ->assertOk()
            ->assertJsonPath('data.total', 0)
            ->assertJsonPath('data.director_activated_parents.0.id', $parent->id);

        $parent->refresh();
        $this->assertSame('director', $parent->parent_activation_method);
        $this->assertNull($parent->email_verified_at);
        $this->assertSame($director->id, $parent->enrollment_confirmed_by);
        $this->assertTrue($parent->canAccessParentPortal());
        $this->assertFalse($parent->canSelfServiceCredentials());

        $this->actingAs($director)
            ->get(route('club.staff'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('ClubDirector/Staff')
                ->has('parent_accounts', 1)
                ->where('parent_accounts.0.id', $parent->id)
                ->where('parent_accounts.0.parent_activation_method', 'director'));

        $this->putJson(route('club.enrollment-confirmations.parents.password', $parent), [
            'password' => 'director-password123',
            'password_confirmation' => 'director-password123',
        ])->assertOk();
        $this->assertTrue(Hash::check('director-password123', $parent->fresh()->password));

        $this->actingAs($parent)->get(route('parent.dashboard'))->assertOk();
        $this->get(route('profile.edit'))->assertForbidden();
        $this->put(route('password.update'), [
            'current_password' => 'director-password123',
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ])->assertForbidden();

        auth()->logout();
        $this->post(route('password.email'), ['email' => $parent->email])
            ->assertSessionHasErrors('email');
    }

    public function test_verified_parent_receives_a_single_use_password_link_valid_for_24_hours(): void
    {
        Mail::fake();
        [, $club] = $this->directorAndClub('Recovery');
        $link = ClubParentEnrollmentLink::create([
            'club_id' => $club->id,
            'token' => str_repeat('r', 64),
        ]);
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'status' => 'active',
            'secure_enrollment_link_id' => $link->id,
            'parent_activation_method' => 'email',
        ]);

        $this->assertSame(1440, config('auth.passwords.parents.expire'));
        $this->post(route('password.email'), ['email' => $parent->email])
            ->assertSessionHasNoErrors();

        $resetUrl = null;
        Mail::assertSent(ParentPasswordResetMail::class, function (ParentPasswordResetMail $mail) use (&$resetUrl, $parent) {
            $resetUrl = $mail->actionUrl;

            return $mail->hasTo($parent->email);
        });
        $this->assertNotNull($resetUrl);
        $parts = parse_url($resetUrl);
        parse_str($parts['query'] ?? '', $query);
        $token = basename($parts['path']);

        $payload = [
            'token' => $token,
            'email' => $query['email'] ?? $parent->email,
            'password' => 'new-password123',
            'password_confirmation' => 'new-password123',
        ];
        $this->post(route('password.store'), $payload)
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('login'));
        $this->assertTrue(Hash::check('new-password123', $parent->fresh()->password));

        $this->post(route('password.store'), $payload)->assertSessionHasErrors('email');
    }

    public function test_traditional_parent_can_use_the_dashboard_but_must_verify_email_for_password_features(): void
    {
        Mail::fake();
        [, $club] = $this->directorAndClub('Traditional');
        $parent = User::factory()->unverified()->create([
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $club->church_id,
            'status' => 'active',
            'secure_enrollment_link_id' => null,
            'parent_activation_method' => null,
            'enrollment_confirmed_at' => null,
        ]);
        $parent->clubs()->attach($club->id, ['status' => 'active']);

        $this->assertTrue($parent->canAccessParentPortal());
        $this->assertFalse($parent->canSelfServiceCredentials());

        $this->actingAs($parent)
            ->get(route('parent.dashboard'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Dashboard')
                ->where('auth_user.email_verified_at', null)
                ->where('auth.user.password_self_service_enabled', false));

        auth()->logout();
        $this->post(route('password.email'), ['email' => $parent->email])
            ->assertSessionHasErrors('email');

        $verificationUrl = null;
        $this->actingAs($parent)
            ->post(route('verification.send'))
            ->assertSessionHas('status', 'verification-link-sent');
        Mail::assertSent(ParentEmailVerificationMail::class, function (ParentEmailVerificationMail $mail) use (&$verificationUrl, $parent) {
            $verificationUrl = $mail->actionUrl;

            return $mail->hasTo($parent->email);
        });

        $this->get($verificationUrl)->assertRedirect(route('parent.dashboard'));
        $parent->refresh();
        $this->assertNotNull($parent->email_verified_at);
        $this->assertSame('email', $parent->parent_activation_method);
        $this->assertNull($parent->enrollment_confirmed_at, 'Email verification must not replace traditional director approval.');
        $this->assertTrue($parent->canSelfServiceCredentials());
        $this->actingAs($parent)
            ->get(route('profile.edit'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Parent/Profile')
                ->where('auth_user.id', $parent->id)
                ->where('account_church_name', $club->church->church_name));

        Mail::fake();
        auth()->logout();
        $this->post(route('password.email'), ['email' => $parent->email])
            ->assertSessionHasNoErrors();
        Mail::assertSent(ParentPasswordResetMail::class);
    }

    public function test_pathfinder_and_tlt_parent_enrollment_stores_and_exports_a_drawn_signature(): void
    {
        Storage::fake('public');
        [, $club] = $this->directorAndClub('Pathfinder', 'pathfinders');
        $parent = User::factory()->create([
            'profile_type' => 'parent',
            'club_id' => $club->id,
            'church_id' => $club->church_id,
            'church_name' => $club->church_name,
            'status' => 'active',
        ]);

        $this->actingAs($parent)->post(route('parent.apply.submit'), [
            'club_id' => $club->id,
            'applicant_name' => 'Pathfinder Child',
            'birthdate' => '2013-01-01',
            'father_guardian_name' => 'Pathfinder Parent',
            'father_guardian_email' => $parent->email,
            'father_guardian_phone' => '555-111-2222',
            'signature_type' => 'drawn',
            'signature_data' => $this->signaturePngDataUri(),
        ])->assertRedirect(route('parent.dashboard'));

        $details = MemberPathfinder::query()->where('applicant_name', 'Pathfinder Child')->firstOrFail();
        $this->assertSame('drawn', $details->signature_type);
        $this->assertSame('Pathfinder Parent', $details->parent_guardian_signature);
        $this->assertNotNull($details->signed_at);
        Storage::disk('public')->assertExists($details->signature_path);

        $html = view('pdf.pathfinder_application', [
            'member' => $details,
            'club' => $club,
            'generatedAt' => now()->toDateTimeString(),
            'clubLogoDataUri' => null,
        ])->render();
        $this->assertStringContainsString('data:image/png;base64,', $html);

        $pdfPath = app(DocumentExportService::class)->generatePathfinderPdf(
            $details,
            Storage::disk('public')->path('exports')
        );
        $this->assertFileExists($pdfPath);
        $this->assertStringStartsWith('%PDF', file_get_contents($pdfPath));
    }

    private function directorAndClub(string $prefix = 'Secure', string $clubType = 'adventurers'): array
    {
        $church = Church::create([
            'church_name' => "{$prefix} Church",
            'email' => strtolower($prefix) . '@example.com',
        ]);
        $director = User::factory()->create([
            'profile_type' => 'club_director',
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'status' => 'active',
        ]);
        $club = Club::create([
            'user_id' => $director->id,
            'club_name' => "{$prefix} Club",
            'church_id' => $church->id,
            'church_name' => $church->church_name,
            'director_name' => $director->name,
            'creation_date' => now()->toDateString(),
            'club_type' => $clubType,
            'status' => 'active',
        ]);
        $director->clubs()->attach($club->id, ['status' => 'active']);

        return [$director, $club];
    }

    private function signaturePngDataUri(): string
    {
        $image = imagecreatetruecolor(600, 180);
        $white = imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 15, 23, 42);
        imagefill($image, 0, 0, $white);
        imagesetthickness($image, 7);
        imageline($image, 35, 125, 125, 45, $ink);
        imageline($image, 125, 45, 190, 130, $ink);
        imageline($image, 190, 130, 300, 60, $ink);
        imageline($image, 300, 60, 555, 65, $ink);

        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);

        return 'data:image/png;base64,'.base64_encode($png);
    }
}
