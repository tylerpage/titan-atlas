<?php

namespace App\Support;

class AiTraceContext
{
    /** @var array<string, mixed>|null */
    protected static ?array $context = null;

    protected static float $startedAt = 0;

    protected static ?int $pendingQueueWaitMs = null;

    /** @var array<string, float> */
    protected static array $toolStarts = [];

    /** @var list<array{name: string, duration_ms: int}> */
    protected static array $tools = [];

    protected static ?int $preservedQueueWaitMs = null;

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function begin(array $metadata): void
    {
        if (self::$pendingQueueWaitMs !== null) {
            $metadata['queue_wait_ms'] = self::$pendingQueueWaitMs;
            self::$pendingQueueWaitMs = null;
        }

        self::$context = $metadata;
        self::$startedAt = microtime(true);
        self::$toolStarts = [];
        self::$tools = [];
    }

    public static function setQueueWaitMs(int $milliseconds): void
    {
        self::$pendingQueueWaitMs = max(0, $milliseconds);
    }

    public static function active(): bool
    {
        return self::$context !== null && self::$startedAt > 0;
    }

    public static function recordToolStart(string $toolInvocationId): void
    {
        if (! self::enabled() || ! self::active()) {
            return;
        }

        self::$toolStarts[$toolInvocationId] = microtime(true);
    }

    public static function recordToolEnd(string $toolInvocationId, string $toolName): void
    {
        if (! self::enabled() || ! self::active()) {
            return;
        }

        $startedAt = self::$toolStarts[$toolInvocationId] ?? null;

        if ($startedAt === null) {
            return;
        }

        unset(self::$toolStarts[$toolInvocationId]);

        self::$tools[] = [
            'name' => $toolName,
            'duration_ms' => max(0, (int) round((microtime(true) - $startedAt) * 1000)),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function snapshot(): ?array
    {
        if (! self::active()) {
            return null;
        }

        $totalMs = max(0, (int) round((microtime(true) - self::$startedAt) * 1000));
        $toolMs = array_sum(array_column(self::$tools, 'duration_ms'));

        return [
            ...self::$context,
            'total_ms' => $totalMs,
            'tool_ms' => $toolMs,
            'estimated_llm_ms' => max(0, $totalMs - $toolMs),
            'tools' => self::$tools,
        ];
    }

    public static function preserveQueueWaitMs(): void
    {
        if (self::$context !== null && isset(self::$context['queue_wait_ms'])) {
            self::$preservedQueueWaitMs = max(0, (int) self::$context['queue_wait_ms']);
        }
    }

    public static function consumePreservedQueueWaitMs(): int
    {
        $milliseconds = self::$preservedQueueWaitMs ?? 0;
        self::$preservedQueueWaitMs = null;

        return $milliseconds;
    }

    public static function clear(): void
    {
        self::$context = null;
        self::$startedAt = 0;
        self::$toolStarts = [];
        self::$tools = [];
        self::$pendingQueueWaitMs = null;
    }

    public static function enabled(): bool
    {
        return (bool) config('titan.ai_perf_logging', true);
    }
}
