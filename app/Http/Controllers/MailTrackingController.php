<?php

namespace App\Http\Controllers;

use App\Models\MailDeliveryLog;
use Illuminate\Http\Request;

class MailTrackingController extends Controller
{
    public function open(Request $request, MailDeliveryLog $mailLog)
    {
        $now = now();
        $metadata = $mailLog->metadata ?: [];
        $metadata['open_source'] ??= 'tracking_pixel';

        $mailLog->forceFill([
            'opened_at' => $mailLog->opened_at ?: $now,
            'last_opened_at' => $now,
            'open_count' => ((int) $mailLog->open_count) + 1,
            'last_open_ip' => $request->ip(),
            'last_open_user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
            'metadata' => $metadata,
        ])->save();

        return response(base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw=='), 200, [
            'Content-Type' => 'image/gif',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
            'Pragma' => 'no-cache',
            'Expires' => '0',
        ]);
    }
}
