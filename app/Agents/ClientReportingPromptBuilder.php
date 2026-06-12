<?php

namespace App\Agents;

class ClientReportingPromptBuilder
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
        $isSummary = $this->skillRouter->shouldIncludeSummarySkill($message);

        $skills = [
            $this->sections->dataQuestionSkill(),
            $this->sections->pinSkill(),
        ];

        if ($isSummary) {
            $skills[] = $this->sections->summarySkill();
        }

        if ($this->skillRouter->shouldIncludeSeoSkill($message)) {
            $skills[] = $this->sections->seoSkill();
        }

        if ($this->sections->shouldIncludePaidMediaForContext($context)) {
            $skills[] = $this->sections->paidMediaSkill();
            $skills[] = $this->sections->dataAvailabilitySkill();
        }

        $skillsBlock = implode("\n\n", $skills);
        $persona = $this->sections->clientPersonaTone();
        $visualRules = $this->sections->visualOutputRules();
        $sqlReuse = $this->sections->sqlReuseRules();
        $schemaSummary = $this->sections->schemaSummary($context);
        $examples = $this->sections->visualizationExamplesForContext($context);
        $recentReports = $this->sections->recentSessionReports($context);
        $compareDates = $this->sections->compareDateRange($context);

        $examplesBlock = $examples !== '' ? "\n\n{$examples}" : '';

        $responseGuidance = $isSummary
            ? 'Write the human narrative in your text reply; save the KPI table as the widget. Do not bullet-list metrics in text.'
            : 'Reply with 1–2 sentences of commentary only — never duplicate data in text.';

        $aiName = (string) config('titan.branding.ai_assistant_name', 'TitanAI');
        $productName = (string) config('app.name', 'Atlas');

        return <<<PROMPT
You are {$aiName} — {$productName}'s analytics intelligence assistant for client dashboard users. You help business users understand their data, KPIs, and trends without requiring SQL expertise.

{$persona}

{$visualRules}

{$sqlReuse}
{$examplesBlock}

{$skillsBlock}

## Workflow for data questions
1. Choose visualization_type (table, line_chart, or stat_card).
2. Write parameterized SQL with :start_date and :end_date.
3. {$this->sections->tool('preview_report_query')} → {$this->sections->tool('save_analytics_report')}.
4. {$responseGuidance}
5. Always finish with a brief text reply after your last tool call — never end on a tool call alone.

## SQL rules
- SELECT only. No comments. No semicolons.
- Always scope via :dashboard_id. SQLite json_extract for payloads.
- Never expose raw SQL in your reply.

## Branding (visualization_config)
- stat_card: header, format (currency|number|percent), value_column
- line_chart: title, date_column, value_column, format (currency for revenue)
- table: title, columns [{key, label}]

## Preview date range (bound to :start_date / :end_date)
Start: {$previewStart}
End: {$previewEnd}
{$compareDates}
{$recentReports}

{$schemaSummary}{$this->sections->memoryBlock($context)}
PROMPT;
    }
}
