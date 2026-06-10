<?php

namespace App\Ingestion\Connectors\StackAdapt;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class StackAdaptGraphqlClient
{
    public const METRICS_FRAGMENT = <<<'GQL'
        cost
        impressionsBigint
        clicksBigint
        ctr
        conversionsBigint
        conversionRevenue
        roas
        secondaryConversionsBigint
        engagementRate
        videoStarts
        videoCompletions
        audioStarts
        audioCompletions
GQL;

    public function listAdvertisers(string $apiKey, int $first = 100): array
    {
        $query = <<<'GQL'
            query StackAdaptAdvertisers($first: Int) {
                advertisers(first: $first) {
                    nodes {
                        id
                        name
                    }
                }
            }
        GQL;

        $data = $this->request($apiKey, $query, ['first' => $first]);
        $nodes = $data['advertisers']['nodes'] ?? [];

        if (! is_array($nodes)) {
            return [];
        }

        $advertisers = [];

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $id = (string) ($node['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $advertisers[] = [
                'advertiserId' => $id,
                'displayName' => (string) ($node['name'] ?? $id),
                'pickerLabel' => trim((string) ($node['name'] ?? $id).' — '.$id),
            ];
        }

        usort($advertisers, fn (array $left, array $right) => strcasecmp($left['displayName'], $right['displayName']));

        return $advertisers;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function advertiserDeliveryRecords(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        ?string $after = null,
    ): array {
        return $this->advertiserDeliveryProbe($apiKey, $advertiserId, $fromDate, $toDate, $after)['records'];
    }

    /**
     * @return array{
     *     typename: string|null,
     *     record_count: int,
     *     records: list<array<string, mixed>>
     * }
     */
    public function advertiserDeliveryProbe(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        ?string $after = null,
    ): array {
        $query = <<<GQL
            query StackAdaptAdvertiserDelivery(
                \$ids: [ID!]!
                \$date: DateRangeInput
                \$first: Int
                \$after: String
            ) {
                advertiserDelivery(
                    dataType: TABLE
                    granularity: DAILY
                    ids: \$ids
                    date: \$date
                ) {
                    __typename
                    ... on AdvertiserDeliveryOutcome {
                        records(first: \$first, after: \$after) {
                            nodes {
                                granularity {
                                    time
                                    startTime
                                    endTime
                                }
                                metrics {
                                    {$this->metricsSelection()}
                                }
                            }
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                        }
                    }
                }
            }
        GQL;

        $data = $this->request($apiKey, $query, [
            'ids' => [$advertiserId],
            'date' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'first' => max(1, (int) config('titan.stackadapt.page_size', 250)),
            'after' => $after,
        ]);

        $payload = $data['advertiserDelivery'] ?? null;
        $typename = is_array($payload) ? (string) ($payload['__typename'] ?? '') : '';
        $records = $this->unwrapDeliveryRecords($payload);

        return [
            'typename' => $typename !== '' ? $typename : null,
            'record_count' => count($records),
            'records' => $records,
        ];
    }

    /**
     * @return array{records: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function campaignDeliveryPage(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        ?string $after = null,
    ): array {
        $query = <<<GQL
            query StackAdaptCampaignDelivery(
                \$filterBy: CampaignFilters
                \$date: DateRangeInput
                \$first: Int
                \$after: String
            ) {
                campaignDelivery(
                    dataType: TABLE
                    granularity: DAILY
                    filterBy: \$filterBy
                    date: \$date
                ) {
                    __typename
                    ... on CampaignDeliveryOutcome {
                        records(first: \$first, after: \$after) {
                            nodes {
                                granularity {
                                    time
                                    startTime
                                    endTime
                                }
                                campaign {
                                    id
                                    name
                                    channelType
                                    campaignGroup {
                                        id
                                        name
                                    }
                                }
                                metrics {
                                    {$this->metricsSelection()}
                                }
                            }
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                        }
                    }
                }
            }
        GQL;

        $data = $this->request($apiKey, $query, [
            'filterBy' => [
                'advertiserIds' => [$advertiserId],
            ],
            'date' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'first' => max(1, (int) config('titan.stackadapt.page_size', 250)),
            'after' => $after,
        ]);

        $records = $this->unwrapDeliveryRecords($data['campaignDelivery'] ?? null);
        $pageInfo = $this->extractPageInfo($data['campaignDelivery'] ?? null);

        return [
            'records' => $records,
            'next_cursor' => ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null,
        ];
    }

    /**
     * @return array{records: list<array<string, mixed>>, next_cursor: string|null}
     */
    public function campaignInsightPage(
        string $apiKey,
        string $advertiserId,
        string $fromDate,
        string $toDate,
        array $attributes,
        ?string $after = null,
    ): array {
        $query = <<<'GQL'
            query StackAdaptCampaignInsight(
                $attributes: [InsightAttributeField!]!
                $filterBy: CampaignFilters
                $date: DateRangeInput
                $first: Int
                $after: String
            ) {
                campaignInsight(
                    attributes: $attributes
                    filterBy: $filterBy
                    date: $date
                ) {
                    __typename
                    ... on CampaignInsightOutcome {
                        records(first: $first, after: $after) {
                            nodes {
                                attributes {
                                    date
                                    country { code name }
                                    region { code name }
                                    app { domain title }
                                    deviceType
                                }
                                metrics {
                                    cost
                                    impressionsBigint
                                    clicksBigint
                                    ctr
                                    conversionsBigint
                                    conversionRevenue
                                    roas
                                }
                            }
                            pageInfo {
                                hasNextPage
                                endCursor
                            }
                        }
                    }
                }
            }
        GQL;

        $data = $this->request($apiKey, $query, [
            'attributes' => $attributes,
            'filterBy' => [
                'advertiserIds' => [$advertiserId],
            ],
            'date' => [
                'from' => $fromDate,
                'to' => $toDate,
            ],
            'first' => max(1, (int) config('titan.stackadapt.page_size', 250)),
            'after' => $after,
        ]);

        $records = $this->unwrapInsightRecords($data['campaignInsight'] ?? null);
        $pageInfo = $this->extractInsightPageInfo($data['campaignInsight'] ?? null);

        return [
            'records' => $records,
            'next_cursor' => ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null,
        ];
    }

    /**
     * @return array{
     *     delivery_typename: string,
     *     delivery_record_count: int,
     *     delivery_from_date: string,
     *     delivery_to_date: string
     * }
     */
    public function testAdvertiserAccess(string $apiKey, string $advertiserId): array
    {
        $lagDays = max(0, (int) config('titan.stackadapt.data_lag_days', 1));
        $windowDays = max(7, (int) config('titan.stackadapt.test_window_days', 30));

        $end = now()->subDays($lagDays)->toDateString();
        $start = now()->subDays($windowDays)->toDateString();

        $probe = $this->advertiserDeliveryProbe(
            $apiKey,
            $advertiserId,
            $start,
            $this->exclusiveEndDate($end),
        );

        $typename = $probe['typename'];

        if ($typename === null) {
            throw new RuntimeException('StackAdapt returned no advertiser delivery payload for the selected advertiser.');
        }

        if (! in_array($typename, ['AdvertiserDeliveryOutcome', 'CampaignDeliveryOutcome'], true)) {
            throw new RuntimeException('StackAdapt returned unexpected delivery response type: '.$typename.'.');
        }

        return [
            'delivery_typename' => $typename,
            'delivery_record_count' => $probe['record_count'],
            'delivery_from_date' => $start,
            'delivery_to_date' => $end,
        ];
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function request(string $apiKey, string $query, array $variables = []): array
    {
        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->post($this->endpoint(), [
                'query' => $query,
                'variables' => $variables === [] ? new \stdClass : $variables,
            ]);

        return $this->decodeResponse($response);
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeResponse(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw new RuntimeException('StackAdapt GraphQL returned an invalid response.');
        }

        $errors = $body['errors'] ?? [];

        if (is_array($errors) && $errors !== []) {
            $message = $errors[0]['message'] ?? 'StackAdapt GraphQL request failed.';

            throw new RuntimeException(is_string($message) ? $message : 'StackAdapt GraphQL request failed.');
        }

        $data = $body['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    protected function endpoint(): string
    {
        return (string) config('titan.stackadapt.graphql_endpoint', 'https://api.stackadapt.com/graphql');
    }

    protected function metricsSelection(): string
    {
        return self::METRICS_FRAGMENT;
    }

    /**
     * StackAdapt DateRangeInput `to` is exclusive.
     */
    public function exclusiveEndDate(string $inclusiveEndDate): string
    {
        return \Carbon\Carbon::parse($inclusiveEndDate)->addDay()->toDateString();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function unwrapDeliveryRecords(mixed $payload): array
    {
        $typename = is_array($payload) ? (string) ($payload['__typename'] ?? '') : '';

        if ($typename === 'Progress') {
            throw new RuntimeException('StackAdapt delivery report is still processing. Try again shortly.');
        }

        if (! in_array($typename, ['AdvertiserDeliveryOutcome', 'CampaignDeliveryOutcome'], true)) {
            return [];
        }

        $nodes = $payload['records']['nodes'] ?? [];

        return is_array($nodes) ? array_values(array_filter($nodes, 'is_array')) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function unwrapInsightRecords(mixed $payload): array
    {
        if (! is_array($payload) || ($payload['__typename'] ?? '') !== 'CampaignInsightOutcome') {
            if (is_array($payload) && ($payload['__typename'] ?? '') === 'Progress') {
                throw new RuntimeException('StackAdapt insight report is still processing. Try again shortly.');
            }

            return [];
        }

        $nodes = $payload['records']['nodes'] ?? [];

        return is_array($nodes) ? array_values(array_filter($nodes, 'is_array')) : [];
    }

    /**
     * @return array{hasNextPage?: bool, endCursor?: string|null}
     */
    protected function extractPageInfo(mixed $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $pageInfo = $payload['records']['pageInfo'] ?? [];

        return is_array($pageInfo) ? $pageInfo : [];
    }

    /**
     * @return array{hasNextPage?: bool, endCursor?: string|null}
     */
    protected function extractInsightPageInfo(mixed $payload): array
    {
        return $this->extractPageInfo($payload);
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('errors.0.message')
            ?? $response->json('message')
            ?? $response->body();

        return is_string($message) && $message !== ''
            ? $message
            : 'StackAdapt API request failed.';
    }
}
