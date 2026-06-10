<?php

namespace App\Http\Requests\Admin;

use App\Models\ConnectorBlueprint;
use App\Support\DynamicConnectorBaseUrl;
use App\Support\DynamicConnectorCredentials;
use Illuminate\Foundation\Http\FormRequest;

class StoreAiConnectorConnectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ConnectorBlueprint $blueprint */
        $blueprint = $this->route('blueprint');

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'credentials' => ['required', 'array'],
        ];

        foreach (DynamicConnectorCredentials::keys($blueprint) as $key) {
            $rules["credentials.{$key}"] = ['required', 'string'];
        }

        if (DynamicConnectorBaseUrl::requiresPerDashboard($blueprint)) {
            $rules['credentials.base_url'] = ['required', 'string', 'url', 'max:500'];
        } elseif ($request->filled('credentials.base_url')) {
            $rules['credentials.base_url'] = ['nullable', 'string', 'url', 'max:500'];
        }

        return $rules;
    }
}
