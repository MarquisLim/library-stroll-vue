<?php

return [

    'enabled' => (bool) env('AI_SERVICE_ENABLED', false),
    'assistant_enabled' => (bool) env('AI_ASSISTANT_ENABLED', false),
    'assistant_llm_enabled' => (bool) env('AI_ASSISTANT_LLM_ENABLED', false),
    'assistant_rag_enabled' => (bool) env('AI_ASSISTANT_RAG_ENABLED', false),
    'assistant_history_enabled' => (bool) env('AI_ASSISTANT_HISTORY_ENABLED', false),
    'assistant_context_enabled' => (bool) env('AI_ASSISTANT_CONTEXT_ENABLED', false),
    'assistant_intent_routing_enabled' => (bool) env('AI_ASSISTANT_INTENT_ROUTING_ENABLED', false),
    'assistant_query_understanding_enabled' => (bool) env('AI_ASSISTANT_QUERY_UNDERSTANDING_ENABLED', false),
    'assistant_lexical_metadata_enabled' => (bool) env('AI_ASSISTANT_LEXICAL_METADATA_ENABLED', false),
    'assistant_debug_enabled' => (bool) env('AI_ASSISTANT_DEBUG_ENABLED', false),
    'assistant_llm_timeout' => (float) env('AI_ASSISTANT_LLM_TIMEOUT', 15),
    'assistant_history' => [
        'max_conversations' => min(200, max(1, (int) env('AI_ASSISTANT_HISTORY_MAX_CONVERSATIONS', 20))),
        'max_turns' => min(1000, max(1, (int) env('AI_ASSISTANT_HISTORY_MAX_TURNS', 100))),
        'max_query_chars' => min(500, max(1, (int) env('AI_ASSISTANT_HISTORY_MAX_QUERY_CHARS', 500))),
        'max_reference_bytes' => min(8192, max(2, (int) env('AI_ASSISTANT_HISTORY_MAX_REFERENCE_BYTES', 2048))),
        'page_size' => min(50, max(1, (int) env('AI_ASSISTANT_HISTORY_PAGE_SIZE', 20))),
    ],

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
    'colbert_url' => env('AI_COLBERT_URL'),
    'assistant_colbert_post_rrf_enabled' => (bool) env('AI_ASSISTANT_COLBERT_POST_RRF_ENABLED', false),
    'assistant_colbert_url' => env('AI_ASSISTANT_COLBERT_URL'),
    'assistant_colbert_depth' => (int) env('AI_ASSISTANT_COLBERT_DEPTH', 20),
    'colbert_timeout' => (float) env('AI_COLBERT_TIMEOUT', 0.5),
    'colbert_sync' => [
        'enabled' => (bool) env('AI_COLBERT_SYNC_ENABLED', false),
        'auto_enabled' => (bool) env('AI_COLBERT_AUTO_SYNC_ENABLED', false),
        'url' => env('AI_COLBERT_SYNC_URL'),
        'api_key' => env('AI_COLBERT_SYNC_API_KEY'),
        'api_key_file' => env('AI_COLBERT_SYNC_API_KEY_FILE'),
        'connect_timeout' => (float) env('AI_COLBERT_SYNC_CONNECT_TIMEOUT', 0.25),
        'timeout' => (float) env('AI_COLBERT_SYNC_TIMEOUT', 2),
    ],

    'search' => [
        'lexical_limit' => (int) env('AI_SEARCH_LEXICAL_LIMIT', 50),
        'semantic_limit' => (int) env('AI_SEARCH_SEMANTIC_LIMIT', 50),
        'rrf_k' => (int) env('AI_SEARCH_RRF_K', 60),
        'final_limit' => (int) env('AI_SEARCH_FINAL_LIMIT', 20),
    ],

];
