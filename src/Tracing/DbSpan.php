<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\TalariaClient;

/**
 * Shared CLIENT span + query breadcrumb for PDO / MySQLi wrappers.
 */
final class DbSpan
{
    /**
     * @template T
     * @param callable(): T $run
     * @return T
     */
    public static function trace(
        TalariaClient $client,
        string $system,
        string $sql,
        callable $run,
    ): mixed {
        $operation = SqlSanitizer::operation($sql);
        $span = $client->startSpan(
            SqlSanitizer::spanName($sql),
            SpanKind::Client,
            SqlSanitizer::attributes($sql, $system),
        );

        try {
            $result = $run();
            $span->setStatus(SpanStatus::Ok);
            $client->addBreadcrumb([
                'type' => 'query',
                'category' => 'db',
                'message' => $operation,
                'level' => 'info',
                'data' => ['db.system.name' => $system],
            ]);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }
}
