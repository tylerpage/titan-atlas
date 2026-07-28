<?php

namespace App\Services\Analytics;

use App\Enums\ReportVisualizationType;
use App\Support\JsonPayloadSql;
use App\Models\ClientDashboard;
use App\Models\MetricDefinition;
use App\Models\User;
use Illuminate\Support\Collection;

class MetricRegistry
{
    /**
     * @return Collection<int, MetricDefinition>
     */
    public function forDashboard(ClientDashboard $dashboard): Collection
    {
        $this->ensureBuiltins();

        $custom = MetricDefinition::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->orderBy('name')
            ->get();

        $builtins = MetricDefinition::query()
            ->whereNull('client_dashboard_id')
            ->where('is_builtin', true)
            ->orderBy('name')
            ->get();

        return $builtins
            ->keyBy('slug')
            ->merge($custom->keyBy('slug'))
            ->values();
    }

    public function findForDashboard(ClientDashboard $dashboard, string $slug): ?MetricDefinition
    {
        $this->ensureBuiltins();

        return MetricDefinition::query()
            ->where('slug', $slug)
            ->where(function ($query) use ($dashboard) {
                $query->whereNull('client_dashboard_id')
                    ->orWhere('client_dashboard_id', $dashboard->id);
            })
            ->orderByRaw('client_dashboard_id IS NULL ASC')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    public function explain(MetricDefinition $metric): array
    {
        return [
            'id' => $metric->id,
            'slug' => $metric->slug,
            'name' => $metric->name,
            'description' => $metric->description,
            'formula_notes' => $metric->formula_notes,
            'sql_template' => $metric->sql_template,
            'visualization_type' => $metric->visualization_type->value,
            'visualization_config' => $metric->visualization_config ?? [],
            'connector_types' => $metric->connector_types ?? [],
            'is_builtin' => $metric->is_builtin,
            'scope' => $metric->client_dashboard_id ? 'dashboard' : 'platform',
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ClientDashboard $dashboard, User $user, array $data): MetricDefinition
    {
        return MetricDefinition::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'slug' => $data['slug'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'formula_notes' => $data['formula_notes'] ?? null,
            'sql_template' => $data['sql_template'],
            'visualization_type' => $data['visualization_type'],
            'visualization_config' => $data['visualization_config'] ?? [],
            'connector_types' => $data['connector_types'] ?? [],
            'created_by' => $user->id,
            'is_builtin' => false,
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function summaryForPrompt(ClientDashboard $dashboard): array
    {
        return $this->forDashboard($dashboard)
            ->map(fn (MetricDefinition $metric) => [
                'slug' => $metric->slug,
                'name' => $metric->name,
                'description' => $metric->description,
                'visualization_type' => $metric->visualization_type->value,
                'connector_types' => $metric->connector_types ?? [],
            ])
            ->all();
    }

    public function ensureBuiltins(): void
    {
        foreach ($this->builtinDefinitions() as $definition) {
            $definition['sql_template'] = JsonPayloadSql::normalizeSql($definition['sql_template']);

            MetricDefinition::query()->updateOrCreate(
                [
                    'slug' => $definition['slug'],
                    'client_dashboard_id' => null,
                    'is_builtin' => true,
                ],
                $definition,
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function builtinDefinitions(): array
    {
        $orderFilter = "c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date";

        return [
            [
                'client_dashboard_id' => null,
                'slug' => 'revenue',
                'name' => 'Gross Revenue',
                'description' => 'Total order revenue in the selected period.',
                'formula_notes' => 'SUM of order totals from synced commerce orders.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE {$orderFilter}",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Revenue', 'format' => 'currency', 'value_column' => 'revenue'],
                'connector_types' => ['shopify', 'bigcommerce'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'orders',
                'name' => 'Orders',
                'description' => 'Total number of orders in the selected period.',
                'formula_notes' => 'COUNT of order records.',
                'sql_template' => "SELECT COUNT(*) AS orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE {$orderFilter}",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Orders', 'format' => 'number', 'value_column' => 'orders'],
                'connector_types' => ['shopify', 'bigcommerce'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'avg_order_value',
                'name' => 'Average Order Value',
                'description' => 'Total revenue divided by total orders.',
                'formula_notes' => 'AOV = revenue / orders. Returns 0 when no orders.',
                'sql_template' => "SELECT CASE WHEN COUNT(*) = 0 THEN 0 ELSE COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) / COUNT(*) END AS avg_order_value FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE {$orderFilter}",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'AOV', 'format' => 'currency', 'value_column' => 'avg_order_value'],
                'connector_types' => ['shopify', 'bigcommerce'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'sessions',
                'name' => 'Sessions',
                'description' => 'Total web sessions from Shopify session attribution data.',
                'formula_notes' => 'Requires Shopify connector with read_reports scope and session_attribution payloads.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.sessions') AS REAL)), 0) AS sessions FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'session_attribution' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Sessions', 'format' => 'number', 'value_column' => 'sessions'],
                'connector_types' => ['shopify'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'visitors',
                'name' => 'Visitors',
                'description' => 'Total unique visitors from Shopify session attribution data.',
                'formula_notes' => 'Requires Shopify connector with session_attribution payloads.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.visitors') AS REAL)), 0) AS visitors FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'session_attribution' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Visitors', 'format' => 'number', 'value_column' => 'visitors'],
                'connector_types' => ['shopify'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ad_spend',
                'name' => 'Ad Spend',
                'description' => 'Total advertising spend from synced ad data.',
                'formula_notes' => 'SUM of cost from spend_daily payloads.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.cost') AS REAL)), 0) AS ad_spend FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type IN ('google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads') AND r.resource_type = 'spend_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Ad Spend', 'format' => 'currency', 'value_column' => 'ad_spend'],
                'connector_types' => ['google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ads_impressions',
                'name' => 'Ad Impressions',
                'description' => 'Total ad impressions from spend_daily payloads.',
                'formula_notes' => 'SUM of impressions from spend_daily resource_type rows.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.impressions') AS REAL)), 0) AS ads_impressions FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type IN ('google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads') AND r.resource_type = 'spend_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Impressions', 'format' => 'number', 'value_column' => 'ads_impressions'],
                'connector_types' => ['google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ads_clicks',
                'name' => 'Ad Clicks',
                'description' => 'Total ad clicks from spend_daily payloads.',
                'formula_notes' => 'SUM of clicks from spend_daily resource_type rows.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.clicks') AS REAL)), 0) AS ads_clicks FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type IN ('google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads') AND r.resource_type = 'spend_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Clicks', 'format' => 'number', 'value_column' => 'ads_clicks'],
                'connector_types' => ['google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ads_conversions_value',
                'name' => 'All Conv. Value',
                'description' => 'Total ad conversion value from spend_daily payloads.',
                'formula_notes' => 'SUM of conversions_value from spend_daily resource_type rows.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.conversions_value') AS REAL)), 0) AS ads_conversions_value FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type IN ('google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads') AND r.resource_type = 'spend_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'All Conv. Value', 'format' => 'currency', 'value_column' => 'ads_conversions_value'],
                'connector_types' => ['google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ads_conversions',
                'name' => 'Ad Conversions',
                'description' => 'Total ad conversions from spend_daily payloads.',
                'formula_notes' => 'SUM of conversions from spend_daily resource_type rows.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.conversions') AS REAL)), 0) AS ads_conversions FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type IN ('google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads') AND r.resource_type = 'spend_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Conversions', 'format' => 'number', 'value_column' => 'ads_conversions'],
                'connector_types' => ['stackadapt', 'meta_ads'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ads_roas',
                'name' => 'Ad ROAS',
                'description' => 'Return on ad spend from spend_daily payloads.',
                'formula_notes' => 'SUM(conversions_value) / SUM(cost) from spend_daily rows.',
                'sql_template' => "SELECT CASE WHEN COALESCE(SUM(CAST(json_extract(r.payload, '$.cost') AS REAL)), 0) = 0 THEN 0 ELSE COALESCE(SUM(CAST(json_extract(r.payload, '$.conversions_value') AS REAL)), 0) / COALESCE(SUM(CAST(json_extract(r.payload, '$.cost') AS REAL)), 0) END AS ads_roas FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type IN ('google_ads', 'stackadapt', 'meta_ads', 'reddit_ads', 'amazon_ads', 'walmart_connect', 'ebay_ads') AND r.resource_type = 'spend_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'ROAS', 'format' => 'number', 'value_column' => 'ads_roas'],
                'connector_types' => ['stackadapt', 'meta_ads'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'repeat_customers',
                'name' => 'Repeat Channel Orders',
                'description' => 'Orders from source/medium combinations with 2+ orders in the period.',
                'formula_notes' => 'Proxy for repeat purchase behavior. True customer-level repeat rate requires customer_id in order payloads.',
                'sql_template' => "SELECT COALESCE(SUM(cnt), 0) AS repeat_channel_orders FROM (SELECT COUNT(*) AS cnt FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE {$orderFilter} GROUP BY json_extract(r.payload, '$.source_medium') HAVING COUNT(*) > 1)",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Repeat Channel Orders', 'format' => 'number', 'value_column' => 'repeat_channel_orders'],
                'connector_types' => ['shopify', 'bigcommerce'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'ga4_visitors',
                'name' => 'GA4 Visitors',
                'description' => 'Total users from GA4 traffic_daily payloads.',
                'formula_notes' => 'SUM of visitors from traffic_daily resource_type rows.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.visitors') AS REAL)), 0) AS ga4_visitors FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'google_analytics' AND r.resource_type = 'traffic_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Visitors', 'format' => 'number', 'value_column' => 'ga4_visitors'],
                'connector_types' => ['google_analytics'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'active_users',
                'name' => 'Active Users',
                'description' => 'Active users from GA4 traffic_daily payloads.',
                'formula_notes' => 'SUM of active_users from traffic_daily resource_type rows.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.active_users') AS REAL)), 0) AS active_users FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'google_analytics' AND r.resource_type = 'traffic_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Active Users', 'format' => 'number', 'value_column' => 'active_users'],
                'connector_types' => ['google_analytics'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'organic_sessions',
                'name' => 'Organic Sessions',
                'description' => 'Organic sessions from GA4 organic_traffic payloads.',
                'formula_notes' => 'SUM of sessions from organic_traffic payloads.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.sessions') AS REAL)), 0) AS organic_sessions FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'google_analytics' AND r.resource_type = 'organic_traffic' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Organic Sessions', 'format' => 'number', 'value_column' => 'organic_sessions'],
                'connector_types' => ['google_analytics'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'event_count',
                'name' => 'GA4 Event Count',
                'description' => 'Total GA4 event counts from events_daily payloads.',
                'formula_notes' => 'SUM of event_count grouped by event_name.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.event_count') AS REAL)), 0) AS event_count FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'google_analytics' AND r.resource_type = 'events_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Events', 'format' => 'number', 'value_column' => 'event_count'],
                'connector_types' => ['google_analytics'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'search_clicks',
                'name' => 'Organic Search Clicks',
                'description' => 'Total clicks from Google Search Console for the selected period.',
                'formula_notes' => 'SUM of clicks from search_daily payloads (site-level totals).',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.clicks') AS REAL)), 0) AS search_clicks FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'search_console' AND r.resource_type = 'search_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Search Clicks', 'format' => 'number', 'value_column' => 'search_clicks'],
                'connector_types' => ['search_console'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'search_impressions',
                'name' => 'Search Impressions',
                'description' => 'Total search impressions from Google Search Console.',
                'formula_notes' => 'SUM of impressions from search_daily payloads.',
                'sql_template' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.impressions') AS REAL)), 0) AS search_impressions FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'search_console' AND r.resource_type = 'search_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Impressions', 'format' => 'number', 'value_column' => 'search_impressions'],
                'connector_types' => ['search_console'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'search_ctr',
                'name' => 'Average Search CTR',
                'description' => 'Weighted average click-through rate from Search Console daily totals.',
                'formula_notes' => 'CTR = total clicks / total impressions. Returns 0 when impressions are 0.',
                'sql_template' => "SELECT CASE WHEN COALESCE(SUM(CAST(json_extract(r.payload, '$.impressions') AS REAL)), 0) = 0 THEN 0 ELSE COALESCE(SUM(CAST(json_extract(r.payload, '$.clicks') AS REAL)), 0) / SUM(CAST(json_extract(r.payload, '$.impressions') AS REAL)) END AS search_ctr FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'search_console' AND r.resource_type = 'search_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Avg CTR', 'format' => 'percent', 'value_column' => 'search_ctr'],
                'connector_types' => ['search_console'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'avg_search_position',
                'name' => 'Average Search Position',
                'description' => 'Average ranking position from Search Console daily site totals.',
                'formula_notes' => 'Uses position field from search_daily rows (lower is better).',
                'sql_template' => "SELECT COALESCE(AVG(CAST(json_extract(r.payload, '$.position') AS REAL)), 0) AS avg_search_position FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'search_console' AND r.resource_type = 'search_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date",
                'visualization_type' => ReportVisualizationType::StatCard,
                'visualization_config' => ['header' => 'Avg Position', 'format' => 'number', 'value_column' => 'avg_search_position'],
                'connector_types' => ['search_console'],
                'is_builtin' => true,
            ],
            [
                'client_dashboard_id' => null,
                'slug' => 'top_search_queries',
                'name' => 'Top Search Queries',
                'description' => 'Top queries by clicks from Search Console keyword payloads.',
                'formula_notes' => 'Groups keyword resource_type rows by query; orders by clicks descending.',
                'sql_template' => "SELECT json_extract(r.payload, '$.keyword') AS query, SUM(CAST(json_extract(r.payload, '$.clicks') AS REAL)) AS clicks, SUM(CAST(json_extract(r.payload, '$.impressions') AS REAL)) AS impressions, AVG(CAST(json_extract(r.payload, '$.position') AS REAL)) AS avg_position FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'search_console' AND r.resource_type = 'keyword' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date GROUP BY 1 ORDER BY clicks DESC LIMIT 10",
                'visualization_type' => ReportVisualizationType::Table,
                'visualization_config' => [
                    'title' => 'Top search queries',
                    'columns' => [
                        ['key' => 'query', 'label' => 'Query'],
                        ['key' => 'clicks', 'label' => 'Clicks'],
                        ['key' => 'impressions', 'label' => 'Impressions'],
                        ['key' => 'avg_position', 'label' => 'Avg position'],
                    ],
                ],
                'connector_types' => ['search_console'],
                'is_builtin' => true,
            ],
        ];
    }
}
