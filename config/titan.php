<?php

return [
    'queues' => [
        'ingestion' => env('TITAN_QUEUE_INGESTION', 'ingestion'),
        'transform' => env('TITAN_QUEUE_TRANSFORM', 'transform'),
        'cache' => env('TITAN_QUEUE_CACHE', 'cache'),
        'ai' => env('TITAN_QUEUE_AI', 'ai'),
    ],

    'currency' => 'USD',

    'branding' => [
        'powered_by_text' => 'Powered by Irish Titan',
        'powered_by_required' => true,
        'ai_assistant_name' => env('TITAN_AI_ASSISTANT_NAME', 'TitanAI'),
    ],

    'attribution_windows' => [
        7 => '7 days',
        14 => '14 days',
        30 => '30 days',
        60 => '60 days',
        90 => '90 days',
    ],

    'date_range_presets' => [
        'last_7_days' => 'Last 7 days',
        'last_30_days' => 'Last 30 days',
        'last_90_days' => 'Last 90 days',
        'ytd' => 'Year to date',
    ],

    'date_comparisons' => [
        'none' => 'No comparison',
        'previous_period' => 'Compare to previous period',
        'year_over_year' => 'Compare year over year',
    ],

    'invitations' => [
        'expires_days' => (int) env('TITAN_INVITATION_EXPIRY_DAYS', 7),
    ],

    'commerce' => [
        'backfill_years' => (int) env('TITAN_COMMERCE_BACKFILL_YEARS', 2),
        'orders_page_size' => 50,
        'line_items_enabled' => env('TITAN_COMMERCE_LINE_ITEMS_ENABLED', true),
        'top_products_limit' => (int) env('TITAN_COMMERCE_TOP_PRODUCTS_LIMIT', 10),
    ],

    'shopify' => [
        'rest_api_version' => env('TITAN_SHOPIFY_REST_API_VERSION', '2024-10'),
        'analytics_api_version' => env('TITAN_SHOPIFY_ANALYTICS_API_VERSION', '2025-10'),
        'analytics_enabled' => env('TITAN_SHOPIFY_ANALYTICS_ENABLED', true),
        'analytics_chunk_days' => (int) env('TITAN_SHOPIFY_ANALYTICS_CHUNK_DAYS', 30),
        'analytics_row_limit' => (int) env('TITAN_SHOPIFY_ANALYTICS_ROW_LIMIT', 1000),
        'rate_limit' => [
            'max_retries' => (int) env('TITAN_SHOPIFY_RATE_LIMIT_MAX_RETRIES', 5),
            'base_delay_ms' => (int) env('TITAN_SHOPIFY_RATE_LIMIT_BASE_DELAY_MS', 1000),
            'max_delay_ms' => (int) env('TITAN_SHOPIFY_RATE_LIMIT_MAX_DELAY_MS', 60000),
            'request_delay_ms' => (int) env('TITAN_SHOPIFY_RATE_LIMIT_REQUEST_DELAY_MS', 500),
            'job_retry_delay_seconds' => (int) env('TITAN_SHOPIFY_RATE_LIMIT_JOB_RETRY_SECONDS', 120),
        ],
    ],

    'sync' => [
        'daily_at' => env('TITAN_SYNC_DAILY_AT', '02:00'),
        'hourly_today' => env('TITAN_SYNC_HOURLY_TODAY', true),
        'pages_per_job' => (int) env('TITAN_SYNC_PAGES_PER_JOB', 5),
        'memory_limit' => env('TITAN_SYNC_MEMORY_LIMIT', '512M'),
    ],

    'semrush' => [
        'resources' => [
            'domain_overview',
            'organic_keywords',
        ],
        'keyword_limit' => 50,
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'oauth_pending_ttl_minutes' => (int) env('TITAN_GOOGLE_OAUTH_PENDING_TTL_MINUTES', 30),
    ],

    'search_console' => [
        'backfill_months' => (int) env('TITAN_SEARCH_CONSOLE_BACKFILL_MONTHS', 16),
        'incremental_days' => (int) env('TITAN_SEARCH_CONSOLE_INCREMENTAL_DAYS', 5),
        'data_lag_days' => (int) env('TITAN_SEARCH_CONSOLE_DATA_LAG_DAYS', 3),
        'row_limit' => (int) env('TITAN_SEARCH_CONSOLE_ROW_LIMIT', 5000),
        'chunk_days' => (int) env('TITAN_SEARCH_CONSOLE_CHUNK_DAYS', 7),
    ],

    'reporting' => [
        'provider' => env('TITAN_AI_PROVIDER', 'openai'),
        'model' => env('TITAN_AI_MODEL', 'gpt-4o-mini'),
        'max_steps' => (int) env('TITAN_AI_MAX_STEPS', 10),
        'client_max_steps' => (int) env('TITAN_AI_CLIENT_MAX_STEPS', 6),
        'max_rows' => 500,
        'query_timeout_seconds' => 10,
        'response_timeout_seconds' => (int) env('TITAN_AI_RESPONSE_TIMEOUT', 120),
    ],

    'feedback' => [
        'max_attachments' => (int) env('TITAN_FEEDBACK_MAX_ATTACHMENTS', 5),
        'max_attachment_kb' => (int) env('TITAN_FEEDBACK_MAX_ATTACHMENT_KB', 10240),
    ],

    'analytics_engineer' => [
        'max_steps' => (int) env('TITAN_AI_MAX_STEPS', 10),
        'anomaly_drop_threshold_percent' => 50,
        'quality_lookback_days' => 30,
    ],
];
