<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;

class RentalQuoteService
{
    public function __construct(private SerpApiUsageService $usage)
    {
    }

    public function fetchDailyQuoteRange(
        string $vehicleType,
        string $location,
        ?string $startDate = null,
        ?string $endDate = null
    ): array {
        $apiKey = config('services.serpapi.key');
        if (!$apiKey || !config('ai.live_quotes_enabled', true)) {
            return [
                'found' => false,
                'provider' => 'serpapi',
                'reason' => !$apiKey ? 'SERPAPI_KEY is not configured.' : 'Live quotes disabled.',
                'usage' => $this->usage->currentMonthSummary(),
            ];
        }

        $usage = $this->usage->currentMonthSummary();
        if (($usage['remaining'] ?? 0) <= 0) {
            return [
                'found' => false,
                'provider' => 'serpapi',
                'reason' => 'Monthly SerpAPI limit reached.',
                'usage' => $usage,
            ];
        }

        $query = $this->buildQuery($vehicleType, $location, $startDate, $endDate);
        $response = Http::timeout((int) config('ai.live_quotes_timeout_seconds', 12))
            ->get('https://serpapi.com/search.json', [
                'engine' => 'google',
                'q' => $query,
                'gl' => 'us',
                'hl' => 'en',
                'num' => (int) config('ai.live_quotes_max_results', 10),
                'api_key' => $apiKey,
            ]);

        if ($response->failed()) {
            $usageAfter = $this->usage->registerAttempt(false);
            return [
                'found' => false,
                'provider' => 'serpapi',
                'reason' => 'Quote search request failed.',
                'usage' => $usageAfter,
            ];
        }

        $usageAfter = $this->usage->registerAttempt(true);
        $payload = $response->json();
        $results = $payload['organic_results'] ?? [];
        if (!is_array($results) || empty($results)) {
            return [
                'found' => false,
                'provider' => 'serpapi',
                'reason' => 'No search results returned.',
                'usage' => $usageAfter,
            ];
        }

        $quotes = [];
        $samples = [];
        foreach ($results as $result) {
            if (!is_array($result)) {
                continue;
            }

            $title = (string) ($result['title'] ?? '');
            $link = (string) ($result['link'] ?? '');
            $snippet = (string) ($result['snippet'] ?? '');
            $combined = trim($title . ' ' . $snippet);
            $prices = $this->extractUsdValues($combined);
            if (empty($prices)) {
                continue;
            }

            $dailyCandidates = array_values(array_filter($prices, function (float $price) {
                return $price >= (float) config('ai.live_quotes_min_daily_usd', 20)
                    && $price <= (float) config('ai.live_quotes_max_daily_usd', 2000);
            }));

            if (empty($dailyCandidates)) {
                continue;
            }

            $selected = min($dailyCandidates);
            $quotes[] = $selected;
            $samples[] = [
                'source_name' => $this->inferSourceName($link, $title),
                'url' => $link ?: null,
                'snippet' => $snippet ?: null,
                'daily_price_usd' => $selected,
            ];
        }

        $quotes = array_values(array_unique($quotes));
        sort($quotes);
        if (empty($quotes)) {
            return [
                'found' => false,
                'provider' => 'serpapi',
                'reason' => 'No price snippets found in search results.',
                'usage' => $usageAfter,
            ];
        }

        $low = (float) $quotes[0];
        $high = (float) $quotes[count($quotes) - 1];
        $latestSample = !empty($samples) ? $samples[count($samples) - 1] : null;

        return [
            'found' => true,
            'provider' => 'serpapi',
            'query' => $query,
            'checked_at' => now()->toIso8601String(),
            'daily_range' => [$low, $high],
            'quote_count' => count($quotes),
            'quotes' => array_slice($quotes, 0, 10),
            'sources' => $latestSample ? [$latestSample] : [],
            'latest_source' => $latestSample,
            'usage' => $usageAfter,
        ];
    }

    protected function buildQuery(string $vehicleType, string $location, ?string $startDate, ?string $endDate): string
    {
        $parts = [
            trim($vehicleType . ' rental price per day'),
            trim($location),
        ];

        if ($startDate && $endDate) {
            $parts[] = trim($startDate . ' to ' . $endDate);
        } elseif ($startDate) {
            $parts[] = trim('from ' . $startDate);
        }

        return trim(implode(' ', array_filter($parts)));
    }

    protected function extractUsdValues(string $text): array
    {
        if ($text === '') {
            return [];
        }

        preg_match_all('/\\$\\s*([0-9]{1,4}(?:,[0-9]{3})*(?:\\.[0-9]{1,2})?)/', $text, $matches);
        $values = $matches[1] ?? [];

        return array_map(function (string $raw) {
            return (float) str_replace(',', '', $raw);
        }, $values);
    }

    protected function inferSourceName(string $url, string $fallback): string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (is_string($host) && $host !== '') {
            return preg_replace('/^www\\./', '', $host) ?: $fallback;
        }
        return $fallback !== '' ? $fallback : 'Unknown source';
    }
}
