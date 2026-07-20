<?php

declare(strict_types=1);

namespace Talaria\Transport;

/**
 * No-op transport used when the SDK is disabled or misconfigured.
 */
final class NullTransport implements TransportInterface
{
    public function sendBatch(array $events): void
    {
        // intentionally empty
    }
}
