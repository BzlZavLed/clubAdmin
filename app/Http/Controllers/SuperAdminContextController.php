<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Support\SuperadminContext;

class SuperAdminContextController extends Controller
{
    public function set(Request $request)
    {
        $user = $request->user();
        if (!$user || $user->profile_type !== 'superadmin') {
            abort(403, 'Only superadmin can change context.');
        }

        $validated = $request->validate([
            'union_id' => ['nullable', 'integer', 'exists:unions,id'],
            'association_id' => ['nullable', 'integer', 'exists:associations,id'],
            'district_id' => ['nullable', 'integer', 'exists:districts,id'],
            'church_id' => ['nullable', 'integer', 'exists:churches,id'],
            'club_id' => ['nullable', 'integer', 'exists:clubs,id'],
        ]);

        $context = SuperadminContext::normalize($validated);

        $request->session()->put('superadmin_context.role', $context['role']);
        $request->session()->put('superadmin_context.union_id', $context['union_id']);
        $request->session()->put('superadmin_context.association_id', $context['association_id']);
        $request->session()->put('superadmin_context.district_id', $context['district_id']);
        $request->session()->put('superadmin_context.church_id', $context['church_id']);
        $request->session()->put('superadmin_context.club_id', $context['club_id']);

        return response()->json([
            'message' => 'Superadmin context updated.',
            'context' => $context,
        ]);
    }
}
