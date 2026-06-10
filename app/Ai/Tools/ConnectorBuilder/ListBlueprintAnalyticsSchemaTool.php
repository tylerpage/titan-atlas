<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Support\BlueprintAnalyticsSchema;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListBlueprintAnalyticsSchemaTool extends ConnectorBuilderTool
{
    public function description(): Stringable|string
    {
        return 'List blueprint stream resource types, payload fields, SQL placeholders, and example query templates for dashboard widgets.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->context->refreshBlueprint();

        if ($this->context->blueprint === null) {
            return $this->json([
                'success' => false,
                'error' => 'No blueprint exists for this session.',
            ]);
        }

        $connectionId = $this->context->connection?->id
            ?? $this->context->blueprint->connections()->latest()->value('id');

        return $this->json([
            'success' => true,
            'schema' => BlueprintAnalyticsSchema::forBlueprint($this->context->blueprint, $connectionId),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
