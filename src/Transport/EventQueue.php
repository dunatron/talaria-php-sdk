<?php

declare(strict_types=1);

namespace Talaria\Transport;

use Talaria\Event;
use Talaria\Exception\TransportException;

/**
 * In-memory queue that drains via ingestBatch on size or age thresholds.
 *
 * @phpstan-type ClockCallable callable(): float
 * @phpstan-type ErrorHandlerCallable callable(TransportException): void
 */
final class EventQueue
{
    /** @var list<array{event: Event, enqueuedAt: float}> */
    private array $buffer = [];

    private bool $draining = false;

    /**
     * @param ClockCallable|null $clock Returns unix time in seconds (fractional ok)
     * @param ErrorHandlerCallable|null $onError
     */
    public function __construct(
        private readonly TransportInterface $transport,
        private readonly int $maxBatchSize = 50,
        private readonly int $flushIntervalMs = 2000,
        private $clock = null,
        private $onError = null,
    ) {
        $this->clock ??= static fn (): float => microtime(true);
    }

    public function enqueue(Event $event): void
    {
        $this->buffer[] = [
            'event' => $event,
            'enqueuedAt' => ($this->clock)(),
        ];

        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    public function flush(): void
    {
        if ($this->draining || $this->buffer === []) {
            return;
        }

        $this->draining = true;
        try {
            while ($this->buffer !== []) {
                $slice = array_splice($this->buffer, 0, $this->maxBatchSize);
                /** @var list<Event> $events */
                $events = array_map(static fn (array $item) => $item['event'], $slice);

                try {
                    $this->transport->sendBatch($events);
                } catch (TransportException $e) {
                    if ($this->onError !== null) {
                        ($this->onError)($e);
                    }
                    // Drop failed batch — no poison-pill retry loop in v1
                }
            }
        } finally {
            $this->draining = false;
        }
    }

    public function count(): int
    {
        return count($this->buffer);
    }

    private function shouldFlush(): bool
    {
        if (count($this->buffer) >= $this->maxBatchSize) {
            return true;
        }

        if ($this->flushIntervalMs <= 0 || $this->buffer === []) {
            return false;
        }

        $oldest = $this->buffer[0]['enqueuedAt'];
        $ageMs = (($this->clock)() - $oldest) * 1000.0;

        return $ageMs >= $this->flushIntervalMs;
    }
}
