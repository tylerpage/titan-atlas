<?php

namespace App\Ingestion\Connectors;

use App\Contracts\Ingestion\FanOutSyncConnector;
use App\Data\Ingestion\FetchResult;
use App\Data\Ingestion\ValidationResult;
use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Dynamic\DynamicHttpClient;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use Illuminate\Support\Arr;

class DynamicConnector extends AbstractConnector implements FanOutSyncConnector
{
    public function __construct(protected DynamicHttpClient $client) {}

    public function type(): string
    {
        return ConnectorType::Dynamic->value;
    }

    /**
     * @return list<string>
     */
    public function syncStreams(): array
    {
        return ['sync'];
    }

    public function initialSyncCursor(Connection $connection, string $stream, bool $fanOut = false): string
    {
        return $this->encodeCursor([
            'stream' => $stream,
            'page' => 1,
            'after' => null,
            'fan_out' => $fanOut,
        ]);
    }

    protected function validateConnectorCredentials(Connection $connection, array $credentials): ValidationResult
    {
        $blueprint = $this->blueprintFor($connection);

        if ($blueprint === null) {
            return ValidationResult::fail('Dynamic connector is missing a blueprint.');
        }

        foreach ($blueprint->credential_schema ?? [] as $field) {
            $key = $field['key'] ?? null;

            if ($key && empty($credentials[$key])) {
                return ValidationResult::fail("Missing required credential: {$key}.");
            }
        }

        try {
            $this->probeConnection($blueprint, $credentials);
        } catch (\Throwable $e) {
            return ValidationResult::fail('Could not connect to the API.', ['hint' => $e->getMessage()]);
        }

        return ValidationResult::ok('Connected to '.$blueprint->label);
    }

