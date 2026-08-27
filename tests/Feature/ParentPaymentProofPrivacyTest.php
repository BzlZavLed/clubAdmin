<?php

namespace Tests\Feature;

use App\Http\Controllers\ParentPaymentController;
use App\Mail\ParentPaymentSubmissionMail;
use App\Models\BankInfo;
use App\Models\Church;
use App\Models\Club;
use App\Models\Member;
use App\Models\ParentPaymentSubmission;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\User;
use App\Services\Mail\MailerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ParentPaymentProofPrivacyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_payment_proof_requires_authentication_and_record_level_authorization(): void
    {
        [$parent, $director, $submission] = $this->submissionContext('Owner');
        [$otherParent, $otherDirector] = $this->submissionContext('Other');

        Storage::disk('local')->put($submission->receipt_image_path, 'private-proof');
        $url = route('parent-payment-submissions.proof', $submission);

        $this->get($url)->assertRedirect(route('login'));

        $this->actingAs($parent)
            ->get($url)
            ->assertOk()
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->actingAs($director)->get($url)->assertOk();
        $this->actingAs($otherParent)->get($url)->assertForbidden();
        $this->actingAs($otherDirector)->get($url)->assertForbidden();
    }

    public function test_mobile_payment_proof_requires_the_owning_parent_token(): void
    {
        [$parent, , $submission] = $this->submissionContext('Mobile');
        [$otherParent] = $this->submissionContext('Mobile Other');
        Storage::disk('local')->put($submission->receipt_image_path, 'mobile-private-proof');
        $url = route('api.mobile.parent.payment-proofs.show', $submission);

        $this->getJson($url)->assertUnauthorized();

        Sanctum::actingAs($parent, ['mobile']);
        $this->get($url)->assertOk();

        Sanctum::actingAs($otherParent, ['mobile']);
        $this->getJson($url)->assertForbidden();
    }

    public function test_parent_payload_contains_only_the_protected_route(): void
    {
        [$parent, , $submission] = $this->submissionContext('Payload');
        $payload = app(ParentPaymentController::class)
            ->transferSubmissionsForParent($parent)
            ->firstWhere('id', $submission->id);
        $mobilePayload = app(ParentPaymentController::class)
            ->transferSubmissionsForParent($parent, mobile: true)
            ->firstWhere('id', $submission->id);

        $this->assertSame(route('parent-payment-submissions.proof', $submission), $payload['receipt_image_url']);
        $this->assertSame(route('api.mobile.parent.payment-proofs.show', $submission), $mobilePayload['receipt_image_url']);
        $this->assertStringNotContainsString('/storage/', $payload['receipt_image_url']);
    }

    public function test_new_web_upload_is_written_only_to_private_storage(): void
    {
        [$parent, $director, , $club, $member] = $this->submissionContext('Upload');
        $concept = PaymentConcept::query()->create([
            'concept' => 'Private upload test',
            'type' => 'mandatory',
            'pay_to' => 'club_budget',
            'created_by' => $director->id,
            'status' => 'active',
            'club_id' => $club->id,
            'amount' => 100,
            'reusable' => false,
        ]);
        PaymentConceptScope::query()->create([
            'payment_concept_id' => $concept->id,
            'scope_type' => 'club_wide',
            'club_id' => $club->id,
        ]);
        BankInfo::query()->create([
            'bankable_type' => Club::class,
            'bankable_id' => $club->id,
            'pay_to' => 'club_budget',
            'bank_name' => 'Private Bank',
            'account_holder' => $club->club_name,
            'is_active' => true,
            'accepts_parent_deposits' => true,
            'requires_receipt_upload' => true,
        ]);

        $this->actingAs($parent)
            ->post(route('parent.payments.transfers.store'), [
                'payment_concept_id' => $concept->id,
                'member_id' => $member->id,
                'amount' => 10,
                'payment_date' => now()->toDateString(),
                'receipt_image' => UploadedFile::fake()->image('bank-proof.png'),
            ])
            ->assertRedirect(route('parent.payments.index'));

        $uploaded = ParentPaymentSubmission::query()
            ->where('payment_concept_id', $concept->id)
            ->firstOrFail();
        $this->assertSame('local', $uploaded->receipt_image_disk);
        Storage::disk('local')->assertExists($uploaded->receipt_image_path);
        Storage::disk('public')->assertMissing($uploaded->receipt_image_path);
    }

    public function test_club_email_attaches_the_exact_private_proof_image_bytes(): void
    {
        [, , $submission, $club] = $this->submissionContext('Email attachment');
        $proofBytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true
        );
        Storage::disk('local')->put($submission->receipt_image_path, $proofBytes);
        $club->forceFill(['club_email' => 'payments@example.test'])->save();
        Mail::fake();

        app(MailerService::class)->sendParentPaymentSubmission($submission->fresh());

        Mail::assertSent(ParentPaymentSubmissionMail::class, function (ParentPaymentSubmissionMail $mail) use ($proofBytes, $submission) {
            $mail->assertTo('payments@example.test')
                ->assertHasAttachedData($proofBytes, basename($submission->receipt_image_path), ['mime' => 'image/png'])
                ->assertSeeInHtml('El comprobante esta adjunto');

            return true;
        });
        $this->assertSame('sent', $submission->fresh()->club_receipt_email_status);
    }

    public function test_legacy_public_proofs_can_be_moved_to_private_storage_idempotently(): void
    {
        [, , $submission] = $this->submissionContext('Legacy', disk: null);
        Storage::disk('public')->put($submission->receipt_image_path, 'legacy-proof');

        $this->artisan('parent-payments:privatize-proofs')->assertSuccessful();

        Storage::disk('local')->assertExists($submission->receipt_image_path);
        Storage::disk('public')->assertMissing($submission->receipt_image_path);
        $this->assertSame('local', $submission->fresh()->receipt_image_disk);

        $this->artisan('parent-payments:privatize-proofs')->assertSuccessful();
        Storage::disk('local')->assertExists($submission->receipt_image_path);
    }

    private function submissionContext(string $prefix, ?string $disk = 'local'): array
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
        $parent = User::factory()->create([
            'name' => "$prefix Parent",
            'email' => str($prefix)->slug().'-parent@example.test',
            'profile_type' => 'parent',
            'role_key' => 'parent',
            'club_id' => $club->id,
            'church_id' => $church->id,
            'status' => 'active',
        ]);
        $member = Member::query()->create([
            'type' => 'adventurers',
            'id_data' => 999000 + $club->id,
            'club_id' => $club->id,
            'parent_id' => $parent->id,
            'status' => 'active',
        ]);
        $submission = ParentPaymentSubmission::query()->create([
            'club_id' => $club->id,
            'member_id' => $member->id,
            'parent_user_id' => $parent->id,
            'amount' => 25,
            'payment_date' => now()->toDateString(),
            'payment_type' => 'transfer',
            'receipt_image_path' => 'parent-payment-proofs/'.str($prefix)->slug().'.png',
            'receipt_image_disk' => $disk,
            'status' => 'pending',
        ]);

        return [$parent, $director, $submission, $club, $member];
    }
}
