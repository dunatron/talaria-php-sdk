<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\TalariaClient;

/**
 * Predis / phpredis CLIENT spans. Neither client is a Composer requirement —
 * wrap an existing instance with {@see wrap()}.
 */
final class RedisInstrumentation
{
    /**
     * @template T
     * @param callable(): T $execute
     * @return T
     */
    public static function trace(TalariaClient $client, string $command, callable $execute): mixed
    {
        $operation = strtoupper($command);
        $span = $client->startSpan(
            'redis ' . $operation,
            SpanKind::Client,
            [
                'db.system.name' => 'redis',
                'db.operation.name' => $operation,
            ],
        );

        try {
            $result = $execute();
            $span->setStatus(SpanStatus::Ok);
            $client->addBreadcrumb([
                'type' => 'query',
                'category' => 'redis',
                'message' => $operation,
                'level' => 'info',
            ]);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    /**
     * Proxy Predis\Client or \Redis via __call so GET/SET/etc become spans.
     */
    public static function wrap(object $redis, TalariaClient $client): RedisClientProxy
    {
        return new RedisClientProxy($redis, $client);
    }
}
