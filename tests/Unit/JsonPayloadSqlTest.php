<?php

namespace Tests\Unit;

use App\Support\JsonPayloadSql;
use Tests\TestCase;

class JsonPayloadSqlTest extends TestCase
{
    public function test_sqlite_text_and_real_expressions(): void
    {
        $this->assertSame(
            "json_extract(payload, '$.date')",
            JsonPayloadSql::text('payload', 'date'),
        );

        $this->assertSame(
            "cast(json_extract(payload, '$.total') as real)",
            JsonPayloadSql::real('payload', 'total'),
        );
    }

    public function test_normalize_sql_leaves_sqlite_queries_unchanged(): void
    {
        $sql = "SELECT json_extract(r.payload, '$.date') AS date WHERE json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date";

        $this->assertSame($sql, JsonPayloadSql::normalizeSql($sql));
    }
}
