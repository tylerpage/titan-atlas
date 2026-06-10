<?php

namespace App\Support;

use App\Models\Connection;
use App\Models\ConnectorBlueprint;

class DynamicConnectorCredentials
{
    /**
     * @return list<string>
     */
    public static function keys(?ConnectorBlueprint $blueprint): array
    {
        if ($blueprint === null) {
            return [];
        }

        return collect($blueprint->credential_schema ?? [])
            ->pluck('key')
            ->filter(fn ($key) => is_string($key) && $key !== '')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function fields(?ConnectorBlueprint $blueprint): array
    {
        if ($blueprint === null) {
            return [];
        }

        $fields = $blueprint->credential_schema ?? [];

        return is_array($fields) ? $fields : [];
    }

    public static function blueprintFor(Connection $connection): ?ConnectorBlueprint
    {
        if (! $connection->isDynamic()) {
            return null;
        }

        $connection->loadMissing('connectorBlueprint');

        return $connection->connectorBlueprint;
    }
}
