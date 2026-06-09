<?php

namespace App\Enums;

enum ReportVisualizationType: string
{
    case StatCard = 'stat_card';
    case LineChart = 'line_chart';
    case Table = 'table';

    public function toBlockType(): CoverPageBlockType
    {
        return match ($this) {
            self::StatCard => CoverPageBlockType::StatCard,
            self::LineChart => CoverPageBlockType::LineChart,
            self::Table => CoverPageBlockType::Table,
        };
    }
}
