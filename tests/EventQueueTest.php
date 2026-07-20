<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Environment;
use Talaria\Event;
use Talaria\SeverityLevel;
use Talaria\Transport\EventQueue;

final class EventQueueTest extends TestCase
{
    public function testDoesNotSendUntilMaxBatchSize(): void
    {
        $transport = new FakeTransport();
        $queue = new EventQueue($transport, maxBatchSize: 3, flushIntervalMs: 60_000);

        $queue->enqueue($this->event('one'));
        $queue->enqueue($this->event('two'));
        self::assertSame(0, $transport->batchCount());
        self::assertSame(2, $queue->count());

        $queue->enqueue($this->event('three'));
        self::assertSame(1, $transport->batchCount());
        self::assertCount(3, $transport->batches[0]);
        self::assertSame(0, $queue->count());
    }

    public function testFlushesWhenAgeExceeded(): void
    {
        $transport = new FakeTransport();
        $now = 1000.0;
        $queue = new EventQueue(
            $transport,
            maxBatchSize: 50,
            flushIntervalMs: 2000,
            clock: static function () use (&$now): float {
                return $now;
            },
        );

        $queue->enqueue($this->event('old'));
        self::assertSame(0, $transport->batchCount());

        $now = 1003.0; // 3000ms later
        $queue->enqueue($this->event('triggers'));
        self::assertSame(1, $transport->batchCount());
        self::assertCount(2, $transport->batches[0]);
    }

    public function testFlushSendsBatchOfOne(): void
    {
        $transport = new FakeTransport();
        $queue = new EventQueue($transport, maxBatchSize: 50, flushIntervalMs: 60_000);
        $queue->enqueue($this->event('lonely'));
        self::assertSame(0, $transport->batchCount());

        $queue->flush();
        self::assertSame(1, $transport->batchCount());
        self::assertCount(1, $transport->batches[0]);
    }

    public function testDrainsInMaxBatchSizeSlices(): void
    {
        $transport = new FakeTransport();
        $queue = new EventQueue($transport, maxBatchSize: 2, flushIntervalMs: 60_000);

        $queue->enqueue($this->event('a'));
        $queue->enqueue($this->event('b')); // size flush
        $queue->enqueue($this->event('c'));
        $queue->enqueue($this->event('d')); // size flush
        $queue->enqueue($this->event('e'));
        $queue->flush(); // remainder

        self::assertSame(3, $transport->batchCount());
        self::assertCount(2, $transport->batches[0]);
        self::assertCount(2, $transport->batches[1]);
        self::assertCount(1, $transport->batches[2]);
    }

    private function event(string $message): Event
    {
        return new Event(
            message: $message,
            environment: Environment::Development,
            level: SeverityLevel::Info,
        );
    }
}
