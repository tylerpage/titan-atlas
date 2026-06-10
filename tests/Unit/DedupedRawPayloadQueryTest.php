<?php

namespace Tests\Unit;

use App\Support\DedupedRawPayloadQuery;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DedupedRawPayloadQueryTest extends TestCase
{
    public function test_sqlite_uses_latest_id_subquery(): void
    {
        $this->assertSame('sqlite', DB::connection()->getDriverName());

        $query = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            42,
            'search_daily',
        );

        $sql = $query->toSql();

        $this->assertStringContainsString('where "id" in', strtolower($sql));
        $this->assertStringContainsString('max(id)', strtolower($sql));
    }
}
