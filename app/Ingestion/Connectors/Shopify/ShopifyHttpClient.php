<?php

namespace App\Ingestion\Connectors\Shopify;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

class ShopifyHttpClient
{
    public function __construct(protected string $token) {}

    public function get(string $url): Response
    {
        return $this->send(fn () => Http::withHeaders($this->headers())
            ->get($url));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function postJson(string $url, array $payload): Response
    {
        return $this->send(fn () => Http::withHeaders([
            ...$this->headers(),
            'Content-Type' => 'application/json',
        ])->post($url, $payload));
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        return [
            'X-Shopify-Access-Token' => $this->token,
            'Accept' => 'application/json',
        ];
    }

    protected function send(callable $request): Response
    {
        $maxRetries = max(0, (int) config('titan.shopify.rate_limit.max_retries', 5));
        $attempt = 0;

        while (true) {
            $response = $request();

            if (! $this->isRateLimited($response)) {
                $this->throttleAfterRequest();

                return $response;
            }

            if ($attempt >= $maxRetries) {
                throw new ShopifyRateLimitException(
                    $this->rateLimitMessage($response),
                    $this->retryAfterSeconds($response, $attempt),
                );
            }

            $delayMs = $this->backoffDelayMs($attempt, $response);
            Sleep::usleep($delayMs * 1000);
            $attempt++;
        }
    }

    protected function isRateLimited(Response $response): bool
    {
        if (in_array($response->status(), [429, 503], true)) {
            return true;
        }

        $errors = $response->json('errors');

        if (is_string($errors) && str_contains(strtolower($errors), 'rate limit')) {
            return true;
        }

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            if (! is_array($error)) {
                continue;
            }

            $message = strtolower((string) ($error['message'] ?? ''));
            $code = strtoupper((string) ($error['extensions']['code'] ?? ''));

            if (str_contains($message, 'rate limit') || str_contains($message, 'throttl') || $code === 'THROTTLED') {
                return true;
            }
        }

        return false;
    }

    protected function rateLimitMessage(Response $response): string
    {
        $errors = $response->json('errors');

        if (is_string($errors) && $errors !== '') {
            return $errors;
        }

        if (is_array($errors) && $errors !== []) {
            $message = collect($errors)
                ->map(fn ($error) => is_array($error) ? ($error['message'] ?? null) : $error)
                ->filter()
                ->implode('; ');

            if ($message !== '') {
                return $message;
            }
        }

        return 'Rate limited. Please retry later.';
    }

    protected function retryAfterSeconds(Response $response, int $attempt): int
    {
        $header = $response->header('Retry-After');

        if (is_numeric($header) && (int) $header > 0) {
            return min((int) $header, $this->maxDelaySeconds());
        }

        return min(
            (int) ceil($this->backoffDelayMs($attempt, $response) / 1000),
            $this->maxDelaySeconds(),
        );
    }

    protected function backoffDelayMs(int $attempt, Response $response): int
    {
        $baseMs = max(100, (int) config('titan.shopify.rate_limit.base_delay_ms', 1000));
        $maxMs = max($baseMs, (int) config('titan.shopify.rate_limit.max_delay_ms', 60000));
        $retryAfterHeader = $response->header('Retry-After');

        if (is_numeric($retryAfterHeader) && (int) $retryAfterHeader > 0) {
            return min((int) $retryAfterHeader * 1000, $maxMs);
        }

        $exponential = $baseMs * (2 ** $attempt);
        $jitter = random_int(0, (int) max(1, $baseMs / 2));

        return min($exponential + $jitter, $maxMs);
    }

    protected function throttleAfterRequest(): void
    {
        $delayMs = max(0, (int) config('titan.shopify.rate_limit.request_delay_ms', 500));

        if ($delayMs > 0) {
            Sleep::usleep($delayMs * 1000);
        }
    }

    protected function maxDelaySeconds(): int
    {
        $maxMs = max(1000, (int) config('titan.shopify.rate_limit.max_delay_ms', 60000));

        return (int) ceil($maxMs / 1000);
    }
}
