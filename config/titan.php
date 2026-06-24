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
        'last_month' => 'Last month',
        'last_year' => 'Last year',
        'ytd' => 'Year to date',
    ],

    'date_comparisons' => [
        'none' => 'No comparison',
        'previous_period' => 'Compare to previous period',
        'year_over_year' => 'Compare year over year',
    ],

    'dashboard' => [
        'connector_cache_enabled' => (bool) env('TITAN_DASHBOARD_CONNECTOR_CACHE_ENABLED', true),
        'connector_cache_ttl_seconds' => (int) env('TITAN_DASHBOARD_CONNECTOR_CACHE_TTL_SECONDS', 90),
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
        // Keep each queue job short for hosted workers (e.g. Laravel Cloud ~60s limit).
        'pages_per_job' => (int) env('TITAN_SYNC_PAGES_PER_JOB', 2),
        'max_seconds_per_job' => (int) env('TITAN_SYNC_MAX_SECONDS_PER_JOB', 45),
        'job_timeout' => (int) env('TITAN_SYNC_JOB_TIMEOUT', 55),
        'memory_limit' => env('TITAN_SYNC_MEMORY_LIMIT', '512M'),
        // Dispatch one ingestion job per connector stream so managed queues can scale past 1 worker.
        'stream_fan_out_enabled' => (bool) env('TITAN_SYNC_STREAM_FAN_OUT_ENABLED', true),
        // Backfills fetch recent dates first so dashboards populate before older history finishes.
        'backfill_newest_first' => (bool) env('TITAN_SYNC_BACKFILL_NEWEST_FIRST', true),
        // Queue a transform after each ingestion chunk while a sync is running.
        'transform_during_sync' => (bool) env('TITAN_SYNC_TRANSFORM_DURING_SYNC', true),
        // Skip mid-sync transforms during initial backfill; finalize still runs at sync end.
        'transform_during_backfill' => (bool) env('TITAN_SYNC_TRANSFORM_DURING_BACKFILL', false),
    ],

    'transform' => [
        // Keep each transform job short for hosted workers (e.g. Laravel Cloud ~60s limit).
        'payloads_per_chunk' => (int) env('TITAN_TRANSFORM_PAYLOADS_PER_CHUNK', 750),
        'chunks_per_job' => (int) env('TITAN_TRANSFORM_CHUNKS_PER_JOB', 3),
        'max_seconds_per_job' => (int) env('TITAN_TRANSFORM_MAX_SECONDS_PER_JOB', 45),
        'job_timeout' => (int) env('TITAN_TRANSFORM_JOB_TIMEOUT', 55),
        'memory_limit' => env('TITAN_TRANSFORM_MEMORY_LIMIT', '512M'),
        'resource_types' => [
            'order',
            'order_line_item',
            'search_daily',
            'traffic_daily',
            'organic_traffic',
            'ad_spend',
            'spend_daily',
            'campaign_daily',
            'channel_daily',
        ],
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
        'row_limit' => (int) env('TITAN_SEARCH_CONSOLE_ROW_LIMIT', 1000),
        'chunk_days' => (int) env('TITAN_SEARCH_CONSOLE_CHUNK_DAYS', 1),
        'top_queries_limit' => (int) env('TITAN_SEARCH_CONSOLE_TOP_QUERIES_LIMIT', 25),
    ],

    'google_ads' => [
        'api_version' => env('TITAN_GOOGLE_ADS_API_VERSION', 'v21'),
        'developer_token' => env('GOOGLE_ADS_DEVELOPER_TOKEN'),
        'backfill_months' => (int) env('TITAN_GOOGLE_ADS_BACKFILL_MONTHS', 16),
        'incremental_days' => (int) env('TITAN_GOOGLE_ADS_INCREMENTAL_DAYS', 5),
        'data_lag_days' => (int) env('TITAN_GOOGLE_ADS_DATA_LAG_DAYS', 1),
        'row_limit' => (int) env('TITAN_GOOGLE_ADS_ROW_LIMIT', 1000),
        'chunk_days' => (int) env('TITAN_GOOGLE_ADS_CHUNK_DAYS', 1),
        'top_campaigns_limit' => (int) env('TITAN_GOOGLE_ADS_TOP_CAMPAIGNS_LIMIT', 25),
    ],

    'stackadapt' => [
        'graphql_endpoint' => env('TITAN_STACKADAPT_GRAPHQL_ENDPOINT', 'https://api.stackadapt.com/graphql'),
        'rest_base_url' => env('TITAN_STACKADAPT_REST_BASE_URL', 'https://api.stackadapt.com/v2'),
        'backfill_months' => (int) env('TITAN_STACKADAPT_BACKFILL_MONTHS', 16),
        'incremental_days' => (int) env('TITAN_STACKADAPT_INCREMENTAL_DAYS', 5),
        'data_lag_days' => (int) env('TITAN_STACKADAPT_DATA_LAG_DAYS', 1),
        'chunk_days' => (int) env('TITAN_STACKADAPT_CHUNK_DAYS', 1),
        'page_size' => (int) env('TITAN_STACKADAPT_PAGE_SIZE', 250),
        'top_campaigns_limit' => (int) env('TITAN_STACKADAPT_TOP_CAMPAIGNS_LIMIT', 25),
        'top_channels_limit' => (int) env('TITAN_STACKADAPT_TOP_CHANNELS_LIMIT', 10),
        'top_insights_limit' => (int) env('TITAN_STACKADAPT_TOP_INSIGHTS_LIMIT', 15),
        'use_rest_fallback' => (bool) env('TITAN_STACKADAPT_USE_REST_FALLBACK', false),
        'test_window_days' => (int) env('TITAN_STACKADAPT_TEST_WINDOW_DAYS', 30),
    ],

    'reddit_ads' => [
        'base_url' => env('TITAN_REDDIT_ADS_BASE_URL', 'https://ads-api.reddit.com/api/v3'),
        'backfill_months' => (int) env('TITAN_REDDIT_ADS_BACKFILL_MONTHS', 16),
        'incremental_days' => (int) env('TITAN_REDDIT_ADS_INCREMENTAL_DAYS', 5),
        'data_lag_days' => (int) env('TITAN_REDDIT_ADS_DATA_LAG_DAYS', 1),
        'chunk_days' => (int) env('TITAN_REDDIT_ADS_CHUNK_DAYS', 7),
        'http_timeout_seconds' => (int) env('TITAN_REDDIT_ADS_HTTP_TIMEOUT', 30),
        'top_campaigns_limit' => (int) env('TITAN_REDDIT_ADS_TOP_CAMPAIGNS_LIMIT', 25),
    ],

    'google_analytics' => [
        'backfill_months' => (int) env('TITAN_GA4_BACKFILL_MONTHS', 16),
        'incremental_days' => (int) env('TITAN_GA4_INCREMENTAL_DAYS', 5),
        'data_lag_days' => (int) env('TITAN_GA4_DATA_LAG_DAYS', 2),
        'row_limit' => (int) env('TITAN_GA4_ROW_LIMIT', 1000),
        'chunk_days' => (int) env('TITAN_GA4_CHUNK_DAYS', 1),
        'top_events_limit' => (int) env('TITAN_GA4_TOP_EVENTS_LIMIT', 15),
        'top_keywords_limit' => (int) env('TITAN_GA4_TOP_KEYWORDS_LIMIT', 25),
        'opportunities' => [
            'min_impressions' => (int) env('TITAN_GA4_OPP_MIN_IMPRESSIONS', 50),
            'low_ctr_multiplier' => (float) env('TITAN_GA4_OPP_LOW_CTR_MULTIPLIER', 0.5),
            'striking_distance_min' => (float) env('TITAN_GA4_OPP_STRIKING_MIN_POSITION', 4),
            'striking_distance_max' => (float) env('TITAN_GA4_OPP_STRIKING_MAX_POSITION', 10),
            'traffic_drop_percent' => (float) env('TITAN_GA4_OPP_TRAFFIC_DROP_PERCENT', 30),
            'limit' => (int) env('TITAN_GA4_OPP_LIMIT', 10),
        ],
    ],

    'ai_perf_logging' => (bool) env('TITAN_AI_PERF_LOGGING', true),

    'agent_memory' => [
        'enabled' => (bool) env('TITAN_AGENT_MEMORY_ENABLED', true),
        'max_injected' => (int) env('TITAN_AGENT_MEMORY_MAX_INJECTED', 6),
        'max_content_chars' => (int) env('TITAN_AGENT_MEMORY_MAX_CHARS', 4000),
    ],

    'reporting' => [
        'provider' => env('TITAN_AI_PROVIDER', 'openai'),
        'model' => env('TITAN_AI_MODEL', 'gpt-4o-mini'),
        'max_steps' => (int) env('TITAN_AI_MAX_STEPS', 8),
        'client_max_steps' => (int) env('TITAN_AI_CLIENT_MAX_STEPS', 8),
        'max_history_messages' => (int) env('TITAN_AI_MAX_HISTORY_MESSAGES', 12),
        'preview_cache_ttl_seconds' => (int) env('TITAN_AI_PREVIEW_CACHE_TTL', 60),
        'fast_path_enabled' => env('TITAN_AI_FAST_PATH_ENABLED', true),
        'max_rows' => 500,
        'query_timeout_seconds' => 10,
        'response_timeout_seconds' => (int) env('TITAN_AI_RESPONSE_TIMEOUT', 120),
    ],

    'connector_builder' => [
        'provider' => env('TITAN_AI_PROVIDER', 'openai'),
        'model' => env('TITAN_CONNECTOR_BUILDER_MODEL', env('TITAN_AI_MODEL', 'gpt-4o-mini')),
        'max_steps' => (int) env('TITAN_CONNECTOR_BUILDER_MAX_STEPS', 15),
        'allowed_auth_types' => ['api_key', 'bearer', 'basic', 'oauth2_client_credentials'],
        'allowed_http_methods' => ['GET', 'POST'],
        'read_only_enforced' => true,
        'max_streams_per_blueprint' => (int) env('TITAN_CONNECTOR_BUILDER_MAX_STREAMS', 8),
        'http_timeout_seconds' => (int) env('TITAN_CONNECTOR_BUILDER_HTTP_TIMEOUT', 30),
        'max_response_bytes' => (int) env('TITAN_CONNECTOR_BUILDER_MAX_RESPONSE_BYTES', 5_000_000),
        'response_timeout_seconds' => (int) env('TITAN_CONNECTOR_BUILDER_RESPONSE_TIMEOUT', 180),
    ],

    'connector_api_logs' => [
        'enabled' => (bool) env('TITAN_CONNECTOR_API_LOGS_ENABLED', true),
        'retention_hours' => (int) env('TITAN_CONNECTOR_API_LOGS_RETENTION_HOURS', 48),
        'max_body_bytes' => (int) env('TITAN_CONNECTOR_API_LOGS_MAX_BODY_BYTES', 100_000),
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
