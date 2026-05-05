<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Association;
use App\Models\Church;
use App\Models\Club;
use App\Models\District;
use App\Models\Union;
use App\Models\User;
use App\Support\ClubHelper;
use App\Support\SuperadminContext;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->canUseEventPlanner($user);
    }

    public function view(User $user, Event $event): bool
    {
        return $this->canUseEventPlanner($user)
            && (
                $this->canAccessScope($user, (string) ($event->scope_type ?: 'club'), (int) ($event->scope_id ?: $event->club_id))
                || $this->canAccessTargetedEvent($user, $event)
            );
    }

    public function create(User $user): bool
    {
        return $this->canUseEventPlanner($user);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->canUseEventPlanner($user)
            && $this->canAccessScope($user, (string) ($event->scope_type ?: 'club'), (int) ($event->scope_id ?: $event->club_id));
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->canUseEventPlanner($user)
            && $this->canAccessScope($user, (string) ($event->scope_type ?: 'club'), (int) ($event->scope_id ?: $event->club_id));
    }

    protected function canUseEventPlanner(User $user): bool
    {
        return in_array($user->profile_type, [
            'club_director',
            'club_personal',
            'district_pastor',
            'district_secretary',
            'association_youth_director',
            'union_youth_director',
            'superadmin',
        ], true);
    }

    protected function canAccessScope(User $user, string $scopeType, int $scopeId): bool
    {
        $actingRole = $this->effectiveRole($user);
        $actingContext = $this->effectiveContext($user);

        if (($user->profile_type ?? null) === 'superadmin' && $actingRole === 'superadmin') {
            return true;
        }

        return match ($scopeType) {
            'club' => $this->belongsToClub($user, $scopeId, $actingRole, $actingContext),
            'church' => $this->belongsToChurch($user, $scopeId, $actingRole, $actingContext),
            'district' => $this->belongsToDistrict($user, $scopeId, $actingRole, $actingContext),
            'association' => $this->belongsToAssociation($user, $scopeId, $actingRole, $actingContext),
            'union' => $this->belongsToUnion($user, $scopeId, $actingRole, $actingContext),
            default => false,
        };
    }

    protected function belongsToClub(User $user, int $clubId, ?string $role = null, ?array $context = null): bool
    {
        $role = $role ?: $this->effectiveRole($user);
        $context = $context ?: $this->effectiveContext($user);

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            if (($user->profile_type ?? null) === 'superadmin') {
                return (int) ($context['club_id'] ?? 0) === (int) $clubId;
            }

            if ($user->club_id && (int) $user->club_id === (int) $clubId) {
                return true;
            }

            return $user->clubs()->where('clubs.id', $clubId)->exists();
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return Club::query()
                ->whereKey($clubId)
                ->where('district_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['district_id'] ?? 0) : $user->scope_id))
                ->exists();
        }

        if ($role === 'association_youth_director') {
            return Club::query()
                ->whereKey($clubId)
                ->whereHas('district', fn ($query) => $query->where('association_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        if ($role === 'union_youth_director') {
            return Club::query()
                ->whereKey($clubId)
                ->whereHas('district.association', fn ($query) => $query->where('union_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        return false;
    }

    protected function belongsToChurch(User $user, int $churchId, ?string $role = null, ?array $context = null): bool
    {
        $role = $role ?: $this->effectiveRole($user);
        $context = $context ?: $this->effectiveContext($user);

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            if (($user->profile_type ?? null) === 'superadmin') {
                return (int) ($context['church_id'] ?? 0) === (int) $churchId;
            }

            return ClubHelper::churchIdsForUser($user)
                ->map(fn ($id) => (int) $id)
                ->contains($churchId);
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return Church::query()
                ->whereKey($churchId)
                ->where('district_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['district_id'] ?? 0) : $user->scope_id))
                ->exists();
        }

        if ($role === 'association_youth_director') {
            return Church::query()
                ->whereKey($churchId)
                ->whereHas('district', fn ($query) => $query->where('association_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        if ($role === 'union_youth_director') {
            return Church::query()
                ->whereKey($churchId)
                ->whereHas('district.association', fn ($query) => $query->where('union_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        return false;
    }

    protected function belongsToDistrict(User $user, int $districtId, ?string $role = null, ?array $context = null): bool
    {
        $role = $role ?: $this->effectiveRole($user);
        $context = $context ?: $this->effectiveContext($user);

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['district_id'] ?? 0) : $user->scope_id) === $districtId;
        }

        if ($role === 'association_youth_director') {
            return District::query()
                ->whereKey($districtId)
                ->where('association_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id))
                ->exists();
        }

        if ($role === 'union_youth_director') {
            return District::query()
                ->whereKey($districtId)
                ->whereHas('association', fn ($query) => $query->where('union_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        return false;
    }

    protected function belongsToAssociation(User $user, int $associationId, ?string $role = null, ?array $context = null): bool
    {
        $role = $role ?: $this->effectiveRole($user);
        $context = $context ?: $this->effectiveContext($user);

        if ($role === 'association_youth_director') {
            return (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id) === $associationId;
        }

        if ($role === 'union_youth_director') {
            return Association::query()
                ->whereKey($associationId)
                ->where('union_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id))
                ->exists();
        }

        return false;
    }

    protected function belongsToUnion(User $user, int $unionId, ?string $role = null, ?array $context = null): bool
    {
        $role = $role ?: $this->effectiveRole($user);
        $context = $context ?: $this->effectiveContext($user);

        return $role === 'union_youth_director'
            && (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id) === $unionId;
    }

    protected function canAccessTargetedEvent(User $user, Event $event): bool
    {
        $role = $this->effectiveRole($user);
        $context = $this->effectiveContext($user);
        $targetClubIds = $event->targetClubs()->pluck('clubs.id')->map(fn ($id) => (int) $id)->all();

        if (empty($targetClubIds)) {
            return false;
        }

        if (($user->profile_type ?? null) === 'superadmin' && $role === 'superadmin') {
            return true;
        }

        if (in_array($role, ['club_director', 'club_personal'], true)) {
            $userClubIds = ($user->profile_type ?? null) === 'superadmin'
                ? collect([(int) ($context['club_id'] ?? 0)])->filter()->values()->all()
                : ClubHelper::clubIdsForUser($user)->map(fn ($id) => (int) $id)->all();

            return collect($targetClubIds)->intersect($userClubIds)->isNotEmpty();
        }

        if (in_array($role, ['district_pastor', 'district_secretary'], true)) {
            return Club::query()
                ->whereIn('id', $targetClubIds)
                ->where('district_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['district_id'] ?? 0) : $user->scope_id))
                ->exists();
        }

        if ($role === 'association_youth_director') {
            return Club::query()
                ->whereIn('id', $targetClubIds)
                ->whereHas('district', fn ($query) => $query->where('association_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['association_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        if ($role === 'union_youth_director') {
            return Club::query()
                ->whereIn('id', $targetClubIds)
                ->whereHas('district.association', fn ($query) => $query->where('union_id', (int) (($user->profile_type ?? null) === 'superadmin' ? ($context['union_id'] ?? 0) : $user->scope_id)))
                ->exists();
        }

        return false;
    }

    protected function effectiveRole(User $user): string
    {
        if (($user->profile_type ?? null) !== 'superadmin') {
            return ClubHelper::roleKey($user);
        }

        return (string) (SuperadminContext::fromSession()['role'] ?? 'superadmin');
    }

    protected function effectiveContext(User $user): array
    {
        if (($user->profile_type ?? null) !== 'superadmin') {
            return [];
        }

        return SuperadminContext::fromSession();
    }
}
