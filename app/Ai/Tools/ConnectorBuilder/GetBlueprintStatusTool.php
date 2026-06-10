<?php

namespace App\Ai\Tools\ConnectorBuilder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GetBlueprintStatusTool extends ConnectorBuilderTool
{
    public function description(): Stringable|string
    {
        return 'Return the current blueprint, connection sync status, pending credentials keys, and blockers.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->context->refreshBlueprint();
        $blueprint = $this->context->blueprint;

        if ($blueprint === null) {
            return $this->json([
                'success' => true,
                'has_blueprint' => false,
                'pending_credentials' => array_keys($this->context->session->pending_credentials ?? []),
            ]);
        }

        $connection = $blueprint->connections()->latest()->first();

        return $this->json([
            'success' => true,
            'has_blueprint' => true,
            'blueprint' => [
                'id' => $blueprint->id,
                'slug' => $blueprint->slug,
                'label' => $blueprint->label,
                'status' => $blueprint->status->value,
                'stream_count' => $blueprint->streams->count(),
                'streams' => $blueprint->streams->map(fn ($stream) => [
                    'stream_key' => $stream->stream_key,
                    'resource_type' => $stream->resource_type,
                    'path_template' => $stream->path_template,
                    'enabled' => $stream->enabled,
                ])->values()->all(),
                'credential_schema' => $blueprint->credential_schema,
                'dev_tasks' => $blueprint->dev_tasks,
                'dashboard_spec' => $blueprint->dashboard_spec,
            ],
            'connection' => $connection ? [
                'id' => $connection->id,
                'name' => $connection->name,
                'sync_status' => $connection->sync_status->value,
                'sync_error' => $connection->sync_error,
                'last_synced_at' => $connection->last_synced_at?->toIso8601String(),
            ] : null,
            'pending_credentials' => array_keys($this->context->session->pending_credentials ?? []),
            'last_test_result' => $this->context->lastTestResult,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
