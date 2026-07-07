<?php

namespace App\Ingestion\Connectors\MetaAds;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MetaAdsApiClient
{
    protected function graphUrl(string $path): string
    {
        $version = (string) config('titan.meta_ads.api_version', 'v21.0');
        $base = rtrim((string) config('titan.meta_ads.graph_base_url', 'https://graph.facebook.com'), '/');

        return $base.'/'.$version.'/'.ltrim($path, '/');
    }

    public function normalizeAdAccountId(string $id): string
    {
        $id = trim($id);

        if ($id === '') {
            return '';
        }

        return str_starts_with($id, 'act_') ? $id : 'act_'.$id;
    }

    /**
     * @return list<array{adAccountId: string, name: string, currency: string}>
     */
    public function listAdAccounts(string $accessToken): array
    {
        $response = $this->request($accessToken, 'GET', 'me/adaccounts', [
            'fields' => 'id,name,account_id,currency',
            'limit' => max(1, (int) config('titan.meta_ads.ad_accounts_limit', 100)),
        ]);

        $accounts = [];

        foreach (Arr::get($response, 'data', []) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $adAccountId = $this->normalizeAdAccountId((string) ($row['id'] ?? $row['account_id'] ?? ''));

            if ($adAccountId === '') {
                continue;
            }

            $accounts[] = [
                'adAccountId' => $adAccountId,
                'name' => (string) ($row['name'] ?? $adAccountId),
                'currency' => (string) ($row['currency'] ?? 'USD'),
            ];
        }

        usort($accounts, fn (array $left, array $right) => strcasecmp($left['name'], $right['name']));

        return $accounts;
    }

    /**
     * @return array{adAccountId: string, name: string, currency: string}
     */
    public function testConnection(string $accessToken, string $adAccountId): array
    {
        $adAccountId = $this->normalizeAdAccountId($adAccountId);

        $response = $this->request($accessToken, 'GET', $adAccountId, [
            'fields' => 'id,name,currency,account_status',
        ]);

        return [
            'adAccountId' => $adAccountId,
            'name' => (string) ($response['name'] ?? $adAccountId),
            'currency' => (string) ($response['currency'] ?? 'USD'),
        ];
    }

    /**
     * @return array{rows: list<array<string, mixed>>, after: string|null}
     */
    public function insightsPage(
        string $accessToken,
        string $adAccountId,
        string $startDate,
        string $endDate,
        string $stream,
        ?string $after = null,
    ): array {
        $adAccountId = $this->normalizeAdAccountId($adAccountId);

        $params = [
            'time_range' => json_encode(['since' => $startDate, 'until' => $endDate], JSON_THROW_ON_ERROR),
            'time_increment' => 1,
            'limit' => max(1, (int) config('titan.meta_ads.page_size', 500)),
            'fields' => implode(',', $this->fieldsForStream($stream)),
        ];

        match ($stream) {
            'campaign_daily' => $params['level'] = 'campaign',
            'placement_daily' => [
                $params['level'] = 'account',
                $params['breakdowns'] = 'publisher_platform,platform_position',
            ],
            'device_daily' => [
                $params['level'] = 'account',
                $params['breakdowns'] = 'device_platform',
            ],
            default => $params['level'] = 'account',
        };

        if ($after !== null && $after !== '') {
            $params['after'] = $after;
        }

        $response = $this->request($accessToken, 'GET', $adAccountId.'/insights', $params);
        $rows = Arr::get($response, 'data', []);

        if (! is_array($rows)) {
            $rows = [];
        }

        $nextAfter = Arr::get($response, 'paging.cursors.after');

        return [
            'rows' => array_values(array_filter($rows, fn ($row) => is_array($row))),
            'after' => is_string($nextAfter) && $nextAfter !== '' ? $nextAfter : null,
        ];
    }

    /**
     * @return list<string>
     */
    protected function fieldsForStream(string $stream): array
    {
        $base = [
            'date_start',
            'date_stop',
            'spend',
            'impressions',
            'inline_link_clicks',
            'clicks',
            'ctr',
            'cpc',
            'cpm',
            'reach',
            'frequency',
            'actions',
            'action_values',
        ];

        if ($stream === 'campaign_daily') {
            $base[] = 'campaign_id';
            $base[] = 'campaign_name';
            $base[] = 'objective';
        }

        if ($stream === 'placement_daily') {
            $base[] = 'publisher_platform';
            $base[] = 'platform_position';
        }

        if ($stream === 'device_daily') {
            $base[] = 'device_platform';
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    protected function request(string $accessToken, string $method, string $path, array $query = []): array
    {
        $timeout = max(10, (int) config('titan.meta_ads.http_timeout_seconds', 60));
        $url = $this->graphUrl($path);
        $query['access_token'] = $accessToken;

        $response = Http::acceptJson()
            ->timeout($timeout)
            ->get($url, $query);

        if (! $response->successful()) {
            $message = (string) (
                $response->json('error.message')
                ?? $response->json('message')
                ?? $response->body()
            );

            throw new RuntimeException(
                'Meta Ads API request failed ('.$response->status().'): '.($message !== '' ? $message : 'Unknown error'),
            );
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    /**
     * @param  list<array<string, mixed>>|null  $actions
     */
    public function sumActionValues(?array $actions, array $actionTypes): float
    {
        if ($actions === null) {
            return 0.0;
        }

        $total = 0.0;

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            $type = (string) ($action['action_type'] ?? '');

            if (! in_array($type, $actionTypes, true)) {
                continue;
            }

            $total += (float) ($action['value'] ?? 0);
        }

        return $total;
    }
}
