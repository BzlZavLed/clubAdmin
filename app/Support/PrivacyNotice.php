<?php

namespace App\Support;

final class PrivacyNotice
{
    public const VERSION = '2026-08-27';

    public const CONSENT_RECORD_TEXT = 'Adult accepts processing of voluntarily provided personal information for account administration, enrollment, safety, communications, payments, and club activities; acknowledges access, correction, and deletion rights subject to applicable exceptions and retention duties; and confirms parental, guardian, or other lawful authority when providing a minor’s information.';

    public static function digest(): string
    {
        return hash('sha256', self::VERSION.'|'.self::CONSENT_RECORD_TEXT);
    }
}
