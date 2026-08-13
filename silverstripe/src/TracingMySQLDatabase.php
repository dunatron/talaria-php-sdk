<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\Connect\MySQLDatabase;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;
use Talaria\Tracing\SqlSanitizer;

/**
 * CLIENT/db spans around Silverstripe MySQL queries. Identical statements are
 * each recorded (N+1 stays visible). No-ops when tracing is off.
 */
class TracingMySQLDatabase extends MySQLDatabase
{
    /**
     * @param mixed $sql
     * @param mixed $errorLevel
     * @return mixed
     */
    public function query($sql, $errorLevel = E_USER_ERROR)
    {
        return $this->withQuerySpan((string) $sql, function () use ($sql, $errorLevel) {
            return parent::query($sql, $errorLevel);
        });
    }

    /**
     * @param mixed $sql
     * @param mixed $parameters
     * @param mixed $errorLevel
     * @return mixed
     */
    public function preparedQuery($sql, $parameters, $errorLevel = E_USER_ERROR)
    {
        return $this->withQuerySpan((string) $sql, function () use ($sql, $parameters, $errorLevel) {
            return parent::preparedQuery($sql, $parameters, $errorLevel);
        });
    }

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    private function withQuerySpan(string $sql, callable $callback): mixed
    {
        $client = self::client();
        if ($client === null || !$client->getConfig()->enableTracing) {
            return $callback();
        }

        $root = $client->getTracer()->rootSpan();
        if ($root === null) {
            // Boot / session queries before HTTPMiddleware would become their own traces.
            return $callback();
        }

        $operation = SqlSanitizer::operation($sql);
        $sanitized = SqlSanitizer::sanitize($sql);
        $span = $client->startSpan($operation, SpanKind::Client, [
            'db.system.name' => 'mysql',
            'db.operation.name' => $operation,
            'db.query.text' => $sanitized,
        ]);
        $client->addBreadcrumb([
            'type' => 'query',
            'category' => 'db',
            'message' => $operation,
            'level' => 'info',
            'data' => [
                'db.system.name' => 'mysql',
                'db.operation.name' => $operation,
            ],
        ]);

        try {
            $result = $callback();
            $span->setStatus(SpanStatus::Ok);

            return $result;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            $client->getTracer()->markError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    private static function client(): ?TalariaClient
    {
        if (!class_exists(Injector::class)) {
            return null;
        }

        try {
            $client = Injector::inst()->get(TalariaClient::class);

            return $client instanceof TalariaClient ? $client : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
