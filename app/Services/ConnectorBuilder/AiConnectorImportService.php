<?php

namespace App\Services\ConnectorBuilder;

use App\Models\Company;
use App\Models\ConnectorBlueprint;
use App\Support\AiConnectorPortableFormat;
use App\Support\DynamicConnectorAuth;
use App\Support\DynamicConnectorReadOnlyGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AiConnectorImportService
{
    public function __construct(
        protected ConnectorBlueprintService $blueprints,
        protected DynamicConnectorReadOnlyGuard $readOnlyGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $package
     * @param  array{scope: string, mode: string, company_id?: int|null}  $options
     */
    public function import(array $package, array $options): ConnectorBlueprint
    {
        $blueprintData = AiConnectorPortableFormat::validatePackage($package);

        $scope = (string) ($options['scope'] ?? 'global');
        $mode = (string) ($options['mode'] ?? 'create');
        $companyId = $options['company_id'] ?? null;

        if ($scope === 'company' && ! is_numeric($companyId)) {
            throw ValidationException::withMessages([
                'company_id' => 'Select a company when importing a company-scoped connector.',
            ]);
        }

        if ($scope === 'global') {
            return $this->importGlobal($blueprintData, $mode);
        }

        return $this->importCompany($blueprintData, (int) $companyId, $mode);
    }

    /**
     * @param  array<string, mixed>  $blueprintData
     */
    protected function importGlobal(array $blueprintData, string $mode): ConnectorBlueprint
    {
        $slug = (string) $blueprintData['slug'];
        $existing = ConnectorBlueprint::query()
            ->where('is_global', true)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null && $mode === 'create') {
            throw ValidationException::withMessages([
                'mode' => "A global connector with slug [{$slug}] already exists. Import again using Replace existing.",
            ]);
        }

        return DB::transaction(function () use ($blueprintData, $existing) {
            $attributes = $this->blueprintAttributes($blueprintData, [
                'is_global' => true,
                'company_id' => null,
                'client_dashboard_id' => null,
            ]);

            if ($existing !== null) {
                $existing->update($attributes);
                $blueprint = $existing;
            } else {
                $blueprint = ConnectorBlueprint::query()->create(array_merge([
                    'slug' => $blueprintData['slug'],
                ], $attributes));
            }

            $this->syncStreams($blueprint, $blueprintData['streams'] ?? []);

            return $blueprint->fresh(['streams', 'company', 'dashboard']);
        });
    }

    /**
     * @param  array<string, mixed>  $blueprintData
     */
    protected function importCompany(array $blueprintData, int $companyId, string $mode): ConnectorBlueprint
    {
        Company::query()->findOrFail($companyId);

        $slug = (string) $blueprintData['slug'];
        $existing = ConnectorBlueprint::query()
            ->where('company_id', $companyId)
            ->where('is_global', false)
            ->where('slug', $slug)
            ->first();

        if ($existing !== null && $mode === 'create') {
            throw ValidationException::withMessages([
                'mode' => "A company connector with slug [{$slug}] already exists. Import again using Replace existing.",
            ]);
        }

        return DB::transaction(function () use ($blueprintData, $companyId, $existing) {
            $attributes = $this->blueprintAttributes($blueprintData, [
                'is_global' => false,
                'company_id' => $companyId,
                'client_dashboard_id' => null,
            ]);

            if ($existing !== null) {
                $existing->update($attributes);
                $blueprint = $existing;
            } else {
                $blueprint = ConnectorBlueprint::query()->create(array_merge([
                    'slug' => $blueprintData['slug'],
                ], $attributes));
            }

            $this->syncStreams($blueprint, $blueprintData['streams'] ?? []);

            return $blueprint->fresh(['streams', 'company', 'dashboard']);
        });
    }

    /**
     * @param  array<string, mixed>  $blueprintData
     * @param  array<string, mixed>  $scopeAttributes
     * @return array<string, mixed>
     */
    protected function blueprintAttributes(array $blueprintData, array $scopeAttributes): array
    {
        $authConfig = DynamicConnectorAuth::normalize(
            is_array($blueprintData['auth_config'] ?? null) ? $blueprintData['auth_config'] : null,
        );

        if (is_array($authConfig) && isset($authConfig['type'])) {
            DynamicConnectorAuth::assertAllowedType((string) $authConfig['type']);
        }

        return array_merge($scopeAttributes, [
            'connector_builder_session_id' => null,
            'label' => (string) ($blueprintData['label'] ?? $blueprintData['slug']),
            'status' => AiConnectorPortableFormat::resolveStatus((string) ($blueprintData['status'] ?? 'ready')),
            'original_prompt' => $blueprintData['original_prompt'] ?? null,
            'auth_config' => $authConfig,
            'credential_schema' => DynamicConnectorAuth::normalizeCredentialSchema(
                is_array($blueprintData['credential_schema'] ?? null) ? $blueprintData['credential_schema'] : null,
                $authConfig,
            ),
            'sync_config' => $blueprintData['sync_config'] ?? null,
            'transform_config' => $blueprintData['transform_config'] ?? null,
            'dashboard_spec' => AiConnectorPortableFormat::sanitizeDashboardSpec(
                is_array($blueprintData['dashboard_spec'] ?? null) ? $blueprintData['dashboard_spec'] : null,
            ),
            'dev_tasks' => is_array($blueprintData['dev_tasks'] ?? null) ? $blueprintData['dev_tasks'] : [],
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $streams
     */
    protected function syncStreams(ConnectorBlueprint $blueprint, array $streams): void
    {
        $this->blueprints->syncStreams(
            $blueprint,
            $this->readOnlyGuard->sanitizeStreams($streams),
        );
    }
}
