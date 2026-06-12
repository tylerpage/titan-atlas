<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Enums\ConnectorType;
use App\Models\Connection;
use App\Services\AI\DashboardAgentMemoryService;
use App\Services\Analytics\GoogleAdsDashboardService;
use App\Services\Analytics\RedditAdsDashboardService;
use App\Services\Analytics\StackAdaptDashboardService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class AnalyzeCampaignPerformanceTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected GoogleAdsDashboardService $googleAds,
        protected StackAdaptDashboardService $stackAdapt,
        protected RedditAdsDashboardService $redditAds,
        protected DashboardAgentMemoryService $memories,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Return campaign-level ad performance for Google Ads, StackAdapt, or Reddit Ads using synced payload data. Use this before budget reallocation or ROAS questions.';
    }

    public function handle(Request $request): Stringable|string
    {
        $connection = $this->resolveConnection($request->integer('connection_id') ?: null);

        if ($connection === null) {
            return $this->json([
                'success' => false,
                'error' => 'No active paid media connection found on this dashboard.',
            ]);
        }

        $data = match ($connection->connector_type) {
            ConnectorType::GoogleAds => $this->googleAds->dataFor(
                $this->context->dashboard,
                $connection,
                'custom',
                [
                    'start' => $this->context->previewStartDate->toDateString(),
                    'end' => $this->context->previewEndDate->toDateString(),
                ],
            ),
            ConnectorType::StackAdapt => $this->stackAdapt->dataFor(
                $this->context->dashboard,
                $connection,
                'custom',
                [
                    'start' => $this->context->previewStartDate->toDateString(),
                    'end' => $this->context->previewEndDate->toDateString(),
                ],
            ),
            ConnectorType::RedditAds => $this->redditAds->dataFor(
                $this->context->dashboard,
                $connection,
                'custom',
                [
                    'start' => $this->context->previewStartDate->toDateString(),
                    'end' => $this->context->previewEndDate->toDateString(),
                ],
            ),
            default => null,
        };

        if ($data === null) {
            return $this->json([
                'success' => false,
                'error' => 'AnalyzeCampaignPerformanceTool supports google_ads, stackadapt, and reddit_ads only.',
            ]);
        }

        $campaigns = collect($data['campaigns'] ?? [])
            ->map(function (array $campaign) {
                $cost = (float) ($campaign['cost'] ?? 0);
                $conversionsValue = (float) ($campaign['conversions_value'] ?? 0);

                return [
                    ...$campaign,
                    'roas' => $cost > 0 ? round($conversionsValue / $cost, 4) : 0.0,
                ];
            })
            ->sortByDesc('cost')
            ->values()
            ->all();

        $summary = $data['summary'] ?? [];
        $topRoas = collect($campaigns)->sortByDesc('roas')->take(3)->values()->all();
        $lowRoasHighSpend = collect($campaigns)
            ->filter(fn (array $c) => ($c['cost'] ?? 0) > 0)
            ->sortBy('roas')
            ->take(3)
            ->values()
            ->all();

        $this->memories->remember($this->context->dashboard, [
            'memory_key' => $connection->connector_type->value.':campaign_snapshot',
            'category' => 'workflow',
            'agent_flow' => 'reporting',
            'title' => 'Latest campaign performance snapshot',
            'content' => sprintf(
                'Top spend campaigns: %s. Low ROAS high-spend candidates: %s.',
                collect($campaigns)->take(3)->pluck('campaign_name')->implode(', '),
                collect($lowRoasHighSpend)->pluck('campaign_name')->implode(', '),
            ),
            'source_tool' => self::class,
            'metadata' => [
                'connector_type' => $connection->connector_type->value,
                'connection_id' => $connection->id,
            ],
        ], $this->context->user);

        return $this->json([
            'success' => true,
            'connection_id' => $connection->id,
            'connector_type' => $connection->connector_type->value,
            'summary' => $summary,
            'campaigns' => $campaigns,
            'reallocation_hints' => [
                'cut_candidates' => $lowRoasHighSpend,
                'grow_candidates' => $topRoas,
            ],
            'date_range' => [
                'start' => $this->context->previewStartDate->toDateString(),
                'end' => $this->context->previewEndDate->toDateString(),
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connection_id' => $schema->integer(),
        ];
    }

    protected function resolveConnection(?int $connectionId): ?Connection
    {
        $query = $this->context->dashboard->connections()
            ->where('is_active', true)
            ->whereIn('connector_type', [
                ConnectorType::GoogleAds,
                ConnectorType::StackAdapt,
                ConnectorType::RedditAds,
            ]);

        if ($connectionId) {
            return $query->whereKey($connectionId)->first();
        }

        return $query->orderBy('id')->first();
    }
}
