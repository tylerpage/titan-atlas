<?php

namespace App\Agents;

use App\Enums\ConnectorType;
use App\Models\AnalyticsReport;
use App\Support\AnalyticsSchemaCatalog;
use Illuminate\Support\Str;

class TitanAiPromptSections
{
    public function __construct(protected AnalyticsSchemaCatalog $schema) {}

    /**
     * @return array<string, string>
     */
    public static function toolNames(): array
    {
        return [
            'list_analytics_schema' => 'ListAnalyticsSchemaTool',
            'describe_connector_schema' => 'DescribeConnectorSchemaTool',
            'list_metric_definitions' => 'ListMetricDefinitionsTool',
            'explain_metric' => 'ExplainMetricTool',
            'create_metric_definition' => 'CreateMetricDefinitionTool',
            'run_data_quality_checks' => 'RunDataQualityChecksTool',
            'generate_documentation' => 'GenerateDocumentationTool',
            'build_dashboard_spec' => 'BuildDashboardSpecTool',
            'create_analytics_report' => 'CreateAnalyticsReportTool',
            'preview_report_query' => 'PreviewReportQueryTool',
            'save_analytics_report' => 'SaveAnalyticsReportTool',
            'place_report_on_cover_page' => 'PlaceReportOnCoverPageTool',
            'pin_report_to_saved_dashboard' => 'PinReportToSavedDashboardTool',
            'analyze_campaign_performance' => 'AnalyzeCampaignPerformanceTool',
            'check_connector_data' => 'CheckConnectorDataTool',
            'save_dashboard_memory' => 'SaveDashboardMemoryTool',
            'list_dashboard_memories' => 'ListDashboardMemoriesTool',
        ];
    }

    public function tool(string $key): string
    {
        return self::toolNames()[$key];
    }