    public function fetch(Connection $connection, ?string $cursor = null): FetchResult
    {
        $blueprint = $this->blueprintFor($connection);

        if ($blueprint === null) {
            return new FetchResult(records: [], hasMore: false);
        }

        $credentials = $connection->credentials();
        $state = $this->decodeCursor($cursor, $blueprint);
        $stream = $this->streamFor($blueprint, $state['stream']);

        if ($stream === null) {
            return new FetchResult(records: [], hasMore: false);
        }

        $queryParams = array_merge(
            $stream->query_params ?? [],
            $this->paginationQueryParams($stream, $state),
        );

        $response = $this->client->request(
            blueprint: $blueprint,
            credentials: $credentials,
            method: $stream->http_method ?? 'GET',
            path: $stream->path_template,
            queryParams: $queryParams,
            headers: $stream->headers ?? [],
            body: $stream->request_body ?? [],
            bodyFormat: (string) ($stream->response_mapping['body_format'] ?? 'json'),
        );

        $mapping = $stream->response_mapping ?? [];
        $rawRecords = $this->client->extractRecords($response, $mapping);
        $records = [];

        foreach ($rawRecords as $record) {
            $normalized = $this->client->normalizeRecord($record, $mapping, $stream->resource_type);
            $records[] = $normalized;
        }

        $nextState = $this->nextCursorState($stream, $state, $response, count($rawRecords));

        if ($nextState !== null) {
            return new FetchResult(
                records: $records,
                nextCursor: $this->encodeCursor($nextState),
                hasMore: true,
            );
        }

        if (! empty($state['fan_out'])) {
            return new FetchResult(records: $records, hasMore: false);
        }

        $nextStream = $this->nextStream($blueprint, $state['stream']);

        if ($nextStream !== null) {
            return new FetchResult(
                records: $records,
                nextCursor: $this->encodeCursor([
                    'stream' => $nextStream,
                    'page' => 1,
                    'after' => null,
                    'fan_out' => false,
                ]),
                hasMore: true,
            );
        }

        return new FetchResult(records: $records, hasMore: false);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function probeConnection(ConnectorBlueprint $blueprint, array $credentials): array
    {
        if ($blueprint->testEndpoint() !== null) {
            $testRequest = $blueprint->sync_config['test_request'] ?? null;

            if (is_array($testRequest)) {
                return $this->client->request(
                    blueprint: $blueprint,
                    credentials: $credentials,
                    method: (string) ($testRequest['method'] ?? 'GET'),
                    path: (string) ($testRequest['path'] ?? $blueprint->testEndpoint()),
                    queryParams: is_array($testRequest['query_params'] ?? null) ? $testRequest['query_params'] : [],
                    headers: is_array($testRequest['headers'] ?? null) ? $testRequest['headers'] : [],
                    body: is_array($testRequest['body'] ?? null) ? $testRequest['body'] : [],
                    bodyFormat: (string) ($testRequest['body_format'] ?? 'json'),
                );
            }

            return $this->client->request(
                blueprint: $blueprint,
                credentials: $credentials,
                method: 'GET',
                path: $blueprint->testEndpoint(),
            );
        }

        $stream = $blueprint->streams()->where('enabled', true)->orderBy('id')->first();

        if ($stream === null) {
            throw new \RuntimeException('Blueprint has no enabled streams to test.');
        }

        $queryParams = array_merge($stream->query_params ?? [], ['limit' => 1]);

        return $this->client->request(
            blueprint: $blueprint,
            credentials: $credentials,
            method: $stream->http_method ?? 'GET',
            path: $stream->path_template,
            queryParams: $queryParams,
            headers: $stream->headers ?? [],
            body: $stream->request_body ?? [],
            bodyFormat: (string) ($stream->response_mapping['body_format'] ?? 'json'),
        );
    }

    protected function blueprintFor(Connection $connection): ?ConnectorBlueprint
    {
        $connection->loadMissing('connectorBlueprint.streams');

        return $connection->connectorBlueprint;
    }

    /**
     * @return \Illuminate\Support\Collection<int, ConnectorBlueprintStream>
     */
    protected function enabledStreams(Connection $connection)
    {
        $blueprint = $this->blueprintFor($connection);

        return $blueprint?->streams()->where('enabled', true)->orderBy('id')->get() ?? collect();
    }

    protected function streamFor(ConnectorBlueprint $blueprint, string $streamKey): ?ConnectorBlueprintStream
    {
        return $blueprint->streams
            ->first(fn (ConnectorBlueprintStream $stream) => $stream->stream_key === $streamKey && $stream->enabled);
    }

    protected function nextStream(ConnectorBlueprint $blueprint, string $currentStream): ?string
    {
        $streams = $blueprint->streams->where('enabled', true)->sortBy('id')->values();
        $index = $streams->search(fn (ConnectorBlueprintStream $stream) => $stream->stream_key === $currentStream);

        if ($index === false) {
            return null;
        }

        $next = $streams->get($index + 1);

        return $next?->stream_key;
    }

    /**
     * @return array{stream: string, page: int, after: ?string, fan_out: bool}
     */
    protected function decodeCursor(?string $cursor, ConnectorBlueprint $blueprint): array
    {
        if ($cursor === null || $cursor === '') {
            $first = $blueprint->streams->where('enabled', true)->sortBy('id')->first();

            return [
                'stream' => $first?->stream_key ?? 'default',
                'page' => 1,
                'after' => null,
                'fan_out' => false,
            ];
        }

        $decoded = json_decode($cursor, true);

        if (! is_array($decoded)) {
            throw new \RuntimeException('Invalid dynamic connector cursor.');
        }

        return [
            'stream' => (string) ($decoded['stream'] ?? 'default'),
            'page' => (int) ($decoded['page'] ?? 1),
            'after' => isset($decoded['after']) ? (string) $decoded['after'] : null,
            'fan_out' => (bool) ($decoded['fan_out'] ?? false),
        ];
    }

    /**
     * @param  array{stream: string, page: int, after: ?string, fan_out: bool}  $state
     */
    protected function encodeCursor(array $state): string
    {
        return json_encode($state, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array{stream: string, page: int, after: ?string, fan_out: bool}  $state
     * @return array<string, mixed>
     */
    protected function paginationQueryParams(ConnectorBlueprintStream $stream, array $state): array
    {
        $pagination = $stream->pagination ?? [];
        $type = $pagination['type'] ?? 'none';

        return match ($type) {
            'offset' => [
                ($pagination['limit_param'] ?? 'limit') => (int) ($pagination['page_size'] ?? 100),
                ($pagination['offset_param'] ?? 'offset') => max(0, ($state['page'] - 1) * (int) ($pagination['page_size'] ?? 100)),
            ],
            'cursor' => array_filter([
                ($pagination['limit_param'] ?? 'limit') => (int) ($pagination['page_size'] ?? 100),
                ($pagination['cursor_param'] ?? 'after') => $state['after'],
            ], fn ($value) => $value !== null && $value !== ''),
            default => [],
        };
    }

    /**
     * @param  array{stream: string, page: int, after: ?string, fan_out: bool}  $state
     * @param  array<string, mixed>  $response
     * @return array{stream: string, page: int, after: ?string, fan_out: bool}|null
     */
    protected function nextCursorState(
        ConnectorBlueprintStream $stream,
        array $state,
        array $response,
        int $recordCount,
    ): ?array {
        $pagination = $stream->pagination ?? [];
        $type = $pagination['type'] ?? 'none';
        $pageSize = (int) ($pagination['page_size'] ?? 100);

        return match ($type) {
            'offset' => $recordCount >= $pageSize
                ? ['stream' => $state['stream'], 'page' => $state['page'] + 1, 'after' => null, 'fan_out' => $state['fan_out']]
                : null,
            'cursor' => $this->nextCursorPage($state, $response, $pagination, $recordCount, $pageSize),
            default => null,
        };
    }

    /**
     * @param  array{stream: string, page: int, after: ?string, fan_out: bool}  $state
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $pagination
     * @return array{stream: string, page: int, after: ?string, fan_out: bool}|null
     */
    protected function nextCursorPage(array $state, array $response, array $pagination, int $recordCount, int $pageSize): ?array
    {
        $nextPath = $pagination['next_cursor_path'] ?? 'paging.next.after';
        $nextAfter = Arr::get($response, $nextPath);

        if ($nextAfter !== null && $nextAfter !== '') {
            return [
                'stream' => $state['stream'],
                'page' => $state['page'] + 1,
                'after' => (string) $nextAfter,
                'fan_out' => $state['fan_out'],
            ];
        }

        if ($recordCount >= $pageSize && ! empty($pagination['cursor_param'])) {
            return [
                'stream' => $state['stream'],
                'page' => $state['page'] + 1,
                'after' => $state['after'],
                'fan_out' => $state['fan_out'],
            ];
        }

        return null;
    }
}
