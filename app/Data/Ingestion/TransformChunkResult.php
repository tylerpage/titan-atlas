<?php

namespace App\Data\Ingestion;

readonly class TransformChunkResult
{
    public function __construct(
        public int $written,
        public bool $hasMore,
        public ?int $lastPayloadId,
    ) {}
}
