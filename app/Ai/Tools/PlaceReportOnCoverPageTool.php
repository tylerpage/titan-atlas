<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Models\AnalyticsReport;
use App\Models\CoverPage;
use App\Services\Admin\AnalyticsReportPlacementService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PlaceReportOnCoverPageTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected AnalyticsReportPlacementService $placement,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Add a saved analytics report as a block on a cover page.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $report = AnalyticsReport::query()
                ->active()
                ->where('client_dashboard_id', $this->context->dashboard->id)
                ->findOrFail($request->integer('report_id'));

            $coverPage = CoverPage::query()
                ->where('client_dashboard_id', $this->context->dashboard->id)
                ->findOrFail($request->integer('cover_page_id'));

            $block = $this->placement->placeOnCoverPage(
                $report,
                $coverPage,
                (int) ($request->integer('column_span') ?: 1),
            );

            return $this->json([
                'success' => true,
                'block_id' => $block->id,
                'cover_page_id' => $coverPage->id,
                'edit_url' => route('admin.cover-pages.edit', $coverPage),
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report_id' => $schema->integer()->required(),
            'cover_page_id' => $schema->integer()->required(),
            'column_span' => $schema->integer()->min(1)->max(2),
        ];
    }
}
