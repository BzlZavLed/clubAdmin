<?php

namespace App\Http\Controllers;

use App\Models\ChurchInviteCode;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChurchInviteCodeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if ($user->profile_type !== 'club_director') {
            abort(403);
        }
        $churchId = $user->church_id;
        if (!$churchId) {
            abort(422, 'Director is not linked to a church.');
        }

        $code = ChurchInviteCode::firstOrCreate(
            ['church_id' => $churchId],
            ['code' => Str::upper(Str::random(10)), 'status' => 'active']
        );

        return response()->json([
            'code' => $code->code,
            'uses_left' => $code->uses_left,
            'expires_at' => $code->expires_at,
            'status' => $code->status,
        ]);
    }

    public function regenerate(Request $request)
    {
        $user = $request->user();
        if ($user->profile_type !== 'club_director') {
            abort(403);
        }
        $churchId = $user->church_id;
        if (!$churchId) {
            abort(422, 'Director is not linked to a church.');
        }

        $code = ChurchInviteCode::updateOrCreate(
            ['church_id' => $churchId],
            [
                'code' => Str::upper(Str::random(10)),
                'status' => 'active',
                'uses_left' => null,
                'expires_at' => null,
                'created_by' => $user->id,
            ]
        );

        return response()->json([
            'message' => 'Invite code regenerated',
            'code' => $code->code,
            'uses_left' => $code->uses_left,
            'expires_at' => $code->expires_at,
            'status' => $code->status,
        ]);
    }
}
