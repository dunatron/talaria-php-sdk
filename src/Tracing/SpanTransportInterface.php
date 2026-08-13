<?php

declare(strict_types=1);

namespace Talaria\Tracing;

interface SpanTransportInterface
{
    /**
     * Send a batch of spans via spans/ingestBatch.
     *
     * @param list<Span> $spans
     */
    public function sendBatch(array $spans): void;
}
