<?php

namespace App\Support;

use Illuminate\Support\Str;

class EcommerceConnectorCatalog
{
    protected ?array $catalog = null;

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->load();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function platforms(): array
    {
        $catalog = $this->load();

        return is_array($catalog['platforms'] ?? null) ? $catalog['platforms'] : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function supportedPlatforms(?string $category = null): array
    {
        return collect($this->platforms())
            ->filter(function (array $platform) use ($category) {
                if ($category !== null && ($platform['category'] ?? null) !== $category) {
                    return false;
                }

                return (bool) ($platform['dynamic_connector']['supported'] ?? false);
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(?string $query): ?array
    {
        $query = trim((string) $query);

        if ($query === '') {
            return null;
        }

        $needle = Str::lower($query);

        return collect($this->platforms())->first(function (array $platform) use ($needle) {
            $slug = Str::lower((string) ($platform['slug'] ?? ''));
            $name = Str::lower((string) ($platform['platform'] ?? ''));

            return $needle === $slug
                || $needle === $name
                || Str::contains($name, $needle)
                || Str::contains($slug, $needle);
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    public function blueprintRecipe(?string $query): ?array
    {
        $platform = $this->find($query);

        if ($platform === null) {
            return null;
        }

        $recipe = $platform['blueprint_template'] ?? null;

        return is_array($recipe) ? $recipe : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function lookup(?string $query, ?string $category = null): array
    {
        if ($query === null || trim($query) === '') {
            return [
                'success' => true,
                'schema_version' => $this->schemaVersion(),
                'purpose' => $this->purpose(),
                'supported_platforms' => collect($this->supportedPlatforms($category))
                    ->map(fn (array $platform) => $this->summarizePlatform($platform))
                    ->values()
                    ->all(),
                'usage' => $this->usageNotes(),
            ];
        }

        $platform = $this->find($query);

        if ($platform === null) {
            return [
                'success' => false,
                'error' => "No catalog entry matched [{$query}].",
                'supported_platforms' => collect($this->supportedPlatforms($category))
                    ->pluck('platform')
                    ->values()
                    ->all(),
            ];
        }

        return [
            'success' => true,
            'platform' => $this->summarizePlatform($platform, includeRecipe: true),
            'usage' => $this->usageNotes(),
        ];
    }

    public function agentPromptSummary(): string
    {
        $supported = collect($this->supportedPlatforms())
            ->map(fn (array $platform) => sprintf(
                '- %s (%s)',
                $platform['platform'] ?? 'Unknown',
                $platform['slug'] ?? 'unknown',
            ))
            ->implode("\n");

        if ($supported === '') {
            $supported = '- none configured';
        }

        return <<<SUMMARY
Curated dynamic connector recipes are available in docs/ecommerce_connector_catalog.json.
Before SaveConnectorBlueprintTool, call LookupConnectorCatalogTool for ecommerce platforms (especially shopware, magento, woocommerce, miva).
Supported recipes:
{$supported}
SUMMARY;
    }

    protected function schemaVersion(): int
    {
        return (int) ($this->load()['schema_version'] ?? 1);
    }

    protected function purpose(): string
    {
        return (string) ($this->load()['purpose'] ?? 'Curated dynamic connector recipes for Titan.');
    }

    /**
     * @return list<string>
     */
    protected function usageNotes(): array
    {
        return [
            'Use blueprint_template values directly in SaveConnectorBlueprintTool when dynamic_connector.supported is true.',
            'Keep read-only external access only. Do not configure create/update/delete endpoints.',
            'Set sync_config.require_base_url_per_dashboard when each dashboard has its own shop URL.',
            'Use stream resource_type values exactly in dashboard SQL and ListBlueprintAnalyticsSchemaTool output.',
            'RecordDevTasksTool for OAuth authorization-code flows, HMAC-only APIs without token fallback, GraphQL, and webhooks.',
        ];
    }

    /**
     * @param  array<string, mixed>  $platform
     * @return array<string, mixed>
     */
    protected function summarizePlatform(array $platform, bool $includeRecipe = false): array
    {
        $summary = [
            'platform' => $platform['platform'] ?? null,
            'slug' => $platform['slug'] ?? null,
            'category' => $platform['category'] ?? null,
            'documentation_url' => $platform['documentation_url'] ?? null,
            'dynamic_connector' => $platform['dynamic_connector'] ?? [],
            'authentication' => $platform['authentication'] ?? [],
            'resources' => $platform['resources'] ?? [],
            'metrics' => $platform['metrics'] ?? [],
            'dimensions' => $platform['dimensions'] ?? [],
            'join_keys' => $platform['join_keys'] ?? [],
            'pagination' => $platform['pagination'] ?? null,
            'incremental_field' => $platform['incremental_field'] ?? null,
            'supports_webhooks' => $platform['supports_webhooks'] ?? false,
            'unsupported_in_v1' => $platform['unsupported_in_v1'] ?? [],
        ];

        if ($includeRecipe && is_array($platform['blueprint_template'] ?? null)) {
            $summary['blueprint_template'] = $platform['blueprint_template'];
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    protected function load(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        $path = base_path('docs/ecommerce_connector_catalog.json');

        if (! is_file($path)) {
            $this->catalog = ['schema_version' => 1, 'platforms' => []];

            return $this->catalog;
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->catalog = ['schema_version' => 1, 'platforms' => []];

            return $this->catalog;
        }

        if (array_is_list($decoded)) {
            $this->catalog = [
                'schema_version' => 1,
                'purpose' => 'Legacy list-format catalog entries.',
                'platforms' => $decoded,
            ];

            return $this->catalog;
        }

        $this->catalog = $decoded;

        return $this->catalog;
    }
}
