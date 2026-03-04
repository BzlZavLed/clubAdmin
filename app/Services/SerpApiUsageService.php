<?php

namespace App\Services;

use App\Models\SerpApiUsageMonthly;

class SerpApiUsageService
{
    public function currentMonthSummary(): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $limit = (int) config('ai.serpapi_monthly_limit', 250);

        $usage = SerpApiUsageMonthly::firstOrCreate(
            ['usage_month' => $monthStart],
            ['calls_count' => 0, 'success_count' => 0, 'failed_count' => 0]
        );

        $used = (int) $usage->calls_count;

        return [
            'month' => $monthStart,
            'limit' => $limit,
            'used' => $used,
            'remaining' => max(0, $limit - $used),
            'success_count' => (int) $usage->success_count,
            'failed_count' => (int) $usage->failed_count,
            'last_called_at' => optional($usage->last_called_at)?->toIso8601String(),
        ];
    }

    public function registerAttempt(bool $success): array
    {
        $monthStart = now()->startOfMonth()->toDateString();
        $usage = SerpApiUsageMonthly::firstOrCreate(
            ['usage_month' => $monthStart],
            ['calls_count' => 0, 'success_count' => 0, 'failed_count' => 0]
        );

        $usage->increment('calls_count');
        $usage->increment($success ? 'success_count' : 'failed_count');
        $usage->last_called_at = now();
        $usage->save();

        return $this->currentMonthSummary();
    }
}
