<?php

namespace App\Http\Controllers;

use App\Models\Church;
use App\Models\Club;
use Illuminate\Http\Request;

class SuperAdminContextController extends Controller
{
    public function set(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can change context.');
        }

        $validated = $request->validate([
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        $selectedClub = null;
        $selectedChurch = null;

        if (!empty($validated['club_id'])) {
            $selectedClub = Club::query()
                ->withoutGlobalScopes()
                ->where('status', '!=', 'deleted')
                ->where('id', (int) $validated['club_id'])
                ->firstOrFail(['id', 'club_name', 'church_id']);
        }

        $churchId = $validated['church_id'] ?? null;
        if ($selectedClub) {
            if ($churchId && (int) $churchId !== (int) $selectedClub->church_id) {
                return response()->json([
                    'message' => 'Selected club does not belong to selected church.',
                ], 422);
            }
            $churchId = (int) $selectedClub->church_id;
        }

        if ($churchId) {
            $selectedChurch = Church::query()
                ->where('id', (int) $churchId)
                ->firstOrFail(['id', 'church_name']);
        }

        $request->session()->put('superadmin_context.church_id', $churchId);
        $request->session()->put('superadmin_context.club_id', $selectedClub?->id);

        return response()->json([
            'message' => 'Superadmin context updated.',
            'context' => [
                'church_id' => $churchId,
                'church_name' => $selectedChurch?->church_name,
                'club_id' => $selectedClub?->id,
                'club_name' => $selectedClub?->club_name,
            ],
        ]);
    }
}
