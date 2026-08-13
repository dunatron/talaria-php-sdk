<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\QueuedJobService;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

/**
 * PRODUCER span on enqueue, CONSUMER span on run.
 *
 * Only loaded when `symbiote/silverstripe-queuedjobs` is installed.
 */
class TracingQueuedJobService extends QueuedJobService
{
    /**
     * @param mixed $job
     * @param mixed $startAfter
     * @param mixed $userId
     * @param mixed $queueName
     * @return mixed
     */
    public function queueJob($job, $startAfter = null, $userId = null, $queueName = null)
    {
        $client = self::client();
        if ($client === null || !$client->getConfig()->enableTracing) {
            return parent::queueJob($job, $startAfter, $userId, $queueName);
        }

        $name = self::jobName($job);
        $span = $client->startSpan($name, SpanKind::Producer, [
            'messaging.system' => 'silverstripe-queuedjobs',
            'messaging.operation' => 'publish',
        ]);
        $client->addBreadcrumb([
            'type' => 'default',
            'category' => 'queue',
            'message' => 'enqueue ' . $name,
            'level' => 'info',
        ]);

        try {
            $result = parent::queueJob($job, $startAfter, $userId, $queueName);
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

    /**
     * @param mixed $id
     * @return mixed
     */
    public function runJob($id)
    {
        $args = func_get_args();
        $client = self::client();
        if ($client === null || !$client->getConfig()->enableTracing) {
            return parent::runJob(...$args);
        }

        $name = 'queuedjob.process';
        $span = $client->startTransaction($name, SpanKind::Consumer, [
            'messaging.system' => 'silverstripe-queuedjobs',
            'messaging.operation' => 'process',
            'messaging.message.id' => (string) $id,
        ]);
        $client->addBreadcrumb([
            'type' => 'default',
            'category' => 'queue',
            'message' => $name,
            'level' => 'info',
            'data' => ['id' => (string) $id],
        ]);

        try {
            $result = parent::runJob(...$args);
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

    private static function jobName(mixed $job): string
    {
        if (is_object($job)) {
            if (method_exists($job, 'getTitle')) {
                try {
                    $title = $job->getTitle();
                    if (is_string($title) && $title !== '') {
                        return $title;
                    }
                } catch (\Throwable) {
                    // fall through
                }
            }

            return $job::class;
        }

        return 'queuedjob.enqueue';
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
