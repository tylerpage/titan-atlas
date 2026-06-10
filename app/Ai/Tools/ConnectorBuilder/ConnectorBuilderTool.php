<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Agents\ConnectorBuilderAgentContext;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Stringable;

abstract class ConnectorBuilderTool implements Tool
{
    public function __construct(protected ConnectorBuilderAgentContext $context) {}

    protected function json(mixed $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    abstract public function description(): Stringable|string;

    abstract public function handle(Request $request): Stringable|string;

    abstract public function schema(JsonSchema $schema): array;
}
