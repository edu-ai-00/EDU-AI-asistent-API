<?php

return [
    'api_key' => env('AI_API_KEY'),
    'api_url' => env('AI_API_URL', 'https://openrouter.ai/api/v1'),
    'model' => env('AI_MODEL', 'openai/gpt-4o'),
    'max_tokens' => (int) env('AI_MAX_TOKENS', 2048),
    'max_context' => (int) env('AI_MAX_CONTEXT', 128000),
];