    public function visualOutputRules(): string
    {
        $create = $this->tool('create_analytics_report');
        $preview = $this->tool('preview_report_query');

        return <<<RULES
## Visual output rules (MANDATORY)
- NEVER format data as markdown tables, ASCII charts, or bullet lists of numbers in your text response.
- For ANY question that returns data (tables, charts, stats, rankings, trends, top N lists), you MUST:
  1. Write SQL using :start_date and :end_date placeholders (never hardcode dates — saved reports reuse the user's date picker).
  2. Call {$create} with the correct visualization_type (validates and saves in one step).
  3. Use {$preview} only when SQL fails and you need to debug before retrying {$create}.
- Your text reply must be 1–2 sentences of commentary only. The dashboard widget renders the data.
- Visualization mapping:
  - User asks for table, list, ranking, top N → visualization_type: table
  - User asks for chart, graph, trend, over time → visualization_type: line_chart
  - User asks for total, single KPI, summary number → visualization_type: stat_card
- When user asks to change format ("show as chart", "put that in a table"), reuse SQL from a saved report in this conversation with a new visualization_type.
RULES;
    }

    public function sqlReuseRules(): string
    {
        return <<<'RULES'
## Parameterized SQL (required for reusable reports)
- ALWAYS filter dates with: json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date
- ALWAYS scope: c.client_dashboard_id = :dashboard_id
- Saved reports re-execute with the chat date picker and on pinned dashboards — hardcoded dates break reuse.
RULES;
    }

    public function schemaSummary(ReportingAgentContext $context): string
    {
        $summary = $this->schema->asCompactPromptSummary($context->dashboard);
        $listSchema = $this->tool('list_analytics_schema');
        $describeConnector = $this->tool('describe_connector_schema');

        return <<<SECTION
## Schema summary
{$summary}
For connector-specific payload fields, call {$describeConnector}. Call {$listSchema} only when you need table columns, sync_runs, or modeling notes not covered above.
SECTION;
    }

    public function dataQuestionSkill(): string
    {
        $preview = $this->tool('preview_report_query');
        $save = $this->tool('save_analytics_report');
        $listSchema = $this->tool('list_analytics_schema');

        return <<<SKILL
## Skill: data questions
- Before writing SQL, call {$listSchema} (or DescribeConnectorSchemaTool for one connector).
- Data questions (table/chart/stat) → {$preview} → {$save} (REQUIRED — preview is mandatory before save).
- Format changes ("show as chart") → reuse SQL from conversation reports → {$save} with new visualization_type.
SKILL;
    }

    public function metricSkill(): string
    {
        $explain = $this->tool('explain_metric');
        $listMetrics = $this->tool('list_metric_definitions');
        $listSchema = $this->tool('list_analytics_schema');

        return <<<SKILL
## Skill: metrics
- Metric or KPI definition questions → {$explain} first (not {$listSchema}).
- List available metrics → {$listMetrics}.
SKILL;
    }

    public function seoSkill(): string
    {
        $describeConnector = $this->tool('describe_connector_schema');

        return <<<SKILL
## Skill: SEO, Search Console & GA4
- Search Console data lives in raw_connector_payloads: resource_type keyword (queries), search_daily (site totals), search_page (landing URLs), search_device (device breakdown).
- Key payload fields: clicks, impressions, ctr, position, keyword, page, device, date.
- Position is average rank (lower is better). CTR is a ratio (0–1) in payloads.
- Query-level analysis → resource_type = 'keyword'. Landing-page analysis → resource_type = 'search_page'. Device breakdown → resource_type = 'search_device'. Trend charts → search_daily.
- GA4 data: resource_type traffic_daily (visitors, active_users, sessions), events_daily (event_name, event_count), landing_page (landing_page, sessions). Traffic trends → traffic_daily. Event analysis → events_daily.
- For connector field details call {$describeConnector} with connector search_console or google_analytics.
SKILL;
    }

    public function paidMediaSkill(): string
    {
        $analyze = $this->tool('analyze_campaign_performance');
        $check = $this->tool('check_connector_data');
        $create = $this->tool('create_analytics_report');
        $describeConnector = $this->tool('describe_connector_schema');

        return <<<SKILL
## Skill: paid media (Google Ads, StackAdapt, Reddit Ads)
- Campaign / budget / ROAS questions → call {$analyze} FIRST with the active ad connection.
- Before saying data is unavailable, call {$check} for resource_type campaign_daily (or spend_daily).
- Account spend trends → resource_type spend_daily. Campaign analysis → resource_type campaign_daily (NOT spend_daily).
- Fields: campaign_id, campaign_name, cost, impressions, clicks, ctr, conversions_value (and conversions for StackAdapt).
- Filter ads SQL with c.connector_type IN ('google_ads', 'stackadapt', 'reddit_ads') when multiple ad connectors exist.
- ROAS = conversions_value / NULLIF(cost, 0). Rank campaigns by ROAS and spend for budget reallocation advice.
- High spend + low ROAS → cut candidates. Top ROAS with room to grow → receive candidates.
- Projections: extrapolate from recent daily trends only — state clearly this is not a guaranteed forecast.
- After analysis, save a table widget with {$create} when the user needs a visual.
- For field details call {$describeConnector} with connector_type google_ads, stackadapt, or reddit_ads.
SKILL;
    }

    public function dataAvailabilitySkill(): string
    {
        $check = $this->tool('check_connector_data');

        return <<<SKILL
## Data availability (mandatory)
- Never claim connector data is missing or broken without calling {$check} or AnalyzeCampaignPerformanceTool first.
- If row counts are zero, explain the connection may still be syncing — do not blame "campaign ID structure".
SKILL;
    }

    public function qualitySkill(): string
    {
        $quality = $this->tool('run_data_quality_checks');

        return <<<SKILL
## Skill: data quality
- Sync issues, stale data, missing rows, or anomalies → {$quality}.
SKILL;
    }

    public function dashboardSpecSkill(): string
    {
        $build = $this->tool('build_dashboard_spec');

        return <<<SKILL
## Skill: multi-widget dashboards
- Multiple widgets or board layout requests → {$build}.
SKILL;
    }

    public function pinSkill(): string
    {
        $pin = $this->tool('pin_report_to_saved_dashboard');

        return <<<SKILL
## Skill: pin to saved dashboard
- When asked to save or pin a report to a shared board → {$pin} after {$this->tool('save_analytics_report')}.
SKILL;
    }

    public function clientPersonaTone(): string
    {
        return <<<'TONE'
## Voice & tone (client mode)
- Write like a trusted analytics partner: warm, clear, and confident — not a database report.
- Use plain business language. Avoid robotic openers ("the analytics summary reveals", "key metrics include").
- When you include numbers in prose, round sensibly ($2.1M not $2,091,455.10) unless precision matters.
TONE;
    }

    public function summarySkill(): string
    {
        $preview = $this->tool('preview_report_query');
        $save = $this->tool('save_analytics_report');

        return <<<SKILL
## Skill: period summaries & client briefs (OVERRIDES the 1–2 sentence text limit)
When the user asks for a summary, overview, recap, brief, or write-up:
1. {$preview} → {$save} ONE KPI table (visualization_type: table) — NOT a single stat_card.
   - SQL returns one row per headline metric (columns: metric, value) covering revenue, orders, AOV, sessions/visitors, and other available KPIs for the period.
   - config example: { "title": "Period at a glance", "columns": [{"key":"metric","label":"Metric"},{"key":"value","label":"Value"}] }
2. Text reply: 2–4 short paragraphs of client-ready narrative.
   - Lead with the headline story — how did the period go?
   - Weave 3–5 key figures naturally into sentences; do NOT bullet-list metrics or repeat every value from the table.
   - End with one useful observation (trend, concentration, or what to watch next).
3. The table widget is the shareable/pinnable data artifact; your text is the human interpretation.
SKILL;
    }

    public function adminCoverPageSkill(): string
    {
        $place = $this->tool('place_report_on_cover_page');

        return <<<SKILL
## Skill: cover page
- Cover page placement → {$place}.
SKILL;
    }

    public function visualizationExamples(): string
    {
        return <<<'EXAMPLES'
## Example patterns
Top N days table:
SELECT json_extract(r.payload, '$.date') AS date, SUM(CAST(json_extract(r.payload, '$.total') AS REAL)) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date GROUP BY 1 ORDER BY revenue DESC LIMIT 5
→ visualization_type: table, config: { "title": "Top sales days", "columns": [{"key":"date","label":"Date"},{"key":"revenue","label":"Revenue"}] }

Daily revenue chart:
SELECT json_extract(r.payload, '$.date') AS date, SUM(CAST(json_extract(r.payload, '$.total') AS REAL)) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date GROUP BY 1 ORDER BY 1
→ visualization_type: line_chart, config: { "title": "Daily revenue", "date_column": "date", "value_column": "revenue", "format": "currency" }
EXAMPLES;
    }

    public function paidMediaExamples(): string
    {
        return <<<'EXAMPLES'
## Paid media SQL patterns
Campaign performance table:
SELECT json_extract(r.payload, '$.campaign_name') AS campaign_name, json_extract(r.payload, '$.campaign_id') AS campaign_id, SUM(CAST(json_extract(r.payload, '$.cost') AS REAL)) AS cost, SUM(CAST(json_extract(r.payload, '$.conversions_value') AS REAL)) AS conversions_value, CASE WHEN SUM(CAST(json_extract(r.payload, '$.cost') AS REAL)) = 0 THEN 0 ELSE SUM(CAST(json_extract(r.payload, '$.conversions_value') AS REAL)) / SUM(CAST(json_extract(r.payload, '$.cost') AS REAL)) END AS roas FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND c.connector_type = 'google_ads' AND r.resource_type = 'campaign_daily' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date GROUP BY 1, 2 ORDER BY cost DESC
→ visualization_type: table, config: { "title": "Campaign performance", "columns": [{"key":"campaign_name","label":"Campaign"},{"key":"cost","label":"Spend"},{"key":"conversions_value","label":"Conv. value"},{"key":"roas","label":"ROAS"}] }
EXAMPLES;
    }

    public function dashboardHasPaidMediaConnection(ReportingAgentContext $context): bool
    {
        return $context->dashboard->connections()
            ->where('is_active', true)
            ->whereIn('connector_type', [
                ConnectorType::GoogleAds,
                ConnectorType::StackAdapt,
                ConnectorType::RedditAds,
            ])
            ->exists();
    }

    public function shouldIncludePaidMediaForContext(ReportingAgentContext $context): bool
    {
        return $this->dashboardHasPaidMediaConnection($context)
            || app(PromptSkillRouter::class)->shouldIncludePaidMediaSkill($context->currentUserMessage);
    }

    public function memoryBlock(ReportingAgentContext $context, string $flow = 'reporting'): string
    {
        $block = app(\App\Services\AI\DashboardAgentMemoryService::class)
            ->forPrompt($context->dashboard, $flow);

        return $block !== '' ? "\n\n{$block}" : '';
    }

    public function visualizationExamplesForContext(ReportingAgentContext $context): string
    {
        if ($this->sessionHasReports($context)) {
            return '';
        }

        $examples = $this->visualizationExamples();

        if ($this->shouldIncludePaidMediaForContext($context)) {
            $examples .= "\n\n".$this->paidMediaExamples();
        }

        return $examples;
    }

    public function recentSessionReports(ReportingAgentContext $context): string
    {
        $reports = AnalyticsReport::query()
            ->active()
            ->where('analytics_report_session_id', $context->session->id)
            ->orderByDesc('id')
            ->limit(3)
            ->get(['id', 'prompt', 'visualization_type']);

        if ($reports->isEmpty()) {
            return '';
        }

        $lines = $reports->map(function (AnalyticsReport $report) {
            return sprintf(
                '- #%d (%s) "%s"',
                $report->id,
                $report->visualization_type->value,
                Str::limit($report->prompt, 50),
            );
        })->implode("\n");

        return <<<SECTION

## Reports saved in this conversation
{$lines}
Reuse the same SQL when converting visualization type (e.g. table → line_chart). Call {$this->tool('list_analytics_schema')} or prior tool results if you need the SQL text.
SECTION;
    }

    public function compareDateRange(ReportingAgentContext $context): string
    {
        if ($context->previewCompareStartDate === null || $context->previewCompareEndDate === null) {
            return '';
        }

        $compareStart = $context->previewCompareStartDate->toDateString();
        $compareEnd = $context->previewCompareEndDate->toDateString();

        return <<<SECTION

## Comparison date range
Compare start: {$compareStart}
Compare end: {$compareEnd}
Use :compare_start_date and :compare_end_date in SQL when the user requests period-over-period comparison.
SECTION;
    }

    public function sessionHasReports(ReportingAgentContext $context): bool
    {
        return AnalyticsReport::query()
            ->active()
            ->where('analytics_report_session_id', $context->session->id)
            ->exists();
    }
}
