<?php

namespace App\Support;

use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Services\Analytics\MetricRegistry;

class AnalyticsSchemaCatalog
{
    public function __construct(protected MetricRegistry $metrics) {}

    /**
     * @return array<string, mixed>
     */
    public function forDashboard(ClientDashboard $dashboard): array
    {
        $connections = $dashboard->connections()
            ->where('is_active', true)
            ->get(['id', 'name', 'connector_type']);

        $activeTypes = $connections
            ->map(fn (Connection $c) => $c->connector_type->value)
            ->unique()
            ->values()
            ->all();

        return [
            'dashboard_id' => $dashboard->id,
            'connections' => $connections->map(fn (Connection $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'connector_type' => $c->connector_type->value,
                'is_commerce' => $c->connector_type->isCommerce(),
            ])->values()->all(),
            'placeholders' => [
                ':dashboard_id' => 'Always required. Filter data to this dashboard.',
                ':start_date' => 'Inclusive start date (YYYY-MM-DD). Bound from cover page period or dashboard date picker.',
                ':end_date' => 'Inclusive end date (YYYY-MM-DD).',
                ':compare_start_date' => 'Optional comparison period start.',
                ':compare_end_date' => 'Optional comparison period end.',
                ':connection_id' => 'Optional. Scope to a specific connection when needed.',
            ],
            'tables' => $this->tables(),
            'connector_entities' => $this->connectorEntities($activeTypes),
            'metric_definitions' => $this->metrics->summaryForPrompt($dashboard),
            'data_modeling_notes' => $this->dataModelingNotes(),
            'notes' => [
                'Commerce order data is in raw_connector_payloads with resource_type = order.',
                'Order line items use resource_type = order_line_item (one row per product line, external_id order_id:line_item_id).',
                JsonPayloadSql::promptHint(),
                'metric_snapshots may have multiple rows per date+metric_key with different dimension_hash values. Filter dimensions explicitly or GROUP BY to avoid double-counting.',
                'All queries MUST be SELECT only and MUST filter by client_dashboard_id via connections or metric_snapshots.',
            ],
        ];
    }

