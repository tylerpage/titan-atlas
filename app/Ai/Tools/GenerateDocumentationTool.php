<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Models\AnalyticsReport;
use App\Models\SavedDashboard;
use App\Services\Analytics\MetricRegistry;
use App\Support\AnalyticsSchemaCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class GenerateDocumentationTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected MetricRegistry $registry,
        protected AnalyticsSchemaCatalog $catalog,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Generate markdown documentation for a metric, connector, dashboard, or saved report.';
    }

    public function handle(Request $request): Stringable|string
    {
        $subject = $request->string('subject')->toString();
        $identifier = $request->string('identifier')->toString();

        $markdown = match ($subject) {
            'metric' => $this->documentMetric($identifier),
            'connector' => $this->documentConnector($identifier),
            'dashboard' => $this->documentSavedDashboard($identifier),
            'report' => $this->documentReport($identifier),
            default => null,
        };

        if ($markdown === null) {
            return $this->json([
                'success' => false,
                'error' => 'Invalid subject. Use metric, connector, dashboard, or report.',
            ]);
        }

        $this->context->lastDocumentation = [
            'subject' => $subject,
            'identifier' => $identifier,
            'markdown' => $markdown,
        ];

        return $this->json([
            'success' => true,
            'subject' => $subject,
            'identifier' => $identifier,
            'markdown' => $markdown,
        ]);
    }

    protected function documentMetric(string $slug): ?string
    {
        $metric = $this->registry->findForDashboard($this->context->dashboard, $slug);

        if (! $metric) {
            return null;
        }

        $connectors = implode(', ', $metric->connector_types ?? ['all']);

        return <<<MD
# {$metric->name}

**Slug:** `{$metric->slug}`

## Definition

{$metric->description}

## Formula

{$metric->formula_notes}

## SQL Template

```sql
{$metric->sql_template}
```

## Visualization

- Type: {$metric->visualization_type->value}
- Config: {$this->jsonEncode($metric->visualization_config ?? [])}

## Applicable Connectors

{$connectors}
MD;
    }

    protected function documentConnector(string $connectorType): ?string
    {
        $entities = $this->catalog->connectorEntitiesForTypes([$connectorType]);
        $connector = collect($entities)->firstWhere('connector', $connectorType);

        if (! $connector) {
            return null;
        }

        $entityList = collect($connector['entities'] ?? [])
            ->map(function (array $entity) {
                $fields = isset($entity['payload_fields'])
                    ? implode(', ', $entity['payload_fields'])
                    : 'N/A';

                return "- **{$entity['name']}** (resource_type: ".($entity['titan_resource_type'] ?? 'not synced').") — fields: {$fields}";
            })
            ->implode("\n");

        return <<<MD
# {$connectorType} Connector

## Entities

{$entityList}

## Notes

Synced data is stored in `raw_connector_payloads`. Use `json_extract` for payload fields.
MD;
    }

    protected function documentSavedDashboard(string $identifier): ?string
    {
        $board = SavedDashboard::query()
            ->where('client_dashboard_id', $this->context->dashboard->id)
            ->where(function ($query) use ($identifier) {
                $query->where('id', (int) $identifier)
                    ->orWhere('title', 'like', "%{$identifier}%");
            })
            ->withCount('blocks')
            ->first();

        if (! $board) {
            return null;
        }

        return <<<MD
# Saved Dashboard: {$board->title}

{$board->description}

- **Blocks:** {$board->blocks_count}
- **Last updated:** {$board->updated_at?->toDateString()}
MD;
    }

    protected function documentReport(string $identifier): ?string
    {
        $report = AnalyticsReport::query()
            ->where('client_dashboard_id', $this->context->dashboard->id)
            ->whereKey((int) $identifier)
            ->first();

        if (! $report) {
            return null;
        }

        return <<<MD
# Report: {$report->prompt}

## Visualization

Type: {$report->visualization_type->value}

## SQL

```sql
{$report->sql}
```

## Config

{$this->jsonEncode($report->visualization_config ?? [])}
MD;
    }

    protected function jsonEncode(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'subject' => $schema->string()->required(),
            'identifier' => $schema->string()->required(),
        ];
    }
}
