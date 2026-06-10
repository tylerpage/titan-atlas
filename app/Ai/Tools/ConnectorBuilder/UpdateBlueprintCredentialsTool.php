<?php

namespace App\Ai\Tools\ConnectorBuilder;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class UpdateBlueprintCredentialsTool extends ConnectorBuilderTool
{
    public function description(): Stringable|string
    {
        return 'Store credential values collected from the user in the builder session pending state.';
    }

    public function handle(Request $request): Stringable|string
    {
        $credentials = $request->array('credentials');

        if ($credentials === []) {
            return $this->json([
                'success' => false,
                'error' => 'Provide credentials as a key/value object.',
            ]);
        }

        $existing = $this->context->session->pending_credentials ?? [];
        $merged = array_merge($existing, $credentials);

        $this->context->session->update(['pending_credentials' => $merged]);

        return $this->json([
            'success' => true,
            'credential_keys' => array_keys($merged),
            'message' => 'Credentials stored in session. Use TestBlueprintConnectionTool to validate.',
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'credentials' => $schema->object([])->required(),
        ];
    }
}
