<?php

namespace App\Services\Analytics;

use App\Models\ConnectorBlueprint;
use Carbon\Carbon;
use Illuminate\Support\Arr;

class BlueprintTransformService
{
    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{date: Carbon, key: string, value: float, dimensions?: array<string, mixed>}>
     */
    public function extractMetrics(ConnectorBlueprint $blueprint, string $resourceType, array $payload, int $connectionId): array
    {
        $config = $blueprint->transform_config ?? [];
        $resourceConfig = $config[$resourceType] ?? null;

        if ($resourceConfig === null) {
            return [];
        }

        $metrics = [];
        $mappings = $resourceConfig['metrics'] ?? $resourceConfig;

        if (! is_array($mappings)) {
            return [];
        }

        foreach ($mappings as $mapping) {
            if (! is_array($mapping) || empty($mapping['key'])) {
                continue;
            }

            $valuePath = $mapping['value_path'] ?? $mapping['field'] ?? null;
            $rawValue = $valuePath !== null ? Arr::get($payload, $valuePath) : ($mapping['value'] ?? null);

            if ($rawValue === null || $rawValue === '') {
                continue;
            }

            $datePath = $mapping['date_path'] ?? 'date';
            $dateValue = Arr::get($payload, $datePath) ?? Arr::get($payload, 'date') ?? now()->toDateString();

            $dimensions = ['connection_id' => $connectionId];

            foreach ($mapping['dimensions'] ?? [] as $dimensionKey => $dimensionPath) {
                if (is_int($dimensionKey)) {
                    $dimensions[$dimensionPath] = Arr::get($payload, $dimensionPath);
                } else {
                    $dimensions[$dimensionKey] = Arr::get($payload, $dimensionPath);
                }
            }

            $metrics[] = [
                'date' => Carbon::parse($dateValue),
                'key' => (string) $mapping['key'],
                'value' => (float) $rawValue,
                'dimensions' => $dimensions,
            ];
        }

        return $metrics;
    }

    /**
     * @return list<string>
     */
    public function allowedResourceTypes(ConnectorBlueprint $blueprint): array
    {
        return $blueprint->resourceTypes();
    }
}
