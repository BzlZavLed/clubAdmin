<?php

namespace App\Services;

use App\Models\PrivacyConsent;
use App\Models\User;
use App\Support\AuditRecorder;
use App\Support\PrivacyNotice;
use Illuminate\Http\Request;

final class PrivacyConsentRecorder
{
    public function record(User $user, Request $request, string $source): PrivacyConsent
    {
        $consent = PrivacyConsent::create([
            'user_id' => $user->id,
            'notice_version' => PrivacyNotice::VERSION,
            'notice_hash' => PrivacyNotice::digest(),
            'subject_email_hash' => AuditRecorder::identifierHash($user->email),
            'source' => $source,
            'locale' => $request->session()->get('locale'),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'consented_at' => now(),
        ]);

        AuditRecorder::event('privacy_consent_recorded', [
            'actor_id' => $user->id,
            'entity_type' => 'PrivacyConsent',
            'entity_id' => $consent->id,
            'entity_label' => PrivacyNotice::VERSION,
            'metadata' => [
                'notice_version' => PrivacyNotice::VERSION,
                'notice_hash' => PrivacyNotice::digest(),
                'source' => $source,
            ],
        ], $request);

        return $consent;
    }
}
