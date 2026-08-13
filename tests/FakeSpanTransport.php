<?php

declare(strict_types=1);

namespace Talaria\Tests;

use Talaria\Tracing\Span;
use Talaria\Tracing\SpanTransportInterface;

final class FakeSpanTransport implements SpanTransportInterface
{
    /** @var list<list<Span>> */
    public array $batches = [];

    public function sendBatch(array $spans): void
    {
        $this->batches[] = array_values($spans);
    }

    public function batchCount(): int
    {
        return count($this->batches);
    }

    /**
     * @return list<Span>
     */
    public function allSpans(): array
    {
        $all = [];
        foreach ($this->batches as $batch) {
            foreach ($batch as $span) {
                $all[] = $span;
            }
        }

        return $all;
    }
}
