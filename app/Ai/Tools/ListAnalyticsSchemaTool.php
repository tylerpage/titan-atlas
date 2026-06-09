<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Support\AnalyticsSchemaCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListAnalyticsSchemaTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected AnalyticsSchemaCatalog $catalog,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Returns the analytics database schema, allowed tables, placeholders, and example queries for this dashboard.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->json($this->catalog->forDashboard($this->context->dashboard));
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
