<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\ClubHelper;

class StaffApprovalController extends Controller
{
    protected function authorizeStaff(Request $request, Staff $staff): void
    {
        $user = $request->user();
        if (!$user || $user->profile_type !== 'club_director') {
            abort(403);
        }
        $clubIds = ClubHelper::clubIdsForUser($user);
        if (!$clubIds->contains($staff->club_id)) {
            abort(403);
        }
    }

    public function approve(Request $request, Staff $staff)
    {
        $this->authorizeStaff($request, $staff);

        DB::transaction(function () use ($staff) {
            $staff->update(['status' => 'active']);
            if ($staff->user_id) {
                User::where('id', $staff->user_id)->update(['status' => 'active']);
            }
            DB::table('club_user')
                ->where('user_id', $staff->user_id)
                ->where('club_id', $staff->club_id)
                ->update(['status' => 'active']);
        });

        return response()->json(['message' => 'Staff approved.']);
    }

    public function reject(Request $request, Staff $staff)
    {
        $this->authorizeStaff($request, $staff);

        DB::transaction(function () use ($staff) {
            $staff->update(['status' => 'rejected']);
            if ($staff->user_id) {
                User::where('id', $staff->user_id)->update(['status' => 'rejected']);
            }
            DB::table('club_user')
                ->where('user_id', $staff->user_id)
                ->where('club_id', $staff->club_id)
                ->update(['status' => 'rejected']);
        });

        return response()->json(['message' => 'Staff rejected.']);
    }
}
