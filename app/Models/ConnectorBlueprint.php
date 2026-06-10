<?php

namespace App\Models;

use App\Enums\ConnectorBlueprintStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ConnectorBlueprint extends Model
{
    protected $fillable = [
        'company_id',
        'client_dashboard_id',
        'connector_builder_session_id',
        'slug',
        'label',
        'status',
        'original_prompt',
        'auth_config',
        'credential_schema',
        'sync_config',
        'transform_config',
        'dashboard_spec',
        'dev_tasks',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConnectorBlueprintStatus::class,
            'auth_config' => 'array',
            'credential_schema' => 'array',
            'sync_config' => 'array',
            'transform_config' => 'array',
            'dashboard_spec' => 'array',
            'dev_tasks' => 'array',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class, 'client_dashboard_id');
    }

    public function builderSession(): BelongsTo
    {
        return $this->belongsTo(ConnectorBuilderSession::class, 'connector_builder_session_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function streams(): HasMany
    {
        return $this->hasMany(ConnectorBlueprintStream::class);
    }

    public function isShared(): bool
    {
        return $this->client_dashboard_id === null;
    }

    /**
     * @return list<string>
     */
    public function resourceTypes(): array
    {
        return $this->streams()
            ->where('enabled', true)
            ->pluck('resource_type')
            ->unique()
            ->values()
            ->all();
    }

    public function baseUrl(): string
    {
        return rtrim((string) ($this->sync_config['base_url'] ?? ''), '/');
    }

    public function testEndpoint(): ?string
    {
        $endpoint = $this->sync_config['test_endpoint'] ?? null;

        return is_string($endpoint) && $endpoint !== '' ? $endpoint : null;
    }
}
