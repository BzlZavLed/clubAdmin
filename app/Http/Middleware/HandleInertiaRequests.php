<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;
use App\Models\Staff;
use App\Models\ClubClass;
use App\Models\Club;
use App\Models\Church;
use App\Models\District;
use App\Models\Association;
use App\Models\Union;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request)
    {
        $user = $request->user()?->load(['church', 'clubs', 'staff.classes', 'staff.club', 'staff.assignedCarpetaClassActivation.unionClassCatalog']);
        $staffRecord = $user?->staff;
        $assignedClassId = null;
        $assignedClassName = null;
        $assignedClasses = collect();

        if ($staffRecord) {
            $assignedClassId = $staffRecord->assigned_class ?: $staffRecord->classes?->first()?->id;
            if ($assignedClassId) {
                $assignedClass = ClubClass::query()
                    ->where('id', $assignedClassId)
                    ->first(['id', 'class_name']);
                $assignedClassName = $assignedClass?->class_name ?: $staffRecord->classes?->first()?->class_name;
            } elseif ($staffRecord->assignedCarpetaClassActivation) {
                $assignedClassName = $staffRecord->assignedCarpetaClassActivation->unionClassCatalog?->name;
            } else {
                $assignedClassName = $staffRecord->classes?->first()?->class_name;
            }

            $assignedClasses = $staffRecord->classes?->pluck('class_name') ?? collect();
            if ($assignedClassName && !$assignedClasses->contains($assignedClassName)) {
                $assignedClasses->prepend($assignedClassName);
            }
        }

        if ($user) {
            $request->session()->put('assigned_class_id', $assignedClassId);
            $request->session()->put('assigned_class_name', $assignedClassName);
        }

        $isSuperadmin = $user?->profile_type === 'superadmin';
        $superadminContext = $isSuperadmin ? SuperadminContext::fromSession() : null;
        $availableClubs = $user ? ClubHelper::clubsForUser($user) : collect();
        $availableChurches = $user ? ClubHelper::churchesForUser($user) : collect();
        $scopeSummary = $user ? ClubHelper::scopeSummaryForUser($user) : null;
        $effectiveContextUser = null;
        if ($isSuperadmin && !empty($superadminContext['role']) && $superadminContext['role'] !== 'superadmin') {
            $effectiveScopeId = match ($superadminContext['role']) {
                'union_youth_director' => $superadminContext['union_id'] ?? null,
                'association_youth_director' => $superadminContext['association_id'] ?? null,
                'district_pastor', 'district_secretary' => $superadminContext['district_id'] ?? null,
                'club_director' => $superadminContext['club_id'] ?? null,
                default => null,
            };

            if ($effectiveScopeId) {
                $effectiveContextUser = (object) [
                    'profile_type' => $superadminContext['role'],
                    'role_key' => $superadminContext['role'],
                    'scope_id' => $effectiveScopeId,
                ];
            }
        }

        $hierarchyWidget = $effectiveContextUser
            ? ClubHelper::hierarchyWidgetDataForUser($effectiveContextUser)
            : ($user ? ClubHelper::hierarchyWidgetDataForUser($user) : null);
        $activeClub = $user ? ClubHelper::activeClubForUser($user) : null;
        $effectiveClubId = $activeClub?->id ?: ($isSuperadmin ? $request->session()->get('superadmin_context.club_id') : ($request->session()->get('club_context.club_id') ?: $user?->club_id));
        $effectiveChurchId = $activeClub?->church_id ?: ($isSuperadmin
            ? $request->session()->get('superadmin_context.church_id')
            : ($request->session()->get('club_context.church_id') ?: $user?->church_id));
        $effectiveRole = $isSuperadmin ? ($superadminContext['role'] ?? 'superadmin') : ($user?->role_key ?: $user?->profile_type);
        $effectiveScopeSummary = $isSuperadmin
            ? [
                'role' => $effectiveRole,
                'name' => $superadminContext['club_name']
                    ?? $superadminContext['district_name']
                    ?? $superadminContext['association_name']
                    ?? $superadminContext['union_name']
                    ?? 'Superadmin',
                'evaluation_system' => $superadminContext['evaluation_system'] ?? 'honors',
                'union_name' => $superadminContext['union_name'] ?? null,
                'association_name' => $superadminContext['association_name'] ?? null,
                'district_name' => $superadminContext['district_name'] ?? null,
                'church_name' => $superadminContext['church_name'] ?? null,
                'club_name' => $superadminContext['club_name'] ?? null,
            ]
            : $scopeSummary;
        $contextUnions = $isSuperadmin
            ? Union::query()->where('status', '!=', 'deleted')->orderBy('name')->get(['id', 'name'])
            : collect();
        $contextAssociations = $isSuperadmin
            ? Association::query()->where('status', '!=', 'deleted')->orderBy('name')->get(['id', 'union_id', 'name'])
            : collect();
        $contextDistricts = $isSuperadmin
            ? District::query()->where('status', '!=', 'deleted')->orderBy('name')->get(['id', 'association_id', 'name'])
            : collect();
        $contextChurches = $isSuperadmin
            ? Church::query()->orderBy('church_name')->get(['id', 'district_id', 'church_name'])
            : collect();

        $effectiveChurch = $effectiveChurchId
            ? Church::query()->where('id', $effectiveChurchId)->first(['id', 'church_name'])
            : null;
        $effectiveClub = $activeClub ?: ($effectiveClubId
            ? Club::query()->where('id', $effectiveClubId)->first(['id', 'club_name', 'club_type', 'evaluation_system', 'church_id', 'church_name'])
            : null);
        $primaryDirectorClub = $user && in_array($user->profile_type, ['club_director', 'superadmin'], true)
            ? Club::query()
                ->where('user_id', $user->id)
                ->orderBy('club_name')
                ->first(['id', 'club_name', 'club_type', 'evaluation_system', 'church_id', 'church_name'])
            : null;

        return array_merge(parent::share($request), [
            'auth' => [
                'user' => fn() => $user
                    ? [
                        'id' => $user->id,
                        'name' => $user->name,
                        'profile_type' => $user->profile_type,
                        'role_key' => $user->role_key,
                        'scope_type' => $user->scope_type,
                        'scope_id' => $user->scope_id,
                        'sub_role' => $user->sub_role,
                        'church_id' => $effectiveChurchId ?: $user->church_id,
                        'church_name' => $effectiveChurch?->church_name ?: $user->church_name,
                        'club_id' => $effectiveClubId ?: $user->club_id,
                        'club_name' => $effectiveClub?->club_name ?: null,
                        'club_type' => $effectiveClub?->club_type ?: null,
                        'pastor_name' => optional($user->church)->pastor_name,
                        'conference_name' => optional($user->church)->conference,
                        'assigned_classes' => $assignedClasses->values(),
                        'assigned_class_id' => $assignedClassId,
                        'assigned_class_name' => $assignedClassName,
                        'clubs' => $availableClubs->map(fn($club) => [
                            'id' => $club->id,
                            'club_name' => $club->club_name,
                            'club_type' => $club->club_type,
                            'church_id' => $club->church_id,
                            'church_name' => $club->church_name,
                            'district_id' => $club->district_id,
                            'director_name' => $club->director_name,
                            'evaluation_system' => $club->evaluation_system,
                            'status' => $club->status,
                            'enrollment_payment_amount' => $club->enrollment_payment_amount,
                            'insurance_payment_amount' => $club->district?->association?->insurance_payment_amount,
                        ]),
                        'churches' => $availableChurches->map(fn($church) => [
                            'id' => $church->id,
                            'district_id' => $church->district_id,
                            'church_name' => $church->church_name,
                            'email' => $church->email,
                        ]),
                        'accessible_club_count' => $availableClubs->count(),
                        'accessible_church_count' => $availableChurches->count(),
                        'scope_summary' => $scopeSummary,
                        'effective_scope_summary' => $effectiveScopeSummary,
                        'hierarchy_widget' => $hierarchyWidget,
                        'effective_role' => $effectiveRole,
                        'staff' => $staffRecord ? [
                            'id' => $staffRecord->id,
                            'club_id' => $staffRecord->club_id,
                            'club_name' => optional($staffRecord->club)->club_name,
                            'assigned_class_id' => $assignedClassId,
                            'assigned_class_name' => $assignedClassName,
                            'status' => $staffRecord->status,
                        ] : null,
                    ]
                    : null,
                'active_club' => fn() => $effectiveClub ? [
                    'id' => $effectiveClub->id,
                    'club_name' => $effectiveClub->club_name,
                    'club_type' => $effectiveClub->club_type,
                    'evaluation_system' => $effectiveClub->evaluation_system,
                    'church_id' => $effectiveClub->church_id,
                    'church_name' => $effectiveClub->church_name,
                ] : null,
                'active_church' => fn() => $effectiveChurch ? [
                    'id' => $effectiveChurch->id,
                    'church_name' => $effectiveChurch->church_name,
                ] : null,
                'primary_director_club' => fn() => $primaryDirectorClub ? [
                    'id' => $primaryDirectorClub->id,
                    'club_name' => $primaryDirectorClub->club_name,
                    'club_type' => $primaryDirectorClub->club_type,
                    'church_id' => $primaryDirectorClub->church_id,
                    'church_name' => $primaryDirectorClub->church_name,
                ] : null,
                'available_clubs' => fn() => $availableClubs->map(fn($club) => [
                    'id' => $club->id,
                    'club_name' => $club->club_name,
                    'club_type' => $club->club_type,
                    'evaluation_system' => $club->evaluation_system,
                    'church_id' => $club->church_id,
                    'church_name' => $club->church_name,
                    'district_id' => $club->district_id,
                    'director_name' => $club->director_name,
                    'status' => $club->status,
                    'enrollment_payment_amount' => $club->enrollment_payment_amount,
                    'insurance_payment_amount' => $club->district?->association?->insurance_payment_amount,
                ])->values(),
                'club_context' => fn() => !$isSuperadmin ? [
                    'church_id' => $effectiveChurchId ? (int) $effectiveChurchId : null,
                    'church_name' => $effectiveChurch?->church_name,
                    'club_id' => $effectiveClubId ? (int) $effectiveClubId : null,
                    'club_name' => $effectiveClub?->club_name,
                ] : null,
                'is_in_club' => fn() => session('is_in_club', false),
                'user_club_ids' => fn() => session('user_club_ids', []),
                'superadmin_context' => fn() => $isSuperadmin ? [
                    'role' => $superadminContext['role'] ?? 'superadmin',
                    'dashboard_url' => $superadminContext['dashboard_url'] ?? '/super-admin/dashboard',
                    'evaluation_system' => $superadminContext['evaluation_system'] ?? 'honors',
                    'union_id' => $superadminContext['union_id'] ?? null,
                    'union_name' => $superadminContext['union_name'] ?? null,
                    'association_id' => $superadminContext['association_id'] ?? null,
                    'association_name' => $superadminContext['association_name'] ?? null,
                    'district_id' => $superadminContext['district_id'] ?? null,
                    'district_name' => $superadminContext['district_name'] ?? null,
                    'church_id' => $effectiveChurchId ? (int) $effectiveChurchId : null,
                    'church_name' => $effectiveChurch?->church_name,
                    'club_id' => $effectiveClubId ? (int) $effectiveClubId : null,
                    'club_name' => $effectiveClub?->club_name,
                    'available_clubs' => $availableClubs->map(fn($club) => [
                        'id' => $club->id,
                        'club_name' => $club->club_name,
                        'club_type' => $club->club_type,
                        'evaluation_system' => $club->evaluation_system,
                        'church_id' => $club->church_id,
                        'church_name' => $club->church_name,
                        'district_id' => $club->district_id,
                        'director_name' => $club->director_name,
                        'status' => $club->status,
                        'enrollment_payment_amount' => $club->enrollment_payment_amount,
                        'insurance_payment_amount' => $club->district?->association?->insurance_payment_amount,
                    ])->values(),
                    'available_unions' => $contextUnions->map(fn($union) => [
                        'id' => $union->id,
                        'name' => $union->name,
                    ])->values(),
                    'available_associations' => $contextAssociations->map(fn($association) => [
                        'id' => $association->id,
                        'union_id' => $association->union_id,
                        'name' => $association->name,
                    ])->values(),
                    'available_districts' => $contextDistricts->map(fn($district) => [
                        'id' => $district->id,
                        'association_id' => $district->association_id,
                        'name' => $district->name,
                    ])->values(),
                    'available_churches' => $contextChurches->map(fn($church) => [
                        'id' => $church->id,
                        'district_id' => $church->district_id,
                        'church_name' => $church->church_name,
                    ])->values(),
                ] : null,
                'effective_role' => fn() => $effectiveRole,
                'effective_scope_summary' => fn() => $effectiveScopeSummary,
            ],
        ]);
    }
}
