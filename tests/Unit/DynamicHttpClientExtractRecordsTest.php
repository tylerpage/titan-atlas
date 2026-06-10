<?php

namespace Tests\Unit;

use App\Ingestion\Connectors\Dynamic\DynamicHttpClient;
use Tests\TestCase;

class DynamicHttpClientExtractRecordsTest extends TestCase
{
    public function test_it_extracts_root_level_record_arrays(): void
    {
        $client = app(DynamicHttpClient::class);

        $records = $client->extractRecords([
            ['id' => 1, 'total' => '10.00'],
            ['id' => 2, 'total' => '20.00'],
        ], [
            'records_path' => '@root',
        ]);

        $this->assertCount(2, $records);
        $this->assertSame(1, $records[0]['id']);
    }
}
