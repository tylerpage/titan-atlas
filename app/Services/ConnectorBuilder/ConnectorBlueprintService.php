<?php

namespace App\Services\ConnectorBuilder;

use App\Enums\ConnectorBlueprintStatus;
use App\Models\ClientDashboard;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\ConnectorBuilderSession;
use App\Support\DynamicConnectorAuth;
use App\Support\DynamicConnectorReadOnlyGuard;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ConnectorBlueprintService
{
    public function __construct(protected DynamicConnectorReadOnlyGuard $readOnlyGuard) {}
    /**
     * @param  array<string, mixed>  $data
     */
    public function upsert(
        ClientDashboard $dashboard,
        ConnectorBuilderSession $session,
        array $data,
    ): ConnectorBlueprint {
        $slug = Str::slug((string) ($data['slug'] ?? $data['label'] ?? 'connector'));

        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => 'Blueprint slug is required.']);
        }

        $authConfig = DynamicConnectorAuth::normalize(is_array($data['auth_config'] ?? null) ? $data['auth_config'] : null);

        if (is_array($authConfig) && isset($authConfig['type'])) {
            DynamicConnectorAuth::assertAllowedType((string) $authConfig['type']);
        }

        $blueprint = ConnectorBlueprint::query()->updateOrCreate(
            [
                'company_id' => $dashboard->company_id,
                'slug' => $slug,
            ],
            [
                'client_dashboard_id' => $dashboard->id,
                'connector_builder_session_id' => $session->id,
                'label' => (string) ($data['label'] ?? Str::headline($slug)),
                'status' => ConnectorBlueprintStatus::tryFrom($data['status'] ?? '') ?? ConnectorBlueprintStatus::Draft,
                'original_prompt' => $data['original_prompt'] ?? $session->title,
                'auth_config' => $authConfig,
                'credential_schema' => DynamicConnectorAuth::normalizeCredentialSchema(
                    is_array($data['credential_schema'] ?? null) ? $data['credential_schema'] : null,
                    $authConfig,
                ),
                'sync_config' => $data['sync_config'] ?? null,
                'transform_config' => $data['transform_config'] ?? null,
                'dashboard_spec' => $data['dashboard_spec'] ?? null,
                'dev_tasks' => $data['dev_tasks'] ?? [],
            ],
        );

        if (isset($data['streams']) && is_array($data['streams'])) {
            $this->syncStreams($blueprint, $this->readOnlyGuard->sanitizeStreams($data['streams']));
        }

        return $blueprint->fresh(['streams']);
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     */
    public function syncStreams(ConnectorBlueprint $blueprint, array $streams): void
    {
        $maxStreams = (int) config('titan.connector_builder.max_streams_per_blueprint', 8);
        $streams = array_slice($streams, 0, $maxStreams);

        $streamKeys = [];

        foreach ($streams as $stream) {
            $streamKey = (string) ($stream['stream_key'] ?? '');

            if ($streamKey === '') {
                continue;
            }

            $streamKeys[] = $streamKey;

            ConnectorBlueprintStream::query()->updateOrCreate(
                [
                    'connector_blueprint_id' => $blueprint->id,
                    'stream_key' => $streamKey,
                ],
                [
                    'resource_type' => (string) ($stream['resource_type'] ?? $streamKey),
                    'http_method' => $this->readOnlyGuard->normalizeHttpMethod($stream['http_method'] ?? null),
                    'path_template' => (string) ($stream['path_template'] ?? '/'),
                    'query_params' => $stream['query_params'] ?? [],
                    'request_body' => $stream['request_body'] ?? null,
                    'request_body_format' => (string) ($stream['request_body_format'] ?? 'json'),
                    'headers' => $stream['headers'] ?? [],
                    'pagination' => $stream['pagination'] ?? ['type' => 'none'],
                    'response_mapping' => $stream['response_mapping'] ?? [
                        'records_path' => 'results',
                        'id_path' => 'id',
                        'date_path' => 'date',
                    ],
                    'enabled' => (bool) ($stream['enabled'] ?? true),
                ],
            );
        }

        if ($streamKeys !== []) {
            $blueprint->streams()
                ->whereNotIn('stream_key', $streamKeys)
                ->delete();
        }
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     */
    public function appendDevTasks(ConnectorBlueprint $blueprint, array $tasks): ConnectorBlueprint
    {
        $existing = $blueprint->dev_tasks ?? [];
        $merged = array_values(array_merge($existing, $tasks));

        $blueprint->update([
            'dev_tasks' => $merged,
            'status' => ConnectorBlueprintStatus::NeedsDev,
        ]);

        return $blueprint->fresh(['streams']);
    }
}
