<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;
use App\Support\ClubHelper;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isClubStaff($user);
    }

    public function view(User $user, Event $event): bool
    {
        return $this->isClubStaff($user) && $this->belongsToClub($user, $event->club_id);
    }

    public function create(User $user): bool
    {
        return $this->isClubStaff($user);
    }

    public function update(User $user, Event $event): bool
    {
        return $this->isClubStaff($user) && $this->belongsToClub($user, $event->club_id);
    }

    public function delete(User $user, Event $event): bool
    {
        return $this->isClubStaff($user) && $this->belongsToClub($user, $event->club_id);
    }

    protected function isClubStaff(User $user): bool
    {
        return in_array($user->profile_type, ['club_director', 'club_personal', 'superadmin'], true);
    }

    protected function belongsToClub(User $user, int $clubId): bool
    {
        if (($user->profile_type ?? null) === 'superadmin') {
            return ClubHelper::clubIdsForUser($user)
                ->map(fn ($id) => (int) $id)
                ->contains((int) $clubId);
        }

        if ($user->club_id && (int) $user->club_id === (int) $clubId) {
            return true;
        }

        return $user->clubs()->where('clubs.id', $clubId)->exists();
    }
}
