<?php

declare(strict_types=1);

namespace Talaria\Tracing;

/**
 * No-op span transport used when the SDK is disabled or misconfigured.
 */
final class NullSpanTransport implements SpanTransportInterface
{
    public function sendBatch(array $spans): void
    {
        // intentionally empty
    }
}
