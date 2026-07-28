<?php

namespace App\Ingestion\Connectors\Contracts;

interface PaidMediaAdsApiClient
{
    /**
     * @return list<array{accountId: string, name: string, currency: string}>
     */
    public function listAccounts(string $accessToken): array;

    /**
     * @return array<string, mixed>
     */
    public function testConnection(string $accessToken, string $accountId): array;

    /**
     * @return list<array<string, mixed>>
     */
    public function reportRows(
        string $accessToken,
        string $accountId,
        string $startDate,
        string $endDate,
        string $stream,
    ): array;
}
