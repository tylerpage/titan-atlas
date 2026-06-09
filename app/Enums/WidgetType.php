<?php

namespace App\Enums;

enum WidgetType: string
{
    case Revenue = 'revenue';
    case Orders = 'orders';
    case Roas = 'roas';
    case OrganicTraffic = 'organic_traffic';
    case TopKeywords = 'top_keywords';
    case AdSpend = 'ad_spend';
    case Backlinks = 'backlinks';

    public function label(): string
    {
        return match ($this) {
            self::Revenue => 'Revenue',
            self::Orders => 'Orders',
            self::Roas => 'ROAS',
            self::OrganicTraffic => 'Organic Traffic',
            self::TopKeywords => 'Top Keywords',
            self::AdSpend => 'Ad Spend',
            self::Backlinks => 'Backlinks',
        };
    }

    public function defaultConfiguration(): array
    {
        return match ($this) {
            self::Revenue, self::Orders, self::AdSpend, self::OrganicTraffic => [
                'chart' => 'line',
                'date_granularity' => 'day',
            ],
            self::Roas => [
                'chart' => 'single_stat',
            ],
            self::TopKeywords, self::Backlinks => [
                'chart' => 'table',
                'limit' => 10,
            ],
        };
    }
}
