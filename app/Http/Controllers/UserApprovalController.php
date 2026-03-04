<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class UserApprovalController extends Controller
{
    public function approve(Request $request, User $user)
    {
        $director = $request->user();
        if (!in_array($director->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403);
        }

        if ($director->profile_type === 'superadmin') {
            $user->status = 'active';
            $user->save();
            return response()->json(['message' => 'User approved']);
        }

        // Ensure director shares a club with the user
        $directorClubIds = $director->clubs()->pluck('clubs.id')->toArray();
        $targetClubId = $user->club_id;
        if ($targetClubId && !in_array($targetClubId, $directorClubIds)) {
            abort(403, 'Not allowed to approve this user.');
        }

        $user->status = 'active';
        $user->save();

        return response()->json(['message' => 'User approved']);
    }
}
