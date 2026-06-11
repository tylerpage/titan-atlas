<?php

namespace App\Support;

use App\Enums\ConnectorBlueprintStatus;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use Illuminate\Validation\ValidationException;

class AiConnectorPortableFormat
{
    public const FORMAT = 'titan-ai-connector';

    public const VERSION = 1;

    /**
     * @return array<string, mixed>
     */
    public static function exportPackage(ConnectorBlueprint $blueprint): array
    {
        $blueprint->loadMissing('streams');

        return [
            'format' => self::FORMAT,
            'format_version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'blueprint' => [
                'slug' => $blueprint->slug,
                'label' => $blueprint->label,
                'status' => $blueprint->status->value,
                'scope' => self::scopeFor($blueprint),
                'original_prompt' => $blueprint->original_prompt,
                'auth_config' => $blueprint->auth_config,
                'credential_schema' => $blueprint->credential_schema ?? [],
                'sync_config' => $blueprint->sync_config,
                'transform_config' => $blueprint->transform_config,
                'dashboard_spec' => self::sanitizeDashboardSpec($blueprint->dashboard_spec),
                'dev_tasks' => $blueprint->dev_tasks ?? [],
                'streams' => $blueprint->streams
                    ->map(fn (ConnectorBlueprintStream $stream) => self::exportStream($stream))
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>
     */
    public static function validatePackage(array $package): array
    {
        if (($package['format'] ?? null) !== self::FORMAT) {
            throw ValidationException::withMessages([
                'payload' => 'Unrecognized export format. Expected titan-ai-connector.',
            ]);
        }

        if ((int) ($package['format_version'] ?? 0) !== self::VERSION) {
            throw ValidationException::withMessages([
                'payload' => 'Unsupported export version. This environment supports version '.self::VERSION.'.',
            ]);
        }

        $blueprint = $package['blueprint'] ?? null;

        if (! is_array($blueprint)) {
            throw ValidationException::withMessages([
                'payload' => 'Export is missing blueprint data.',
            ]);
        }

        $slug = trim((string) ($blueprint['slug'] ?? ''));

        if ($slug === '') {
            throw ValidationException::withMessages([
                'payload' => 'Export is missing blueprint slug.',
            ]);
        }

        $streams = $blueprint['streams'] ?? [];

        if (! is_array($streams) || $streams === []) {
            throw ValidationException::withMessages([
                'payload' => 'Export must include at least one stream.',
            ]);
        }

        return $blueprint;
    }

    public static function scopeFor(ConnectorBlueprint $blueprint): string
    {
        if ($blueprint->isGlobal()) {
            return 'global';
        }

        if ($blueprint->isShared()) {
            return 'company';
        }

        return 'dashboard';
    }

    /**
     * @param  array<string, mixed>|null  $spec
     * @return array<string, mixed>|null
     */
    public static function sanitizeDashboardSpec(?array $spec): ?array
    {
        if (! is_array($spec)) {
            return null;
        }

        $sanitized = $spec;

        foreach ([
            'saved_dashboard_id',
            'saved_dashboard_title',
            'saved_dashboard_url',
            'created_report_ids',
            'pinned_blocks',
            'current_version',
            'client_dashboard_id',
            'pending_payload_refresh',
            'versions',
        ] as $key) {
            unset($sanitized[$key]);
        }

        if (isset($sanitized['widgets']) && is_array($sanitized['widgets'])) {
            $sanitized['widgets'] = array_values(array_map(function ($widget) {
                if (! is_array($widget)) {
                    return $widget;
                }

                unset($widget['connection_id']);

                if (isset($widget['visualization_config']) && is_array($widget['visualization_config'])) {
                    unset($widget['visualization_config']['connection_id']);
                }

                return $widget;
            }, $sanitized['widgets']));
        }

        return $sanitized;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function exportStream(ConnectorBlueprintStream $stream): array
    {
        return [
            'stream_key' => $stream->stream_key,
            'resource_type' => $stream->resource_type,
            'http_method' => $stream->http_method,
            'path_template' => $stream->path_template,
            'query_params' => $stream->query_params ?? [],
            'request_body' => $stream->request_body,
            'request_body_format' => $stream->request_body_format,
            'headers' => $stream->headers ?? [],
            'pagination' => $stream->pagination ?? ['type' => 'none'],
            'response_mapping' => $stream->response_mapping ?? [],
            'enabled' => (bool) $stream->enabled,
        ];
    }

    public static function resolveStatus(string $value): ConnectorBlueprintStatus
    {
        return ConnectorBlueprintStatus::tryFrom($value) ?? ConnectorBlueprintStatus::Ready;
    }
}
