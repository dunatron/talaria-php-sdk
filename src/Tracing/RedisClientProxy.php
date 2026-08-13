<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\TalariaClient;

/**
 * Forwards Redis commands to Predis\Client or phpredis \Redis as CLIENT spans.
 */
final class RedisClientProxy
{
    public function __construct(
        private readonly object $inner,
        private readonly TalariaClient $client,
    ) {
    }

    public function inner(): object
    {
        return $this->inner;
    }

    public function __call(string $name, array $arguments): mixed
    {
        return RedisInstrumentation::trace(
            $this->client,
            $name,
            fn () => $this->inner->{$name}(...$arguments),
        );
    }
}