    public function asPromptText(ClientDashboard $dashboard): string
    {
        $catalog = $this->forDashboard($dashboard);

        return json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    public function asCompactPromptSummary(ClientDashboard $dashboard): string
    {
        $catalog = $this->forDashboard($dashboard);

        $connections = collect($catalog['connections'])
            ->map(fn (array $c) => sprintf('%s (%s)', $c['name'], $c['connector_type']))
            ->implode(', ');

        if ($connections === '') {
            $connections = 'none active';
        }

        $metricSlugs = collect($catalog['metric_definitions'])
            ->pluck('slug')
            ->implode(', ');

        if ($metricSlugs === '') {
            $metricSlugs = 'none registered';
        }

        $placeholders = collect($catalog['placeholders'])
            ->keys()
            ->implode(', ');

        $jsonHint = JsonPayloadSql::promptHint();

        return <<<SUMMARY
Connections: {$connections}
Metric slugs: {$metricSlugs}
Placeholders: {$placeholders}

SQL rules:
- SELECT only. Scope every query via :dashboard_id (join connections or filter metric_snapshots).
- Filter dates with payload date field BETWEEN :start_date AND :end_date for orders. {$jsonHint}
- Commerce orders live in raw_connector_payloads where resource_type = 'order'.
- Search Console: resource_type keyword (queries), search_daily (site totals), search_page (landing pages), search_device (device breakdown). Use json_extract for clicks, impressions, ctr, position, keyword, page, device.
- Google Analytics 4: resource_type traffic_daily, traffic_channel, events_daily, landing_page. Use json_extract for visitors, active_users, sessions, event_name, event_count, channel, landing_page.
- Google Ads: resource_type spend_daily, campaign_daily. Use json_extract for cost, impressions, clicks, ctr, conversions_value, campaign_id, campaign_name.
- StackAdapt: resource_type spend_daily, campaign_daily, channel_daily, insight_geo_daily, insight_domain_daily, insight_device_daily. Use json_extract for cost, impressions, clicks, ctr, conversions, conversions_value, roas, channel_type, campaign_id, dimension_key, dimension_label.

Before writing SQL, call ListAnalyticsSchemaTool for full tables and connector fields, or DescribeConnectorSchemaTool for a specific connector.
SUMMARY;
    }

    /**
     * @param  list<string>  $activeTypes
     * @return list<array<string, mixed>>
     */
    public function connectorEntitiesForTypes(array $activeTypes): array
    {
        return $this->connectorEntities($activeTypes);
    }

    /**
     * @param  list<string>  $activeTypes
     * @return list<array<string, mixed>>
     */
    protected function connectorEntities(array $activeTypes): array
    {
        $all = [
            'shopify' => [
                'connector' => 'shopify',
                'entities' => [
                    [
                        'name' => 'orders',
                        'titan_resource_type' => 'order',
                        'source_api' => 'Shopify Admin REST orders',
                        'payload_fields' => ['date', 'total', 'order_number', 'source', 'medium', 'source_medium', 'channel', 'referring_site', 'currency'],
                        'fact_table_recommendation' => 'fact_orders',
                    ],
                    [
                        'name' => 'order_line_items',
                        'titan_resource_type' => 'order_line_item',
                        'source_api' => 'Shopify Admin REST order line_items',
                        'payload_fields' => ['order_id', 'line_item_id', 'date', 'sku', 'name', 'variant_title', 'quantity', 'unit_price', 'sale_price', 'compare_at_price', 'discount_amount', 'line_total', 'image_url', 'product_id', 'variant_id', 'vendor', 'source', 'medium', 'channel'],
                        'fact_table_recommendation' => 'fact_order_lines',
                    ],
                    [
                        'name' => 'session_attribution',
                        'titan_resource_type' => 'session_attribution',
                        'source_api' => 'ShopifyQL shopifyqlQuery',
                        'payload_fields' => ['date', 'source', 'medium', 'source_medium', 'sessions', 'visitors'],
                        'fact_table_recommendation' => 'fact_sessions',
                    ],
                    [
                        'name' => 'customers',
                        'titan_resource_type' => null,
                        'source_api' => 'Shopify Admin REST customers (not synced in v1)',
                        'notes' => 'Customer-level metrics require future customer sync.',
                        'dim_table_recommendation' => 'dim_customers',
                    ],
                    [
                        'name' => 'products',
                        'titan_resource_type' => null,
                        'source_api' => 'Shopify Admin REST products (not synced in v1)',
                        'dim_table_recommendation' => 'dim_products',
                    ],
                    [
                        'name' => 'inventory',
                        'titan_resource_type' => null,
                        'source_api' => 'Shopify inventory levels (not synced in v1)',
                    ],
                    [
                        'name' => 'fulfillments',
                        'titan_resource_type' => null,
                        'source_api' => 'Shopify fulfillments (not synced in v1)',
                    ],
                ],
            ],
            'bigcommerce' => [
                'connector' => 'bigcommerce',
                'entities' => [
                    [
                        'name' => 'orders',
                        'titan_resource_type' => 'order',
                        'source_api' => 'BigCommerce v2 orders',
                        'payload_fields' => ['date', 'total', 'order_number', 'source', 'medium', 'source_medium', 'channel', 'referring_site', 'currency'],
                        'fact_table_recommendation' => 'fact_orders',
                    ],
                    [
                        'name' => 'order_line_items',
                        'titan_resource_type' => 'order_line_item',
                        'source_api' => 'BigCommerce v2 orders/{id}/products',
                        'payload_fields' => ['order_id', 'line_item_id', 'date', 'sku', 'name', 'quantity', 'unit_price', 'sale_price', 'compare_at_price', 'discount_amount', 'line_total', 'image_url', 'product_id', 'variant_id', 'source', 'medium', 'channel'],
                        'fact_table_recommendation' => 'fact_order_lines',
                    ],
                    [
                        'name' => 'customers',
                        'titan_resource_type' => null,
                        'source_api' => 'BigCommerce customers (not synced in v1)',
                        'dim_table_recommendation' => 'dim_customers',
                    ],
                    [
                        'name' => 'products',
                        'titan_resource_type' => null,
                        'source_api' => 'BigCommerce catalog products (not synced in v1)',
                        'dim_table_recommendation' => 'dim_products',
                    ],
                    [
                        'name' => 'channels',
                        'titan_resource_type' => null,
                        'source_api' => 'BigCommerce channels (mapped via order channel field)',
                        'notes' => 'Channel appears on normalized order payloads when available.',
                    ],
                ],
            ],
            'search_console' => [
                'connector' => 'search_console',
                'entities' => [
                    [
                        'name' => 'search_daily',
                        'titan_resource_type' => 'search_daily',
                        'source_api' => 'Google Search Console searchAnalytics.query (dimensions: date)',
                        'payload_fields' => ['date', 'clicks', 'impressions', 'ctr', 'position'],
                        'fact_table_recommendation' => 'fact_search_daily',
                    ],
                    [
                        'name' => 'search_queries',
                        'titan_resource_type' => 'keyword',
                        'source_api' => 'Google Search Console searchAnalytics.query (dimensions: date, query)',
                        'payload_fields' => ['date', 'keyword', 'clicks', 'impressions', 'ctr', 'position'],
                        'fact_table_recommendation' => 'fact_search_queries',
                    ],
                    [
                        'name' => 'search_pages',
                        'titan_resource_type' => 'search_page',
                        'source_api' => 'Google Search Console searchAnalytics.query (dimensions: date, page)',
                        'payload_fields' => ['date', 'page', 'clicks', 'impressions', 'ctr', 'position'],
                        'fact_table_recommendation' => 'fact_search_pages',
                    ],
                    [
                        'name' => 'search_devices',
                        'titan_resource_type' => 'search_device',
                        'source_api' => 'Google Search Console searchAnalytics.query (dimensions: date, device)',
                        'payload_fields' => ['date', 'device', 'clicks', 'impressions', 'ctr', 'position'],
                        'fact_table_recommendation' => 'fact_search_devices',
                    ],
                ],
            ],
            [
                'connector_type' => 'google_ads',
                'label' => 'Google Ads',
                'resources' => [
                    [
                        'name' => 'spend_daily',
                        'titan_resource_type' => 'spend_daily',
                        'source_api' => 'Google Ads API searchStream (FROM customer, segments.date)',
                        'payload_fields' => ['date', 'cost', 'impressions', 'clicks', 'ctr', 'conversions_value'],
                        'fact_table_recommendation' => 'fact_google_ads_spend_daily',
                    ],
                    [
                        'name' => 'campaign_daily',
                        'titan_resource_type' => 'campaign_daily',
                        'source_api' => 'Google Ads API searchStream (FROM campaign, segments.date)',
                        'payload_fields' => ['date', 'campaign_id', 'campaign_name', 'cost', 'impressions', 'clicks', 'ctr', 'conversions_value'],
                        'fact_table_recommendation' => 'fact_google_ads_campaigns',
                    ],
                ],
            ],
            [
                'connector_type' => 'stackadapt',
                'label' => 'StackAdapt',
                'resources' => [
                    [
                        'name' => 'spend_daily',
                        'titan_resource_type' => 'spend_daily',
                        'source_api' => 'StackAdapt GraphQL advertiserDelivery (DAILY)',
                        'payload_fields' => ['date', 'cost', 'impressions', 'clicks', 'ctr', 'conversions', 'conversions_value', 'roas', 'secondary_conversions'],
                        'fact_table_recommendation' => 'fact_stackadapt_spend_daily',
                    ],
                    [
                        'name' => 'campaign_daily',
                        'titan_resource_type' => 'campaign_daily',
                        'source_api' => 'StackAdapt GraphQL campaignDelivery (DAILY)',
                        'payload_fields' => ['date', 'campaign_id', 'campaign_name', 'campaign_group_id', 'campaign_group_name', 'channel_type', 'cost', 'impressions', 'clicks', 'conversions', 'conversions_value'],
                        'fact_table_recommendation' => 'fact_stackadapt_campaigns',
                    ],
                    [
                        'name' => 'channel_daily',
                        'titan_resource_type' => 'channel_daily',
                        'source_api' => 'StackAdapt GraphQL campaignDelivery aggregated by channel_type',
                        'payload_fields' => ['date', 'channel_type', 'cost', 'impressions', 'clicks', 'conversions', 'conversions_value', 'video_starts', 'audio_starts'],
                        'fact_table_recommendation' => 'fact_stackadapt_channels',
                    ],
                    [
                        'name' => 'insight_geo_daily',
                        'titan_resource_type' => 'insight_geo_daily',
                        'source_api' => 'StackAdapt GraphQL campaignInsight (COUNTRY, REGION, DATE)',
                        'payload_fields' => ['date', 'dimension_key', 'dimension_label', 'cost', 'impressions', 'clicks', 'conversions'],
                        'fact_table_recommendation' => 'fact_stackadapt_geo',
                    ],
                    [
                        'name' => 'insight_domain_daily',
                        'titan_resource_type' => 'insight_domain_daily',
                        'source_api' => 'StackAdapt GraphQL campaignInsight (APP, DATE)',
                        'payload_fields' => ['date', 'dimension_key', 'dimension_label', 'cost', 'impressions', 'clicks', 'conversions'],
                        'fact_table_recommendation' => 'fact_stackadapt_domains',
                    ],
                    [
                        'name' => 'insight_device_daily',
                        'titan_resource_type' => 'insight_device_daily',
                        'source_api' => 'StackAdapt GraphQL campaignInsight (DEVICE_TYPE, DATE)',
                        'payload_fields' => ['date', 'dimension_key', 'dimension_label', 'cost', 'impressions', 'clicks', 'conversions'],
                        'fact_table_recommendation' => 'fact_stackadapt_devices',
                    ],
                ],
            ],
            [
                'connector_type' => 'google_analytics',
                'label' => 'Google Analytics 4',
                'resources' => [
                    [
                        'name' => 'traffic_daily',
                        'titan_resource_type' => 'traffic_daily',
                        'source_api' => 'GA4 Data API runReport (dimensions: date)',
                        'payload_fields' => ['date', 'visitors', 'active_users', 'sessions'],
                        'fact_table_recommendation' => 'fact_ga4_traffic_daily',
                    ],
                    [
                        'name' => 'events_daily',
                        'titan_resource_type' => 'events_daily',
                        'source_api' => 'GA4 Data API runReport (dimensions: date, eventName)',
                        'payload_fields' => ['date', 'event_name', 'event_count'],
                        'fact_table_recommendation' => 'fact_ga4_events',
                    ],
                    [
                        'name' => 'landing_pages',
                        'titan_resource_type' => 'landing_page',
                        'source_api' => 'GA4 Data API runReport (dimensions: date, landingPage)',
                        'payload_fields' => ['date', 'landing_page', 'sessions', 'active_users'],
                        'fact_table_recommendation' => 'fact_ga4_landing_pages',
                    ],
                ],
            ],
        ];

        if ($activeTypes === []) {
            return array_values($all);
        }

        return collect($all)
            ->only($activeTypes)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function dataModelingNotes(): array
    {
        return [
            'recommended_star_schema' => [
                'fact_tables' => ['fact_orders', 'fact_sessions', 'fact_ad_spend'],
                'dimension_tables' => ['dim_date', 'dim_connection', 'dim_source_medium', 'dim_customers', 'dim_products'],
                'relationships' => [
                    'fact_orders.connection_id -> dim_connection.id',
                    'fact_orders.date -> dim_date.date',
                    'fact_orders.source_medium -> dim_source_medium.name',
                ],
            ],
            'titan_storage' => [
                'raw_layer' => 'raw_connector_payloads (JSON payloads per resource_type)',
                'aggregated_layer' => 'metric_snapshots (daily metric_key + dimension_hash)',
            ],
            'export_targets' => ['Snowflake', 'BigQuery', 'Redshift', 'PostgreSQL', 'MySQL'],
            'export_note' => 'DDL is not executed in '.config('app.name', 'Atlas').'. Recommend models as documentation; SQL runs against SQLite.',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function tables(): array
    {
        return [
            [
                'name' => 'connections',
                'description' => 'Data source connectors linked to a dashboard.',
                'columns' => [
                    'id', 'client_dashboard_id', 'name', 'connector_type', 'sync_status', 'last_synced_at', 'is_active',
                ],
            ],
            [
                'name' => 'raw_connector_payloads',
                'description' => 'Raw synced records. Commerce orders use resource_type = order.',
                'columns' => [
                    'id', 'connection_id', 'resource_type', 'external_id', 'payload (JSON)', 'fetched_at',
                ],
                'payload_fields' => [
                    'order' => ['date', 'total', 'order_number', 'source', 'medium', 'channel', 'referring_site', 'source_medium', 'currency'],
                    'order_line_item' => ['order_id', 'line_item_id', 'date', 'sku', 'name', 'variant_title', 'quantity', 'unit_price', 'sale_price', 'compare_at_price', 'discount_amount', 'line_total', 'image_url', 'product_id', 'variant_id', 'vendor', 'source', 'medium', 'channel'],
                    'session_attribution' => ['date', 'source', 'medium', 'source_medium', 'sessions', 'visitors'],
                    'ad_spend' => ['date', 'cost', 'source', 'campaign'],
                    'keyword' => ['date', 'position', 'keyword'],
                    'organic_traffic' => ['date', 'sessions', 'source', 'landing_page'],
                ],
                'example' => $this->orderRevenueExampleSql(),
            ],
            [
                'name' => 'metric_snapshots',
                'description' => 'Pre-aggregated daily metrics with optional dimension breakdowns.',
                'columns' => [
                    'id', 'client_dashboard_id', 'snapshot_date', 'metric_key', 'dimension_hash', 'metric_value', 'currency', 'dimensions (JSON)',
                ],
                'metric_keys' => ['revenue', 'orders', 'units_sold', 'line_revenue', 'ad_spend', 'keyword_rank', 'organic_sessions', 'sessions', 'visitors'],
                'example' => 'SELECT snapshot_date AS date, SUM(metric_value) AS revenue FROM metric_snapshots WHERE client_dashboard_id = :dashboard_id AND metric_key = \'revenue\' AND snapshot_date BETWEEN :start_date AND :end_date GROUP BY snapshot_date ORDER BY snapshot_date',
            ],
            [
                'name' => 'sync_runs',
                'description' => 'Sync job history per connection.',
                'columns' => [
                    'id', 'connection_id', 'type', 'status', 'records_fetched', 'records_written', 'started_at', 'finished_at',
                ],
            ],
            [
                'name' => 'metric_definitions',
                'description' => 'Registered KPI definitions with SQL templates.',
                'columns' => [
                    'id', 'client_dashboard_id', 'slug', 'name', 'description', 'sql_template', 'visualization_type', 'visualization_config', 'connector_types',
                ],
            ],
        ];
    }

    protected function orderRevenueExampleSql(): string
    {
        $payload = 'r.payload';
        $date = JsonPayloadSql::text($payload, 'date');
        $total = JsonPayloadSql::real($payload, 'total');

        return "SELECT {$date} AS date, COUNT(*) AS orders, SUM({$total}) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND {$date} BETWEEN :start_date AND :end_date GROUP BY 1 ORDER BY 1";
    }
}
