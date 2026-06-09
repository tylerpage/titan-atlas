<?php

namespace App\Ingestion\Connectors\GoogleAds;

use App\Services\Google\GoogleTokenService;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleAdsApiClient
{
    public function __construct(protected GoogleTokenService $tokens) {}

    protected function baseUrl(): string
    {
        $version = (string) config('titan.google_ads.api_version', 'v21');

        if (! preg_match('/^v\d+(\.\d+)?$/', $version)) {
            throw new RuntimeException('Invalid Google Ads API version configured.');
        }

        return "https://googleads.googleapis.com/{$version}";
    }

    /**
     * Accounts available for selection after OAuth, including MCC client accounts.
     *
     * @return list<array{
     *     customerId: string,
     *     displayName: string,
     *     managerCustomerId: string|null,
     *     managerDisplayName: string|null,
     *     pickerLabel: string,
     *     isManagerAccount: bool
     * }>
     */
    public function listSelectableCustomers(string $refreshToken): array
    {
        $selectable = [];
        $seen = [];

        foreach ($this->fetchAccessibleCustomerIds($refreshToken) as $customerId) {
            $displayName = $this->fetchDescriptiveName($refreshToken, $customerId, $customerId);

            $this->pushSelectableCustomer(
                selectable: $selectable,
                seen: $seen,
                customerId: $customerId,
                displayName: $displayName,
                managerCustomerId: null,
                workspaceDisplayName: null,
                isManagerAccount: true,
            );

            $this->appendManagerClientAccounts(
                selectable: $selectable,
                seen: $seen,
                refreshToken: $refreshToken,
                managerCustomerId: $customerId,
                workspaceDisplayName: $displayName,
            );
        }

        usort($selectable, fn (array $left, array $right) => strcasecmp($left['pickerLabel'], $right['pickerLabel']));

        return $selectable;
    }

    /**
     * @deprecated Use listSelectableCustomers() for account pickers.
     *
     * @return list<array{customerId: string, displayName: string}>
     */
    public function listAccessibleCustomers(string $refreshToken): array
    {
        return array_map(
            fn (array $customer) => [
                'customerId' => $customer['customerId'],
                'displayName' => $customer['displayName'],
            ],
            $this->listSelectableCustomers($refreshToken),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function searchStream(
        string $refreshToken,
        string $customerId,
        string $query,
        ?string $loginCustomerId = null,
    ): array {
        $customerId = $this->normalizeCustomerId($customerId);
        $url = "{$this->baseUrl()}/customers/{$customerId}/googleAds:searchStream";

        $response = $this->authorizedPost($refreshToken, $url, ['query' => $query], $loginCustomerId);

        $batches = $response->json();

        if (! is_array($batches)) {
            return [];
        }

        $results = [];

        foreach ($batches as $batch) {
            if (! is_array($batch)) {
                continue;
            }

            $batchResults = $batch['results'] ?? [];

            if (! is_array($batchResults)) {
                continue;
            }

            foreach ($batchResults as $row) {
                if (is_array($row)) {
                    $results[] = $row;
                }
            }
        }

        return $results;
    }

    public function testConnection(
        string $refreshToken,
        string $customerId,
        ?string $loginCustomerId = null,
    ): void {
        $this->searchStream(
            $refreshToken,
            $customerId,
            'SELECT metrics.cost_micros FROM customer WHERE segments.date DURING LAST_7_DAYS LIMIT 1',
            $loginCustomerId,
        );
    }

    public function customerDisplayName(
        string $refreshToken,
        string $customerId,
        ?string $loginCustomerId = null,
    ): string {
        return $this->fetchDescriptiveName($refreshToken, $customerId, $loginCustomerId);
    }

    public function normalizeCustomerId(string $customerId): string
    {
        return preg_replace('/\D/', '', $customerId) ?? '';
    }

    /**
     * @return list<string>
     */
    protected function fetchAccessibleCustomerIds(string $refreshToken): array
    {
        $response = $this->authorizedGet(
            $refreshToken,
            "{$this->baseUrl()}/customers:listAccessibleCustomers",
        );

        $resourceNames = $response->json('resourceNames') ?? [];

        if (! is_array($resourceNames)) {
            return [];
        }

        $customerIds = [];

        foreach ($resourceNames as $resourceName) {
            if (! is_string($resourceName) || ! str_starts_with($resourceName, 'customers/')) {
                continue;
            }

            $customerId = $this->normalizeCustomerId(substr($resourceName, strlen('customers/')));

            if ($customerId !== '') {
                $customerIds[] = $customerId;
            }
        }

        return $customerIds;
    }

    /**
     * @param  list<array{
     *     customerId: string,
     *     displayName: string,
     *     managerCustomerId: string|null,
     *     managerDisplayName: string|null,
     *     pickerLabel: string,
     *     isManagerAccount: bool
     * }>  $selectable
     * @param  array<string, true>  $seen
     */
    protected function appendManagerClientAccounts(
        array &$selectable,
        array &$seen,
        string $refreshToken,
        string $managerCustomerId,
        string $workspaceDisplayName,
    ): void {
        try {
            $rows = $this->searchStream(
                $refreshToken,
                $managerCustomerId,
                'SELECT customer_client.client_customer, customer_client.descriptive_name, customer_client.hidden, customer_client.level, customer_client.manager FROM customer_client WHERE customer_client.level <= 1',
                $managerCustomerId,
            );
        } catch (RuntimeException) {
            return;
        }

        foreach ($rows as $row) {
            $client = is_array($row['customerClient'] ?? null) ? $row['customerClient'] : [];

            if (($client['hidden'] ?? false) === true) {
                continue;
            }

            $clientCustomer = (string) ($client['clientCustomer'] ?? '');

            if (! str_starts_with($clientCustomer, 'customers/')) {
                continue;
            }

            $customerId = $this->normalizeCustomerId(substr($clientCustomer, strlen('customers/')));

            if ($customerId === '' || $customerId === $managerCustomerId) {
                continue;
            }

            $displayName = (string) ($client['descriptiveName'] ?? '');

            if ($displayName === '') {
                $displayName = $this->formatCustomerId($customerId);
            }

            $isManagerAccount = ($client['manager'] ?? false) === true;

            $this->pushSelectableCustomer(
                selectable: $selectable,
                seen: $seen,
                customerId: $customerId,
                displayName: $displayName,
                managerCustomerId: $managerCustomerId,
                workspaceDisplayName: $workspaceDisplayName,
                isManagerAccount: $isManagerAccount,
            );

            if ($isManagerAccount) {
                $this->appendManagerClientAccounts(
                    selectable: $selectable,
                    seen: $seen,
                    refreshToken: $refreshToken,
                    managerCustomerId: $customerId,
                    workspaceDisplayName: $workspaceDisplayName,
                );
            }
        }
    }

    /**
     * @param  list<array{
     *     customerId: string,
     *     displayName: string,
     *     managerCustomerId: string|null,
     *     managerDisplayName: string|null,
     *     pickerLabel: string,
     *     isManagerAccount: bool
     * }>  $selectable
     * @param  array<string, true>  $seen
     */
    protected function pushSelectableCustomer(
        array &$selectable,
        array &$seen,
        string $customerId,
        string $displayName,
        ?string $managerCustomerId,
        ?string $workspaceDisplayName,
        bool $isManagerAccount,
    ): void {
        $dedupeKey = $customerId.':'.($managerCustomerId ?? '');

        if (isset($seen[$dedupeKey])) {
            return;
        }

        $seen[$dedupeKey] = true;

        $selectable[] = [
            'customerId' => $customerId,
            'displayName' => $displayName,
            'managerCustomerId' => $managerCustomerId,
            'managerDisplayName' => $workspaceDisplayName,
            'pickerLabel' => $this->buildPickerLabel($displayName, $workspaceDisplayName, $isManagerAccount),
            'isManagerAccount' => $isManagerAccount,
        ];
    }

    protected function buildPickerLabel(
        string $displayName,
        ?string $workspaceDisplayName,
        bool $isManagerAccount,
    ): string {
        if ($workspaceDisplayName !== null
            && $workspaceDisplayName !== ''
            && strcasecmp($workspaceDisplayName, $displayName) !== 0) {
            $label = $workspaceDisplayName.' > '.$displayName;

            return $isManagerAccount ? $label.' (manager)' : $label;
        }

        if ($isManagerAccount) {
            return $displayName.' (manager)';
        }

        return $displayName;
    }

    protected function fetchDescriptiveName(
        string $refreshToken,
        string $customerId,
        ?string $loginCustomerId = null,
    ): string {
        try {
            $rows = $this->searchStream(
                $refreshToken,
                $customerId,
                'SELECT customer.descriptive_name FROM customer LIMIT 1',
                $loginCustomerId,
            );

            $name = $rows[0]['customer']['descriptiveName'] ?? null;

            if (is_string($name) && $name !== '') {
                return $name;
            }
        } catch (RuntimeException) {
            // Fall back to customer ID label.
        }

        return $this->formatCustomerId($customerId);
    }

    protected function formatCustomerId(string $customerId): string
    {
        $digits = $this->normalizeCustomerId($customerId);

        if (strlen($digits) === 10) {
            return substr($digits, 0, 3).'-'.substr($digits, 3, 3).'-'.substr($digits, 6);
        }

        return $digits;
    }

    protected function authorizedGet(string $refreshToken, string $url, ?string $loginCustomerId = null): Response
    {
        $response = Http::withToken($this->tokens->refreshAccessToken($refreshToken))
            ->withHeaders($this->requestHeaders($loginCustomerId))
            ->acceptJson()
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function authorizedPost(
        string $refreshToken,
        string $url,
        array $body,
        ?string $loginCustomerId = null,
    ): Response {
        $response = Http::withToken($this->tokens->refreshAccessToken($refreshToken))
            ->withHeaders($this->requestHeaders($loginCustomerId))
            ->acceptJson()
            ->post($url, $body);

        if (! $response->successful()) {
            throw new RuntimeException($this->errorMessage($response));
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    protected function requestHeaders(?string $loginCustomerId = null): array
    {
        $developerToken = (string) config('titan.google_ads.developer_token', '');

        if ($developerToken === '') {
            throw new RuntimeException('Google Ads developer token is not configured. Set GOOGLE_ADS_DEVELOPER_TOKEN in the environment.');
        }

        $headers = [
            'developer-token' => $developerToken,
            'Content-Type' => 'application/json',
        ];

        $loginCustomerId = $loginCustomerId !== null && $loginCustomerId !== ''
            ? $this->normalizeCustomerId($loginCustomerId)
            : '';

        if ($loginCustomerId !== '') {
            $headers['login-customer-id'] = $loginCustomerId;
        }

        return $headers;
    }

    protected function errorMessage(Response $response): string
    {
        $message = $response->json('error.message')
            ?? $response->json('message')
            ?? $response->body();

        return is_string($message) && $message !== ''
            ? $message
            : 'Google Ads API request failed.';
    }
}
