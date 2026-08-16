<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\Exception\TransportException;

/**
 * In-memory queue that drains via spans/ingestBatch on size or age thresholds.
 *
 * @phpstan-type ClockCallable callable(): float
 * @phpstan-type ErrorHandlerCallable callable(TransportException): void
 */
final class SpanQueue
{
    /** @var list<array{span: Span, enqueuedAt: float}> */
    private array $buffer = [];

    private bool $draining = false;

    /**
     * @param ClockCallable|null $clock Returns unix time in seconds (fractional ok)
     * @param ErrorHandlerCallable|null $onError
     */
    public function __construct(
        private readonly SpanTransportInterface $transport,
        private readonly int $maxBatchSize = 50,
        private readonly int $flushIntervalMs = 2000,
        private $clock = null,
        private $onError = null,
    ) {
        $this->clock ??= static fn (): float => microtime(true);
    }

    public function enqueue(Span $span): void
    {
        $this->buffer[] = [
            'span' => $span,
            'enqueuedAt' => ($this->clock)(),
        ];

        if ($this->shouldFlush()) {
            $this->flush();
        }
    }

    /**
     * Send one transaction as a single ingest batch (up to 200 spans).
     * Avoids splitting N+1 detection across size-limited RPCs.
     *
     * @param list<Span> $spans
     */
    public function sendTransaction(array $spans): void
    {
        if ($spans === []) {
            return;
        }
        try {
            $this->transport->sendBatch(array_values($spans));
        } catch (TransportException $e) {
            if ($this->onError !== null) {
                ($this->onError)($e);
            }
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
                /** @var list<Span> $spans */
                $spans = array_map(static fn (array $item) => $item['span'], $slice);

                try {
                    $this->transport->sendBatch($spans);
                } catch (TransportException $e) {
                    if ($this->onError !== null) {
                        ($this->onError)($e);
                    }
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
