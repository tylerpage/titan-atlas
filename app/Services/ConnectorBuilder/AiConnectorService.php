<?php

namespace App\Services\ConnectorBuilder;

use App\Enums\ConnectorBlueprintStatus;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBlueprint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AiConnectorService
{
    /**
     * @return Collection<int, ConnectorBlueprint>
     */
    public function listForCompany(Company $company): Collection
    {
        return ConnectorBlueprint::query()
            ->where('company_id', $company->id)
            ->withCount('connections')
            ->with(['streams', 'dashboard'])
            ->orderBy('label')
            ->get();
    }

    /**
     * @return Collection<int, ConnectorBlueprint>
     */
    public function templatesForDashboard(ClientDashboard $dashboard): Collection
    {
        return ConnectorBlueprint::query()
            ->where('company_id', $dashboard->company_id)
            ->where(function ($query) use ($dashboard) {
                $query->whereNull('client_dashboard_id')
                    ->orWhere('client_dashboard_id', $dashboard->id);
            })
            ->whereIn('status', [
                ConnectorBlueprintStatus::Ready->value,
                ConnectorBlueprintStatus::Active->value,
                ConnectorBlueprintStatus::Draft->value,
            ])
            ->orderBy('label')
            ->get();
    }

    public function isAvailableForDashboard(ConnectorBlueprint $blueprint, ClientDashboard $dashboard): bool
    {
        if ($blueprint->company_id !== $dashboard->company_id) {
            return false;
        }

        return $blueprint->client_dashboard_id === null
            || $blueprint->client_dashboard_id === $dashboard->id;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(ConnectorBlueprint $blueprint, array $data): ConnectorBlueprint
    {
        $label = trim((string) ($data['label'] ?? $blueprint->label));

        if ($label === '') {
            throw ValidationException::withMessages(['label' => 'Label is required.']);
        }

        $slug = Str::slug((string) ($data['slug'] ?? $label));

        if ($slug === '') {
            throw ValidationException::withMessages(['slug' => 'Slug is required.']);
        }

        $exists = ConnectorBlueprint::query()
            ->where('company_id', $blueprint->company_id)
            ->where('slug', $slug)
            ->whereKeyNot($blueprint->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages(['slug' => 'Another AI connector in this company already uses that slug.']);
        }

        $status = ConnectorBlueprintStatus::tryFrom((string) ($data['status'] ?? '')) ?? $blueprint->status;

        $blueprint->update([
            'label' => $label,
            'slug' => $slug,
            'status' => $status,
        ]);

        return $blueprint->fresh(['streams', 'connections', 'dashboard', 'company']);
    }

    public function share(ConnectorBlueprint $blueprint): ConnectorBlueprint
    {
        $blueprint->update([
            'client_dashboard_id' => null,
            'status' => $blueprint->status === ConnectorBlueprintStatus::Draft
                ? ConnectorBlueprintStatus::Ready
                : $blueprint->status,
        ]);

        return $blueprint->fresh(['streams', 'connections', 'dashboard', 'company']);
    }

    public function delete(ConnectorBlueprint $blueprint): void
    {
        if ($blueprint->connections()->exists()) {
            throw ValidationException::withMessages([
                'blueprint' => 'Remove or reassign dashboard connections using this AI connector before deleting it.',
            ]);
        }

        $blueprint->delete();
    }
}
