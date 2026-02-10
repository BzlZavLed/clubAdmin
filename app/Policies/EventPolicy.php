<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

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
        return in_array($user->profile_type, ['club_director', 'club_personal'], true);
    }

    protected function belongsToClub(User $user, int $clubId): bool
    {
        if ($user->club_id && (int) $user->club_id === (int) $clubId) {
            return true;
        }

        return $user->clubs()->where('clubs.id', $clubId)->exists();
    }
}
