<?php

namespace App\Services\ConnectorBuilder;

use App\Models\ConnectorBlueprint;
use App\Support\AiConnectorPortableFormat;

class AiConnectorExportService
{
    /**
     * @return array{filename: string, package: array<string, mixed>}
     */
    public function export(ConnectorBlueprint $blueprint): array
    {
        $blueprint->loadMissing('streams');

        $package = AiConnectorPortableFormat::exportPackage($blueprint);

        return [
            'filename' => $blueprint->slug.'-ai-connector.json',
            'package' => $package,
        ];
    }
}
