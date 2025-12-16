<?php

namespace App\Http\Controllers;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StaffApprovalController extends Controller
{
    public function approve(Request $request, Staff $staff)
    {
        $this->authorizeForUser($request->user(), 'update', $staff);

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
        $this->authorizeForUser($request->user(), 'update', $staff);

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
