<?php

namespace App\Enums;

enum CoverPageBlockType: string
{
    case StatCard = 'stat_card';
    case LineChart = 'line_chart';
    case Table = 'table';
    case RichText = 'rich_text';

    public function label(): string
    {
        return match ($this) {
            self::StatCard => 'Stat card',
            self::LineChart => 'Line chart',
            self::Table => 'Table',
            self::RichText => 'Rich text',
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultConfiguration(): array
    {
        return match ($this) {
            self::StatCard => [
                'header' => '',
                'text' => '',
                'improvement_percent' => null,
                'tooltip' => null,
                'data_source' => 'manual',
                'metric_key' => null,
                'connection_id' => null,
            ],
            self::LineChart => [
                'title' => '',
                'insights' => '',
                'data_source' => 'manual',
                'metric_key' => 'revenue',
                'connection_id' => null,
                'series' => [],
            ],
            self::Table => [
                'title' => '',
                'data_source' => 'manual',
                'metric_key' => null,
                'connection_id' => null,
                'columns' => [],
                'rows' => [],
            ],
            self::RichText => [
                'title' => '',
                'body' => '',
            ],
        };
    }
}
