<?php

namespace App\Ingestion\Connectors\Dynamic;

use App\Models\ConnectorBlueprint;
use App\Support\DynamicConnectorAuth;
use App\Support\DynamicConnectorReadOnlyGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class DynamicHttpClient
{
    /** @var array<string, array{token: string, expires_at: int}> */
    protected static array $tokenCache = [];

    public static function resetTokenCache(): void
    {
        self::$tokenCache = [];
    }

    public function __construct(protected DynamicConnectorReadOnlyGuard $readOnlyGuard) {}

    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $headers
     * @param  array<string, mixed>  $body
     */
    public function request(
        ConnectorBlueprint $blueprint,
        array $credentials,
        string $method,
        string $path,
        array $queryParams = [],
        array $headers = [],
        array $body = [],
        string $bodyFormat = 'json',
    ): array {
        $this->assertAllowedMethod($method);
        $url = $this->buildUrl($blueprint, $path, $credentials);
        $this->assertAllowedHost($blueprint, $url, $credentials);

        $queryParams = $this->interpolateArray($queryParams, $credentials);
        $headers = $this->interpolateArray($headers, $credentials);
        $body = $this->interpolateArray($body, $credentials);

        $request = $this->baseRequest($blueprint, $credentials, $headers, resolveToken: true);
        $response = $this->sendRequest($request, strtoupper($method), $url, $queryParams, $body, $bodyFormat);

        return $this->decodeJsonResponse($response);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function buildUrl(ConnectorBlueprint $blueprint, string $path, array $credentials): string
    {
        $baseUrl = $blueprint->baseUrl($credentials);
        $path = $this->interpolateString($path, $credentials);

        if (Str::startsWith($path, ['http://', 'https://'])) {
            return $path;
        }

        if ($baseUrl === '') {
            throw new \RuntimeException('Connector is missing a base URL for this dashboard.');
        }

        return $baseUrl.'/'.ltrim($path, '/');
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function applyAuth(PendingRequest $request, ConnectorBlueprint $blueprint, array $credentials, bool $resolveToken = true): PendingRequest
    {
        $auth = DynamicConnectorAuth::normalize($blueprint->auth_config ?? []) ?? [];
        $type = $auth['type'] ?? 'api_key';

        return match ($type) {
            'bearer', 'oauth2_client_credentials' => $request->withToken($resolveToken
                ? $this->resolveBearerToken($blueprint, $credentials, $auth)
                : $this->credentialValue($credentials, $auth['credential_key'] ?? 'access_token')),
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

        if ($recordsPath === '@root' && array_is_list($response)) {
            return array_values(array_filter($response, fn ($record) => is_array($record)));
        }

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

    protected function assertAllowedHost(ConnectorBlueprint $blueprint, string $url, array $credentials): void
    {
        $allowedHost = parse_url($blueprint->baseUrl($credentials), PHP_URL_HOST);
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
     * @param  array<string, mixed>  $headers
     */
    protected function baseRequest(
        ConnectorBlueprint $blueprint,
        array $credentials,
        array $headers = [],
        bool $resolveToken = true,
    ): PendingRequest {
        $timeout = (int) config('titan.connector_builder.http_timeout_seconds', 30);

        $request = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders($headers);

        return $this->applyAuth($request, $blueprint, $credentials, $resolveToken);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function resolveBearerToken(
        ConnectorBlueprint $blueprint,
        array $credentials,
        ?array $auth = null,
    ): string {
        $auth = $auth ?? DynamicConnectorAuth::normalize($blueprint->auth_config ?? []) ?? [];

        if (! DynamicConnectorAuth::usesTokenRequest($auth)) {
            return $this->credentialValue($credentials, $auth['credential_key'] ?? 'access_token');
        }

        $cacheKey = hash('sha256', json_encode([
            'blueprint_id' => $blueprint->id,
            'credentials' => $credentials,
            'token_request' => DynamicConnectorAuth::tokenRequest($auth),
        ]));

        $cached = self::$tokenCache[$cacheKey] ?? null;

        if (is_array($cached) && ($cached['expires_at'] ?? 0) > time()) {
            return $cached['token'];
        }

        $tokenRequest = DynamicConnectorAuth::tokenRequest($auth);
        $method = (string) ($tokenRequest['method'] ?? 'POST');
        $path = (string) ($tokenRequest['path'] ?? '/oauth/token');
        $body = is_array($tokenRequest['body'] ?? null) ? $tokenRequest['body'] : [];
        $headers = is_array($tokenRequest['headers'] ?? null) ? $tokenRequest['headers'] : [];
        $bodyFormat = (string) ($tokenRequest['body_format'] ?? 'form');
        $clientAuth = (string) ($tokenRequest['client_auth'] ?? 'body');

        $url = $this->buildUrl($blueprint, $path, $credentials);
        $this->assertAllowedHost($blueprint, $url, $credentials);

        $request = Http::timeout((int) config('titan.connector_builder.http_timeout_seconds', 30))
            ->acceptJson()
            ->withHeaders($this->interpolateArray($headers, $credentials));

        if ($clientAuth === 'basic') {
            $request = $request->withBasicAuth(
                $this->credentialValue($credentials, $auth['client_id_key'] ?? 'client_id'),
                $this->credentialValue($credentials, $auth['client_secret_key'] ?? 'client_secret'),
            );
        }

        $response = $this->sendRequest(
            $request,
            $method,
            $url,
            [],
            $this->interpolateArray($body, $credentials),
            $bodyFormat,
        );

        $payload = $this->decodeJsonResponse($response);
        $tokenPath = (string) ($tokenRequest['token_path'] ?? 'access_token');
        $token = Arr::get($payload, $tokenPath);

        if (! is_string($token) || trim($token) === '') {
            throw new \RuntimeException('Token request did not return an access token.');
        }

        $token = trim($token);
        $expiresIn = Arr::get($payload, (string) ($tokenRequest['expires_in_path'] ?? 'expires_in'));
        $ttlSeconds = is_numeric($expiresIn) ? max(60, ((int) $expiresIn) - 30) : 3600;

        self::$tokenCache[$cacheKey] = [
            'token' => $token,
            'expires_at' => time() + $ttlSeconds,
        ];

        return $token;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $body
     */
    protected function sendRequest(
        PendingRequest $request,
        string $method,
        string $url,
        array $queryParams,
        array $body,
        string $bodyFormat,
    ): Response {
        $request = $request->withQueryParameters($queryParams);

        return match ($method) {
            'GET' => $request->get($url),
            'POST' => $this->sendPost($request, $url, $body, $bodyFormat),
            default => throw new \InvalidArgumentException("Unsupported HTTP method [{$method}]."),
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function sendPost(PendingRequest $request, string $url, array $body, string $bodyFormat): Response
    {
        if ($bodyFormat === 'form') {
            return $request->asForm()->post($url, $body);
        }

        if ($body === []) {
            return $request->post($url);
        }

        return $request->asJson()->post($url, $body);
    }

    protected function decodeJsonResponse(Response $response): array
    {
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
    protected function credentialValue(array $credentials, string $key): string
    {
        $value = $credentials[$key] ?? '';

        return is_string($value) ? trim($value) : (string) $value;
    }
}
