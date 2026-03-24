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
    'event_task_template_db_threshold' => (int) env('AI_EVENT_TASK_TEMPLATE_DB_THRESHOLD', 8),
    'intent_min_score' => (float) env('AI_INTENT_MIN_SCORE', 0.35),
    'intent_multi_delta' => (float) env('AI_INTENT_MULTI_DELTA', 0.12),
    'live_quotes_enabled' => (bool) env('AI_LIVE_QUOTES_ENABLED', true),
    'live_quotes_timeout_seconds' => (int) env('AI_LIVE_QUOTES_TIMEOUT_SECONDS', 12),
    'live_quotes_max_results' => (int) env('AI_LIVE_QUOTES_MAX_RESULTS', 10),
    'live_quotes_min_daily_usd' => (float) env('AI_LIVE_QUOTES_MIN_DAILY_USD', 20),
    'live_quotes_max_daily_usd' => (float) env('AI_LIVE_QUOTES_MAX_DAILY_USD', 2000),
    'serpapi_monthly_limit' => (int) env('SERPAPI_MONTHLY_LIMIT', 250),
];
