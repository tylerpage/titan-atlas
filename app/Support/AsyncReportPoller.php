<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AsyncReportPoller
{
    /**
     * @template T
     *
     * @param  callable(): T  $fetchStatus
     * @param  callable(T): bool  $isReady
     * @param  callable(T): bool  $isFailed
     * @return T
     */
    public function waitUntilReady(
        callable $fetchStatus,
        callable $isReady,
        callable $isFailed,
        ?int $maxAttempts = null,
        ?int $sleepMs = null,
    ): mixed {
        $maxAttempts = max(1, $maxAttempts ?? (int) config('titan.reports.poll_max_attempts', 30));
        $sleepMs = max(100, $sleepMs ?? (int) config('titan.reports.poll_sleep_ms', 2000));

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            $status = $fetchStatus();

            if ($isReady($status)) {
                return $status;
            }

            if ($isFailed($status)) {
                throw new RuntimeException('Async report failed.');
            }

            if ($attempt < $maxAttempts) {
                usleep($sleepMs * 1000);
            }
        }

        throw new RuntimeException('Async report timed out before becoming ready.');
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function downloadJsonRows(string $url, ?string $accessToken = null, int $timeoutSeconds = 120): array
    {
        $pending = Http::timeout($timeoutSeconds);

        if ($accessToken !== null && $accessToken !== '') {
            $pending = $pending->withToken($accessToken);
        }

        $response = $pending->get($url);

        if (! $response->successful()) {
            throw new RuntimeException('Could not download async report (HTTP '.$response->status().').');
        }

        $body = $response->body();

        if ($this->looksLikeGzip($body)) {
            $decoded = gzdecode($body);

            if ($decoded === false) {
                throw new RuntimeException('Could not decode gzip report payload.');
            }

            $body = $decoded;
        }

        $json = json_decode($body, true);

        if (! is_array($json)) {
            return [];
        }

        if (array_is_list($json)) {
            return array_values(array_filter($json, fn ($row) => is_array($row)));
        }

        foreach (['rows', 'records', 'data', 'reportData'] as $key) {
            if (isset($json[$key]) && is_array($json[$key]) && array_is_list($json[$key])) {
                return array_values(array_filter($json[$key], fn ($row) => is_array($row)));
            }
        }

        return [];
    }

    protected function looksLikeGzip(string $body): bool
    {
        return strlen($body) >= 2 && str_starts_with($body, "\x1f\x8b");
    }
}
