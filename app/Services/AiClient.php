<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiClient
{
    public function responses(array $payload): array
    {
        $response = $this->request()
            ->post($this->endpoint('/responses'), $payload);

        if ($response->failed()) {
            $message = $response->json('error.message') ?? $response->body();
            throw new RuntimeException('AI provider error: ' . $message);
        }

        return $response->json();
    }

    protected function request(): PendingRequest
    {
        $apiKey = config('ai.api_key');
        if (!$apiKey) {
            throw new RuntimeException('AI_API_KEY is not configured.');
        }

        return Http::timeout(config('ai.timeout_seconds'))
            ->withHeaders([
                'Authorization' => 'Bearer ' . $apiKey,
                'Content-Type' => 'application/json',
            ]);
    }

    protected function endpoint(string $path): string
    {
        $base = rtrim(config('ai.base_url'), '/');
        return $base . $path;
    }
}
