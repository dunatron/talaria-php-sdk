<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanQueue;
use Talaria\Tracing\SpanStatus;

final class SpanQueueTest extends TestCase
{
    public function testSendTransactionDoesNotSplitOnMaxBatchSize(): void
    {
        $transport = new FakeSpanTransport();
        $queue = new SpanQueue($transport, 10, 60_000);

        $spans = [];
        for ($i = 0; $i < 13; $i++) {
            $span = new Span(
                traceId: str_repeat('a', 32),
                spanId: str_pad(dechex($i), 16, '0', STR_PAD_LEFT),
                parentSpanId: $i === 0 ? null : str_repeat('b', 16),
                name: $i === 0 ? 'GET /nPlusOne' : 'SELECT',
                kind: $i === 0 ? SpanKind::Server->value : SpanKind::Client->value,
                startTime: '2026-08-15T00:00:00.000Z',
            );
            $span->setStatus(SpanStatus::Ok);
            $span->end();
            $spans[] = $span;
        }

        $queue->sendTransaction($spans);

        self::assertSame(1, $transport->batchCount());
        self::assertCount(13, $transport->batches[0]);
    }
}
