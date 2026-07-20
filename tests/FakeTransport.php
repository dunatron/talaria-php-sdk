<?php

declare(strict_types=1);

namespace Talaria\Tests;

use Talaria\Event;
use Talaria\Transport\TransportInterface;

final class FakeTransport implements TransportInterface
{
    /** @var list<list<Event>> */
    public array $batches = [];

    public function sendBatch(array $events): void
    {
        $this->batches[] = array_values($events);
    }

    public function batchCount(): int
    {
        return count($this->batches);
    }

    /**
     * @return list<Event>
     */
    public function allEvents(): array
    {
        $all = [];
        foreach ($this->batches as $batch) {
            foreach ($batch as $event) {
                $all[] = $event;
            }
        }

        return $all;
    }
}
