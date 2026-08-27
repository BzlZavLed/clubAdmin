<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use App\Support\AuditRecorder;
use App\Observers\AuditLogObserver;
use App\Models\User;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\MemberPathfinder;
use App\Models\MemberPathfinderInsuranceCard;
use App\Models\Staff;
use App\Models\StaffPathfinder;
use App\Models\Church;
use App\Models\ChurchInviteCode;
use App\Models\Club;
use App\Models\ClubClass;
use App\Models\ParentMember;
use App\Models\SubRole;
use App\Models\ClubIntegrationConfig;
use App\Models\Workplan;
use App\Models\WorkplanEvent;
use App\Models\WorkplanRule;
use App\Models\ClassPlan;
use App\Models\ClassMemberAdventurer;
use App\Models\ClassMemberPathfinder;
use App\Models\RepAssistanceAdv;
use App\Models\RepAssistanceAdvMerit;
use App\Models\ScopeType;
use App\Models\StaffAdventurer;
use App\Models\Event as ClubEvent;
use App\Models\EventPlan;
use App\Models\EventTask;
use App\Models\EventBudgetItem;
use App\Models\EventParticipant;
use App\Models\EventDocument;
use App\Models\Account;
use App\Models\Expense;
use App\Models\ParentChildLinkRequest;
use App\Models\ParentPaymentSubmission;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentConcept;
use App\Models\PaymentConceptScope;
use App\Models\PaymentReceipt;
use App\Models\TreasuryMovement;
use App\Models\ClubParentEnrollmentLink;
use App\Models\LocationSharingConsent;
use App\Models\AdventurerYearlyApplicationSignature;
use App\Models\PathfinderAnnualApplicationSignature;
use App\Models\InvestitureRequest;
use App\Policies\EventPolicy;
use App\Models\MailDeliveryLog;
use Illuminate\Mail\Events\MessageSent;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Gate::policy(ClubEvent::class, EventPolicy::class);

        $auditableModels = [
            User::class,
            Member::class,
            MemberAdventurer::class,
            MemberPathfinder::class,
            MemberPathfinderInsuranceCard::class,
            Staff::class,
            StaffAdventurer::class,
            StaffPathfinder::class,
            Church::class,
            ChurchInviteCode::class,
            Club::class,
            ClubClass::class,
            ParentMember::class,
            SubRole::class,
            ClubIntegrationConfig::class,
            Workplan::class,
            WorkplanEvent::class,
            WorkplanRule::class,
            ClassPlan::class,
            ClassMemberAdventurer::class,
            ClassMemberPathfinder::class,
            RepAssistanceAdv::class,
            RepAssistanceAdvMerit::class,
            ScopeType::class,
            ClubEvent::class,
            EventPlan::class,
            EventTask::class,
            EventBudgetItem::class,
            EventParticipant::class,
            EventDocument::class,
            Account::class,
            Expense::class,
            ParentChildLinkRequest::class,
            ParentPaymentSubmission::class,
            Payment::class,
            PaymentAllocation::class,
            PaymentConcept::class,
            PaymentConceptScope::class,
            PaymentReceipt::class,
            TreasuryMovement::class,
            ClubParentEnrollmentLink::class,
            LocationSharingConsent::class,
            AdventurerYearlyApplicationSignature::class,
            PathfinderAnnualApplicationSignature::class,
            InvestitureRequest::class,
        ];

        foreach ($auditableModels as $modelClass) {
            $modelClass::observe(AuditLogObserver::class);
        }

        Event::listen(Login::class, function (Login $event) {
            AuditRecorder::event('login_succeeded', [
                'actor_id' => $event->user?->id,
                'entity_type' => 'User',
                'entity_id' => $event->user?->id,
                'entity_label' => $event->user?->email,
                'metadata' => ['guard' => $event->guard],
            ]);
        });

        Event::listen(Logout::class, function (Logout $event) {
            AuditRecorder::event('logout', [
                'actor_id' => $event->user?->id,
                'entity_type' => 'User',
                'entity_id' => $event->user?->id,
                'entity_label' => $event->user?->email,
                'metadata' => ['guard' => $event->guard],
            ]);
        });

        Event::listen(Failed::class, function (Failed $event) {
            $email = $event->credentials['email'] ?? null;
            AuditRecorder::event('login_failed', [
                'actor_id' => $event->user?->id,
                'entity_type' => 'User',
                'entity_id' => $event->user?->id,
                'entity_label' => AuditRecorder::maskedEmail($email),
                'metadata' => [
                    'guard' => $event->guard,
                    'identifier_hash' => AuditRecorder::identifierHash($email),
                ],
            ]);
        });

        Event::listen(Lockout::class, function (Lockout $event) {
            $email = $event->request->input('email');
            AuditRecorder::event('login_locked_out', [
                'entity_type' => 'User',
                'entity_label' => AuditRecorder::maskedEmail($email),
                'metadata' => ['identifier_hash' => AuditRecorder::identifierHash($email)],
            ], $event->request);
        });

        Event::listen(PasswordReset::class, fn (PasswordReset $event) => AuditRecorder::event('password_reset', [
            'actor_id' => $event->user->id,
            'entity_type' => 'User',
            'entity_id' => $event->user->id,
            'entity_label' => $event->user->email,
        ]));

        Event::listen(Verified::class, fn (Verified $event) => AuditRecorder::event('email_verified', [
            'actor_id' => $event->user->id,
            'entity_type' => 'User',
            'entity_id' => $event->user->id,
            'entity_label' => $event->user->email,
        ]));

        Event::listen(MessageSent::class, function (MessageSent $event) {
            $headers = $event->message->getHeaders();
            $emailUidHeader = $headers->get('X-Club-Portal-Mail-ID');
            $resendIdHeader = $headers->get('X-Resend-Email-ID');

            if (!$emailUidHeader || !$resendIdHeader) {
                return;
            }

            $mailLog = MailDeliveryLog::query()
                ->where('email_uid', $emailUidHeader->getBodyAsString())
                ->latest()
                ->first();

            if (!$mailLog) {
                return;
            }

            $metadata = $mailLog->metadata ?: [];
            $metadata['provider'] = 'resend';
            $metadata['provider_message_id'] = $resendIdHeader->getBodyAsString();

            $mailLog->forceFill([
                'provider' => 'resend',
                'provider_message_id' => $resendIdHeader->getBodyAsString(),
                'last_provider_event_at' => now(),
                'metadata' => $metadata,
            ])->save();
        });
    }
}
