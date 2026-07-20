<?php

declare(strict_types=1);

namespace Talaria\Transport;

use Talaria\Event;

interface TransportInterface
{
    /**
     * Send a batch of events via events/ingestBatch.
     *
     * @param list<Event> $events
     */
    public function sendBatch(array $events): void;
}
