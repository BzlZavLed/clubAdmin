<?php

namespace App\Http\Controllers;

use App\Models\ChurchInviteCode;
use App\Models\Church;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ChurchInviteCodeController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (!in_array($user->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403);
        }
        $churchId = $user->profile_type === 'superadmin'
            ? ($request->input('church_id') ?: Church::query()->orderBy('id')->value('id'))
            : $user->church_id;
        if (!$churchId) {
            abort(422, 'Missing church_id (for superadmin) or user church link.');
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
        if (!in_array($user->profile_type, ['club_director', 'superadmin'], true)) {
            abort(403);
        }
        $churchId = $user->profile_type === 'superadmin'
            ? ($request->input('church_id') ?: Church::query()->orderBy('id')->value('id'))
            : $user->church_id;
        if (!$churchId) {
            abort(422, 'Missing church_id (for superadmin) or user church link.');
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

    public function regenerateForChurch(Request $request, Church $church)
    {
        $user = $request->user();
        if ($user->profile_type !== 'superadmin') {
            abort(403);
        }

        $code = ChurchInviteCode::updateOrCreate(
            ['church_id' => $church->id],
            [
                'code' => Str::upper(Str::random(10)),
                'status' => 'active',
                'uses_left' => null,
                'expires_at' => null,
                'created_by' => $user->id,
            ]
        );

        return response()->json([
            'message' => 'Invite code generated',
            'code' => $code->code,
            'uses_left' => $code->uses_left,
            'expires_at' => $code->expires_at,
            'status' => $code->status,
        ]);
    }

    public function upsertForChurch(Request $request, Church $church)
    {
        $code = ChurchInviteCode::updateOrCreate(
            ['church_id' => $church->id],
            [
                'code' => Str::upper(Str::random(10)),
                'status' => 'active',
                'uses_left' => null,
                'expires_at' => null,
                'created_by' => $request->user()?->id,
            ]
        );

        return response()->json([
            'message' => 'Invite code generated',
            'code' => $code->code,
            'uses_left' => $code->uses_left,
            'expires_at' => $code->expires_at,
            'status' => $code->status,
        ]);
    }
}
