<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Agents\ConnectorBuilderAgentContext;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Stringable;

class RevertConnectorDashboardTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected ConnectorBlueprintDashboardVersionService $versions,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Revert the connector dashboard on the current client dashboard to a previous saved version.';
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

        $versionId = $request->integer('version_id');
        $versionNumber = $request->integer('version_number');

        $versionQuery = $this->context->blueprint->dashboardVersions()
            ->where('client_dashboard_id', $this->context->dashboard->id);

        $version = $versionId > 0
            ? $versionQuery->find($versionId)
            : ($versionNumber > 0 ? $versionQuery->where('version_number', $versionNumber)->first() : null);

        if ($version === null) {
            return $this->json([
                'success' => false,
                'error' => 'Provide version_id or version_number for a saved dashboard version on this dashboard.',
            ]);
        }

        try {
            $blueprint = $this->versions->revert(
                $this->context->blueprint,
                $this->context->dashboard,
                $version,
            );
        } catch (ValidationException $e) {
            return $this->json([
                'success' => false,
                'error' => collect($e->errors())->flatten()->first() ?? 'Could not revert dashboard version.',
            ]);
        }

        $this->context->blueprint = $blueprint;
        $this->context->lastDashboardSpec = $blueprint->dashboard_spec;

        return $this->json([
            'success' => true,
            'version_number' => $version->version_number,
            'dashboard_spec' => $blueprint->dashboard_spec,
            'message' => "Reverted to dashboard version {$version->version_number}.",
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'version_id' => $schema->integer(),
            'version_number' => $schema->integer(),
        ];
    }
}
