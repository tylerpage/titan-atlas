<?php

namespace App\Services\Ingestion;

use App\Enums\ConnectorApiLogContext;
use App\Models\ConnectorApiLog;
use App\Models\ConnectorBlueprint;
use App\Support\DynamicConnectorAuth;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

class ConnectorApiLogService
{
    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $headers
     */
    public function record(
        ConnectorBlueprint $blueprint,
        string $method,
        string $url,
        array $queryParams,
        array $body,
        Response $response,
        int $durationMs,
        ?string $errorMessage = null,
        ?ConnectorApiLogContext $context = null,
        array $headers = [],
        ?string $bodyFormat = null,
    ): void {
        if (! config('titan.connector_api_logs.enabled', true)) {
            return;
        }

        $scope = \App\Support\ConnectorApiLogScope::current() ?? [];
        $authType = DynamicConnectorAuth::normalize($blueprint->auth_config ?? [])['type'] ?? null;

        ConnectorApiLog::query()->create([
            'connection_id' => $scope['connection_id'] ?? null,
            'connector_blueprint_id' => $blueprint->id,
            'connector_type' => (string) ($scope['connector_type'] ?? 'dynamic'),
            'context' => $context ?? $this->contextFromScope($scope),
            'method' => strtoupper($method),
            'url' => $this->sanitizeUrl($url),
            'status_code' => $response->status(),
            'duration_ms' => max(0, $durationMs),
            'stream_key' => isset($scope['stream_key']) ? (string) $scope['stream_key'] : null,
            'resource_type' => isset($scope['resource_type']) ? (string) $scope['resource_type'] : null,
            'request_query' => $this->redactArray($queryParams),
            'request_body' => $this->redactArray($body),
            'request_headers' => $this->buildRequestHeaders($headers, is_string($authType) ? $authType : null),
            'request_body_format' => $bodyFormat,
            'response_body' => $this->truncateBody($response->body()),
            'response_headers' => $this->redactHeaders($response->headers()),
            'error_message' => $errorMessage !== null ? Str::limit($errorMessage, 1000) : null,
        ]);
    }

    public function pruneExpired(): int
    {
        $hours = (int) config('titan.connector_api_logs.retention_hours', 48);

        return ConnectorApiLog::query()
            ->where('created_at', '<', now()->subHours($hours))
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    protected function contextFromScope(array $scope): ConnectorApiLogContext
    {
        $value = $scope['context'] ?? ConnectorApiLogContext::Sync->value;

        return ConnectorApiLogContext::tryFrom((string) $value) ?? ConnectorApiLogContext::Sync;
    }

    protected function sanitizeUrl(string $url): string
    {
        $parts = parse_url($url);

        if ($parts === false) {
            return Str::limit($url, 2000);
        }

        if (! isset($parts['query'])) {
            return Str::limit($url, 2000);
        }

        parse_str($parts['query'], $query);
        $redactedQuery = http_build_query($this->redactArray($query));
        $base = ($parts['scheme'] ?? 'https').'://'.($parts['host'] ?? '');
        $path = $parts['path'] ?? '';

        if (isset($parts['port'])) {
            $base .= ':'.$parts['port'];
        }

        return Str::limit($base.$path.($redactedQuery !== '' ? '?'.$redactedQuery : ''), 2000);
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    protected function redactArray(array $values): array
    {
        $redacted = [];

        foreach ($values as $key => $value) {
            if ($this->isSensitiveKey((string) $key)) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            if (is_array($value)) {
                $redacted[$key] = $this->redactArray($value);

                continue;
            }

            $redacted[$key] = $value;
        }

        return $redacted;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    protected function buildRequestHeaders(array $headers, ?string $authType): array
    {
        $normalized = $this->redactArray($headers);
        $normalized['Accept'] = $normalized['Accept'] ?? 'application/json';

        if ($authType !== null && $authType !== '') {
            $normalized['Authorization'] = match ($authType) {
                'basic' => '[redacted basic auth]',
                'api_key' => '[redacted api key]',
                default => '[redacted bearer token]',
            };
        }

        return $normalized;
    }

    /**
     * @param  array<string, array<int, string>|string>  $headers
     * @return array<string, string>
     */
    protected function redactHeaders(array $headers): array
    {
        $redacted = [];

        foreach ($headers as $key => $value) {
            $headerValue = is_array($value) ? ($value[0] ?? '') : (string) $value;

            if ($this->isSensitiveKey((string) $key) || strcasecmp((string) $key, 'Authorization') === 0) {
                $redacted[$key] = '[redacted]';

                continue;
            }

            $redacted[$key] = $headerValue;
        }

        return $redacted;
    }

    protected function isSensitiveKey(string $key): bool
    {
        return (bool) preg_match(
            '/(password|secret|token|authorization|api[_-]?key|access[_-]?token|refresh[_-]?token|client[_-]?secret|credential)/i',
            $key,
        );
    }

    protected function truncateBody(string $body): ?string
    {
        if ($body === '') {
            return null;
        }

        $maxBytes = (int) config('titan.connector_api_logs.max_body_bytes', 100_000);

        if (strlen($body) <= $maxBytes) {
            return $body;
        }

        return substr($body, 0, $maxBytes)."\n...[truncated]";
    }
}
