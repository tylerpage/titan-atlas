<?php

namespace App\Agents;

class ReportingPromptBuilder
{
    public function __construct(
        protected TitanAiPromptSections $sections,
        protected PromptSkillRouter $skillRouter,
    ) {}

    public function systemPrompt(ReportingAgentContext $context): string
    {
        $previewStart = $context->previewStartDate->toDateString();
        $previewEnd = $context->previewEndDate->toDateString();
        $message = $context->currentUserMessage;

        $skills = [
            $this->sections->dataQuestionSkill(),
        ];

        if ($this->skillRouter->shouldIncludeMetricSkill($message)) {
            $skills[] = $this->sections->metricSkill();
        }

        if ($this->skillRouter->shouldIncludeQualitySkill($message)) {
            $skills[] = $this->sections->qualitySkill();
        }

        if ($this->skillRouter->shouldIncludeSeoSkill($message)) {
            $skills[] = $this->sections->seoSkill();
        }

        if ($this->skillRouter->shouldIncludeDashboardSpecSkill($message)) {
            $skills[] = $this->sections->dashboardSpecSkill();
        }

        if ($this->skillRouter->shouldIncludeSummarySkill($message)) {
            $skills[] = $this->sections->summarySkill();
        }

        $skills[] = $this->sections->adminCoverPageSkill();

        $skillsBlock = implode("\n\n", $skills);
        $visualRules = $this->sections->visualOutputRules();
        $sqlReuse = $this->sections->sqlReuseRules();
        $schemaSummary = $this->sections->schemaSummary($context);
        $examples = $this->sections->visualizationExamplesForContext($context);
        $recentReports = $this->sections->recentSessionReports($context);
        $compareDates = $this->sections->compareDateRange($context);

        $examplesBlock = $examples !== '' ? "\n\n{$examples}" : '';
        $isSummary = $this->skillRouter->shouldIncludeSummarySkill($message);
        $responseGuidance = $isSummary
            ? 'For summary requests: 2–4 paragraph narrative plus one KPI table widget. No bullet-list metric dumps in text.'
            : 'Brief text commentary only — data renders as dashboard widget.';

        $aiName = (string) config('titan.branding.ai_assistant_name', 'TitanAI');
        $productName = (string) config('app.name', 'Atlas');

        return <<<PROMPT
You are {$aiName} — {$productName}'s analytics intelligence assistant. You bridge source systems, KPIs, SQL, and dashboards for client dashboards.

{$visualRules}

{$sqlReuse}
{$examplesBlock}

{$skillsBlock}

## Workflow for data questions
1. Choose visualization_type. Write SQL with :start_date and :end_date.
2. {$this->sections->tool('preview_report_query')} → {$this->sections->tool('save_analytics_report')}.
3. {$responseGuidance}

## SQL rules
- SELECT only. No comments. No semicolons.
- Scope via :dashboard_id. Optional :compare_start_date, :compare_end_date, :connection_id.
- SQLite json_extract for payloads.

## Branding (visualization_config)
- stat_card: header, format, value_column, optional compare_column
- line_chart: title, date_column, value_column, format
- table: title, columns [{key, label}]

## Preview date range
Start: {$previewStart}
End: {$previewEnd}
{$compareDates}
{$recentReports}

{$schemaSummary}
PROMPT;
    }
}
