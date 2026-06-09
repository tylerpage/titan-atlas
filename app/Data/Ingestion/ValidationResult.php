<?php

namespace App\Data\Ingestion;

readonly class ValidationResult
{
    /**
     * @param  array<string, mixed>  $debug
     */
    public function __construct(
        public bool $valid,
        public ?string $message = null,
        public array $debug = [],
    ) {}

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function ok(?string $message = null, array $debug = []): self
    {
        return new self(true, $message, $debug);
    }

    /**
     * @param  array<string, mixed>  $debug
     */
    public static function fail(string $message, array $debug = []): self
    {
        return new self(false, $message, $debug);
    }
}
