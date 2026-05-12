<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;

class SuperAdminPresenceController extends Controller
{
    public function index(Request $request)
    {
        $onlineCutoff = now()->subMinutes(5);
        $recentCutoff = now()->subMinutes(30);

        if (!Schema::hasColumn('users', 'last_seen_at')) {
            return Inertia::render('SuperAdmin/PresenceLog', [
                'online_threshold_minutes' => 5,
                'recent_threshold_minutes' => 30,
                'generated_at' => now()->toIso8601String(),
                'online_users' => [],
                'recent_users' => [],
            ]);
        }

        $baseQuery = User::query()
            ->where('status', '!=', 'deleted')
            ->select([
                'id',
                'name',
                'email',
                'profile_type',
                'role_key',
                'scope_type',
                'scope_id',
                'church_name',
                'church_id',
                'club_id',
                'status',
                'last_seen_at',
            ]);

        $onlineUsers = (clone $baseQuery)
            ->where('last_seen_at', '>=', $onlineCutoff)
            ->orderByDesc('last_seen_at')
            ->limit(100)
            ->get();

        $recentUsers = (clone $baseQuery)
            ->whereNotNull('last_seen_at')
            ->where('last_seen_at', '<', $onlineCutoff)
            ->where('last_seen_at', '>=', $recentCutoff)
            ->orderByDesc('last_seen_at')
            ->limit(50)
            ->get();

        return Inertia::render('SuperAdmin/PresenceLog', [
            'online_threshold_minutes' => 5,
            'recent_threshold_minutes' => 30,
            'generated_at' => now()->toIso8601String(),
            'online_users' => $onlineUsers,
            'recent_users' => $recentUsers,
        ]);
    }
}
