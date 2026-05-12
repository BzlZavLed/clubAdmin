<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TrackUserPresence
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();

        if ($user && $this->supportsLastSeenColumn()) {
            $syncedAt = (int) $request->session()->get('last_seen_at_synced_at', 0);

            if ($syncedAt <= now()->subMinute()->timestamp) {
                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['last_seen_at' => now()]);

                $request->session()->put('last_seen_at_synced_at', now()->timestamp);
            }
        }

        return $next($request);
    }

    protected function supportsLastSeenColumn(): bool
    {
        static $supportsLastSeen = null;

        if ($supportsLastSeen === null) {
            $supportsLastSeen = Schema::hasColumn('users', 'last_seen_at');
        }

        return $supportsLastSeen;
    }
}
