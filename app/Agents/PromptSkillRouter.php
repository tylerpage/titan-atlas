<?php

namespace App\Agents;

class PromptSkillRouter
{
    /**
     * @param  list<string>  $keywords
     */
    protected function messageMatches(?string $message, array $keywords): bool
    {
        if ($message === null || trim($message) === '') {
            return false;
        }

        $normalized = strtolower($message);

        foreach ($keywords as $keyword) {
            if (str_contains($normalized, strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    public function shouldIncludeMetricSkill(?string $message): bool
    {
        return $this->messageMatches($message, [
            'kpi',
            'metric',
            'roas',
            'revenue definition',
            'define revenue',
            'what is revenue',
            'what does',
            'metric definition',
            'how is',
            'calculated',
        ]);
    }

    public function shouldIncludeQualitySkill(?string $message): bool
    {
        return $this->messageMatches($message, [
            'data quality',
            'sync',
            'stale',
            'missing data',
            'out of date',
            'freshness',
            'anomaly',
            'quality check',
            'data issue',
        ]);
    }

    public function shouldIncludeDashboardSpecSkill(?string $message): bool
    {
        return $this->messageMatches($message, [
            'dashboard',
            'board',
            'multiple widgets',
            'multi-widget',
            'several charts',
            'layout',
            'build a board',
        ]);
    }

    public function shouldIncludeSeoSkill(?string $message): bool
    {
        return $this->messageMatches($message, [
            'seo',
            'search console',
            'google search',
            'organic search',
            'ranking',
            'rankings',
            'impressions',
            'search query',
            'search queries',
            'keyword',
            'keywords',
            'ctr',
            'click-through',
            'landing page',
            'serp',
        ]);
    }

    public function shouldIncludeSummarySkill(?string $message): bool
    {
        return $this->messageMatches($message, [
            'summary',
            'overview',
            'recap',
            'brief',
            'write-up',
            'write up',
            'how did we do',
            'how are we doing',
            'client summary',
            'executive',
            'highlights',
            'performance review',
            'tell me about',
            'what happened',
            'period review',
        ]);
    }
}
