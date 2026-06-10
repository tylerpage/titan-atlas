<?php

namespace App\Ingestion\Connectors\Dynamic;

use App\Models\ConnectorBlueprint;
use App\Support\DynamicConnectorReadOnlyGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DynamicHttpClient
{
    public function __construct(protected DynamicConnectorReadOnlyGuard $readOnlyGuard) {}
    /**
     * @param  array<string, mixed>  $credentials
     */
    public function request(
        ConnectorBlueprint $blueprint,
        array $credentials,
        string $method,
        string $path,
        array $queryParams = [],
        array $headers = [],
    ): array {
        $this->assertAllowedMethod($method);
        $url = $this->buildUrl($blueprint, $path, $credentials);
        $this->assertAllowedHost($blueprint, $url);

        $queryParams = $this->interpolateArray($queryParams, $credentials);
        $headers = $this->interpolateArray($headers, $credentials);

        $request = $this->baseRequest($blueprint, $credentials, $headers);

        $response = match (strtoupper($method)) {
            'GET' => $request->get($url, $queryParams),
            default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };

        if (! $response->successful()) {
            throw new \RuntimeException(
                "API request failed with status {$response->status()}: ".Str::limit($response->body(), 500),
            );
        }

        $body = $response->body();
        $maxBytes = (int) config('titan.connector_builder.max_response_bytes', 5_000_000);

        if (strlen($body) > $maxBytes) {
            throw new \RuntimeException('API response exceeded maximum allowed size.');
        }

        $json = $response->json();

        if (! is_array($json)) {
            throw new \RuntimeException('API response was not valid JSON.');
        }

        return $json;
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function buildUrl(ConnectorBlueprint $blueprint, string $path, array $credentials): string
    {
        $baseUrl = $blueprint->baseUrl();
        $path = $this->interpolateString($path, $credentials);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function applyAuth(PendingRequest $request, ConnectorBlueprint $blueprint, array $credentials): PendingRequest
    {
        $auth = $blueprint->auth_config ?? [];
        $type = $auth['type'] ?? 'api_key';

        return match ($type) {
            'bearer' => $request->withToken($this->credentialValue($credentials, $auth['credential_key'] ?? 'access_token')),
            'basic' => $request->withBasicAuth(
                $this->credentialValue($credentials, $auth['username_key'] ?? 'username'),
                $this->credentialValue($credentials, $auth['password_key'] ?? 'password'),
            ),
            'api_key' => $this->applyApiKeyAuth($request, $auth, $credentials),
            default => throw new \InvalidArgumentException("Unsupported auth type [{$type}] for dynamic connectors."),
        };
    }

    /**
     * @param  array<string, mixed>  $mapping
     * @return list<array<string, mixed>>
     */
    public function extractRecords(array $response, array $mapping): array
    {
        $recordsPath = $mapping['records_path'] ?? 'results';
        $records = Arr::get($response, $recordsPath, []);

        if (! is_array($records)) {
            return [];
        }

        if ($records !== [] && ! array_is_list($records)) {
            $records = [$records];
        }

        return array_values(array_filter($records, fn ($record) => is_array($record)));
    }

    /**
     * @param  array<string, mixed>  $record
     * @param  array<string, mixed>  $mapping
     * @return array{resource_type?: string, external_id?: string, payload: array<string, mixed>}
     */
    public function normalizeRecord(array $record, array $mapping, string $resourceType): array
    {
        $idPath = $mapping['id_path'] ?? 'id';
        $datePath = $mapping['date_path'] ?? 'date';
        $fields = $mapping['fields'] ?? [];

        $payload = [];

        if ($fields === []) {
            $payload = $record;
        } else {
            foreach ($fields as $field) {
                if (is_string($field)) {
                    $payload[$field] = Arr::get($record, $field);
                } elseif (is_array($field) && isset($field['source'], $field['target'])) {
                    $payload[$field['target']] = Arr::get($record, $field['source']);
                }
            }
        }

        $date = Arr::get($record, $datePath) ?? Arr::get($payload, 'date');
        if ($date !== null) {
            $payload['date'] = is_string($date) ? substr($date, 0, 10) : $date;
        } else {
            $payload['date'] = now()->toDateString();
        }

        $externalId = Arr::get($record, $idPath);

        return [
            'external_id' => $externalId !== null ? (string) $externalId : null,
            'payload' => $payload,
            'resource_type' => $resourceType,
        ];
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function interpolateString(string $value, array $credentials): string
    {
        return preg_replace_callback('/\{\{(\w+)\}\}/', function (array $matches) use ($credentials): string {
            return (string) ($credentials[$matches[1]] ?? '');
        }, $value) ?? $value;
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    public function interpolateArray(array $values, array $credentials): array
    {
        $interpolated = [];

        foreach ($values as $key => $value) {
            if (is_string($value)) {
                $interpolated[$key] = $this->interpolateString($value, $credentials);
            } elseif (is_array($value)) {
                $interpolated[$key] = $this->interpolateArray($value, $credentials);
            } else {
                $interpolated[$key] = $value;
            }
        }

        return $interpolated;
    }

    protected function assertAllowedMethod(string $method): void
    {
        $this->readOnlyGuard->assertHttpMethodAllowed($method);
    }

    protected function assertAllowedHost(ConnectorBlueprint $blueprint, string $url): void
    {
        $allowedHost = parse_url($blueprint->baseUrl(), PHP_URL_HOST);
        $requestHost = parse_url($url, PHP_URL_HOST);

        if ($allowedHost === null || $requestHost === null) {
            throw new \RuntimeException('Invalid URL host for dynamic connector request.');
        }

        if (strcasecmp($allowedHost, $requestHost) !== 0) {
            throw new \RuntimeException("Request host [{$requestHost}] is not allowed for this connector.");
        }

        $blockedHosts = ['localhost', '127.0.0.1', '0.0.0.0', '[::1]'];

        if (in_array(strtolower($requestHost), $blockedHosts, true)) {
            throw new \RuntimeException('Requests to local hosts are not allowed.');
        }
    }

    /**
     * @param  array<string, mixed>  $auth
     * @param  array<string, mixed>  $credentials
     */
    protected function applyApiKeyAuth(PendingRequest $request, array $auth, array $credentials): PendingRequest
    {
        $key = $this->credentialValue($credentials, $auth['credential_key'] ?? 'api_key');
        $location = $auth['location'] ?? 'header';
        $headerName = $auth['header_name'] ?? 'Authorization';
        $prefix = $auth['prefix'] ?? 'Bearer ';

        if ($location === 'query') {
            $queryKey = $auth['query_param'] ?? 'api_key';

            return $request->withQueryParameters([$queryKey => $key]);
        }

        $headerValue = $prefix !== '' ? $prefix.$key : $key;

        return $request->withHeaders([$headerName => $headerValue]);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function baseRequest(ConnectorBlueprint $blueprint, array $credentials, array $headers): PendingRequest
    {
        $timeout = (int) config('titan.connector_builder.http_timeout_seconds', 30);

        $request = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders($headers);

        return $this->applyAuth($request, $blueprint, $credentials);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function credentialValue(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? '';

        return is_string($value) ? trim($value) : (string) $value;
    }
}
