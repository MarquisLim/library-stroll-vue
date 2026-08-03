<?php

return [

    'enabled' => (bool) env('AI_SERVICE_ENABLED', false),

    'service_url' => env('AI_SERVICE_URL', 'http://127.0.0.1:8001'),

    'api_key' => env('AI_SERVICE_API_KEY'),

    'timeout' => (int) env('AI_SERVICE_TIMEOUT', 180),

    'connect_timeout' => (int) env('AI_SERVICE_CONNECT_TIMEOUT', 10),

    'retry_times' => (int) env('AI_SERVICE_RETRY_TIMES', 2),

    'retry_sleep' => (int) env('AI_SERVICE_RETRY_SLEEP', 500),

    'queue' => env('AI_PROCESSING_QUEUE', 'ai'),

    'schema_version' => (int) env('AI_METADATA_SCHEMA_VERSION', 1),

    'prompt_version' => (int) env('AI_PROMPT_VERSION', 1),

    'auto_analyze_on_publish' => (bool) env('AI_AUTO_ANALYZE_ON_PUBLISH', true),

    'auto_reindex_on_update' => (bool) env('AI_AUTO_REINDEX_ON_UPDATE', true),

    'rerank_enabled' => (bool) env('AI_RERANK_ENABLED', false),

    'search' => [
        'lexical_limit' => (int) env('AI_SEARCH_LEXICAL_LIMIT', 50),
        'semantic_limit' => (int) env('AI_SEARCH_SEMANTIC_LIMIT', 50),
        'rrf_k' => (int) env('AI_SEARCH_RRF_K', 60),
        'final_limit' => (int) env('AI_SEARCH_FINAL_LIMIT', 20),
    ],

];
