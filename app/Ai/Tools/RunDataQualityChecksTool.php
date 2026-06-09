<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Services\Analytics\DataQualityService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class RunDataQualityChecksTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected DataQualityService $quality,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Run data quality checks: sync health, missing data days, duplicate records, zero revenue rate, and period-over-period order drops.';
    }

    public function handle(Request $request): Stringable|string
    {
        $start = $request->filled('start_date')
            ? Carbon::parse($request->string('start_date')->toString())->startOfDay()
            : $this->context->previewStartDate;

        $end = $request->filled('end_date')
            ? Carbon::parse($request->string('end_date')->toString())->endOfDay()
            : $this->context->previewEndDate;

        $result = $this->quality->runChecks($this->context->dashboard, $start, $end);

        $this->context->lastQualityReport = $result;

        return $this->json([
            'success' => true,
            ...$result,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'start_date' => $schema->string(),
            'end_date' => $schema->string(),
        ];
    }
}
