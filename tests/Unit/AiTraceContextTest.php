<?php

namespace Tests\Unit;

use App\Support\AiTraceContext;
use Tests\TestCase;

class AiTraceContextTest extends TestCase
{
    protected function tearDown(): void
    {
        AiTraceContext::clear();

        parent::tearDown();
    }

    public function test_begin_captures_queue_wait_and_tool_durations(): void
    {
        config(['titan.ai_perf_logging' => true]);

        AiTraceContext::setQueueWaitMs(250);
        AiTraceContext::begin([
            'flow' => 'reporting',
            'session_id' => 42,
            'model' => 'gpt-4o-mini',
        ]);

        AiTraceContext::recordToolStart('tool-1');
        usleep(1000);
        AiTraceContext::recordToolEnd('tool-1', 'PreviewReportQueryTool');

        $snapshot = AiTraceContext::snapshot();

        $this->assertNotNull($snapshot);
        $this->assertSame('reporting', $snapshot['flow']);
        $this->assertSame(42, $snapshot['session_id']);
        $this->assertSame(250, $snapshot['queue_wait_ms']);
        $this->assertGreaterThanOrEqual(1, $snapshot['tool_ms']);
        $this->assertCount(1, $snapshot['tools']);
        $this->assertSame('PreviewReportQueryTool', $snapshot['tools'][0]['name']);
    }

    public function test_tracing_can_be_disabled(): void
    {
        config(['titan.ai_perf_logging' => false]);

        AiTraceContext::begin(['flow' => 'reporting', 'session_id' => 1]);
        AiTraceContext::recordToolStart('tool-1');
        AiTraceContext::recordToolEnd('tool-1', 'SaveAnalyticsReportTool');

        $this->assertSame([], AiTraceContext::snapshot()['tools'] ?? []);
    }
}
