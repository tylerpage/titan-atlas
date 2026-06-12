<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Models\RawConnectorPayload;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Tools\Request;
use Stringable;

class CheckConnectorDataTool extends ReportingTool
{
    public function description(): Stringable|string
    {
        return 'Count synced raw_connector_payload rows by connector and resource_type before claiming data is unavailable.';
    }

    public function handle(Request $request): Stringable|string
    {
        $connectorType = $request->string('connector_type')->toString();
        $resourceType = $request->string('resource_type')->toString();

        $connections = $this->context->dashboard->connections()
            ->where('is_active', true)
            ->when($connectorType !== '', fn ($q) => $q->where('connector_type', $connectorType))
            ->get(['id', 'name', 'connector_type', 'sync_status', 'last_synced_at']);

        if ($connections->isEmpty()) {
            return $this->json([
                'success' => true,
                'connections' => [],
                'message' => 'No matching active connections on this dashboard.',
            ]);
        }

        $counts = [];

        foreach ($connections as $connection) {
            $query = RawConnectorPayload::query()->where('connection_id', $connection->id);

            if ($resourceType !== '') {
                $query->where('resource_type', $resourceType);
            }

            $resourceCounts = $query
                ->select('resource_type', DB::raw('COUNT(*) as row_count'))
                ->groupBy('resource_type')
                ->pluck('row_count', 'resource_type')
                ->all();

            $counts[] = [
                'connection_id' => $connection->id,
                'name' => $connection->name,
                'connector_type' => $connection->connector_type->value,
                'sync_status' => $connection->sync_status->value,
                'last_synced_at' => $connection->last_synced_at?->toDateTimeString(),
                'resource_counts' => $resourceCounts,
                'total_rows' => array_sum($resourceCounts),
            ];
        }

        return $this->json([
            'success' => true,
            'connections' => $counts,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connector_type' => $schema->string(),
            'resource_type' => $schema->string(),
        ];
    }
}
