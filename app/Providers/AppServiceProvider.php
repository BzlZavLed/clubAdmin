<?php

namespace App\Providers;

use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\Failed;
use App\Models\AuditLog;
use App\Observers\AuditLogObserver;
use App\Models\User;
use App\Models\Member;
use App\Models\MemberAdventurer;
use App\Models\TempMemberPathfinder;
use App\Models\Staff;
use App\Models\TempStaffPathfinder;
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
use App\Policies\EventPolicy;

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
            TempMemberPathfinder::class,
            Staff::class,
            StaffAdventurer::class,
            TempStaffPathfinder::class,
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
        ];

        foreach ($auditableModels as $modelClass) {
            $modelClass::observe(AuditLogObserver::class);
        }

        Event::listen(Login::class, function (Login $event) {
            AuditLog::create([
                'actor_id' => $event->user?->id,
                'action' => 'login',
                'entity_type' => 'User',
                'entity_id' => $event->user?->id,
                'entity_label' => $event->user?->email,
                'metadata' => ['guard' => $event->guard],
                'route' => request()?->route()?->getName(),
                'method' => request()?->method(),
                'url' => request()?->fullUrl(),
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });

        Event::listen(Logout::class, function (Logout $event) {
            AuditLog::create([
                'actor_id' => $event->user?->id,
                'action' => 'logout',
                'entity_type' => 'User',
                'entity_id' => $event->user?->id,
                'entity_label' => $event->user?->email,
                'metadata' => ['guard' => $event->guard],
                'route' => request()?->route()?->getName(),
                'method' => request()?->method(),
                'url' => request()?->fullUrl(),
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });

        Event::listen(Failed::class, function (Failed $event) {
            AuditLog::create([
                'actor_id' => $event->user?->id,
                'action' => 'failed_login',
                'entity_type' => 'User',
                'entity_id' => $event->user?->id,
                'entity_label' => $event->credentials['email'] ?? null,
                'metadata' => [
                    'guard' => $event->guard,
                    'credentials' => array_keys($event->credentials ?? []),
                ],
                'route' => request()?->route()?->getName(),
                'method' => request()?->method(),
                'url' => request()?->fullUrl(),
                'ip' => request()?->ip(),
                'user_agent' => request()?->userAgent(),
            ]);
        });
    }
}
