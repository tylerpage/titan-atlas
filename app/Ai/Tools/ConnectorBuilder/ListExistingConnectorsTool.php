<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Enums\ConnectorType;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListExistingConnectorsTool extends ConnectorBuilderTool
{
    public function description(): Stringable|string
    {
        return 'List built-in connector types already implemented in Titan. Prefer creating a dynamic blueprint for new integrations.';
    }

    public function handle(Request $request): Stringable|string
    {
        $connectors = collect(ConnectorType::cases())
            ->reject(fn (ConnectorType $type) => $type === ConnectorType::Dynamic)
            ->map(fn (ConnectorType $type) => [
                'value' => $type->value,
                'label' => $type->label(),
                'supports_test' => $type->supportsLiveConnectionTest(),
                'uses_google_oauth' => $type->usesGoogleOAuth(),
            ])
            ->values()
            ->all();

        return $this->json([
            'success' => true,
            'connectors' => $connectors,
            'note' => 'Use dynamic blueprints for integrations not in this list.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
