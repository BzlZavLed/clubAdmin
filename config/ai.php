<?php

return [
    'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL', 'gpt-4.1-mini'),
    'max_output_tokens' => (int) env('AI_MAX_OUTPUT_TOKENS', 800),
    'daily_token_cap' => (int) env('AI_DAILY_TOKEN_CAP', 400000),
    'event_message_cap' => (int) env('AI_EVENT_MESSAGE_CAP', 40),
    'timeout_seconds' => (int) env('AI_TIMEOUT_SECONDS', 30),
    'provider' => env('AI_PROVIDER', 'openai-compatible'),
    'debug_return' => (bool) env('AI_DEBUG_RETURN', false),
];
