<?php

namespace App\Data\Ingestion;

readonly class FetchResult
{
    /**
     * @param  list<array{resource_type: string, external_id?: string|null, payload: array<string, mixed>}>  $records
     */
    public function __construct(
        public array $records,
        public ?string $nextCursor = null,
        public bool $hasMore = false,
        public ?string $chunkDateFrom = null,
        public ?string $chunkDateThrough = null,
    ) {}
}
