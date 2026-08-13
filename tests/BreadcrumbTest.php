<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\SeverityLevel;
use Talaria\TalariaClient;
use Talaria\Tracing\BreadcrumbBuffer;
use Talaria\Tracing\SpanKind;

final class BreadcrumbTest extends TestCase
{
    public function testBufferCapsAtFifty(): void
    {
        $buffer = new BreadcrumbBuffer(50);
        for ($i = 0; $i < 60; $i++) {
            $buffer->add(['message' => 'crumb-' . $i, 'type' => 'default']);
        }

        $snapshot = $buffer->snapshot();
        self::assertCount(50, $snapshot);
        self::assertSame('crumb-10', $snapshot[0]['message']);
        self::assertSame('crumb-59', $snapshot[49]['message']);
        self::assertSame('BreadcrumbDto', $snapshot[0]['__className__']);
    }

    public function testErrorEventReceivesCappedBreadcrumbsAndTraceIds(): void
    {
        $transport = new FakeTransport();
        $spans = new FakeSpanTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'enableTracing' => true,
            'tracesSampleRate' => 1.0,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $transport, spanTransport: $spans);

        $root = $client->startTransaction('GET /checkout', SpanKind::Server);
        for ($i = 0; $i < 60; $i++) {
            $client->addBreadcrumb([
                'message' => 'crumb-' . $i,
                'type' => 'query',
                'category' => 'db',
                'level' => 'info',
                'data' => ['i' => (string) $i],
            ]);
        }

        $client->captureException(new \RuntimeException('payment failed'));
        $root->end();
        $client->flush();

        $event = $transport->allEvents()[0];
        self::assertNotNull($event->breadcrumbs);
        self::assertCount(50, $event->breadcrumbs);
        self::assertSame('crumb-10', $event->breadcrumbs[0]['message']);
        self::assertSame('crumb-59', $event->breadcrumbs[49]['message']);
        self::assertSame($root->traceId, $event->traceId);
        self::assertSame($root->spanId, $event->spanId);

        $wire = $event->toWire();
        self::assertSame($root->traceId, $wire['traceId']);
        self::assertSame($root->spanId, $wire['spanId']);
        self::assertCount(50, $wire['breadcrumbs']);
        self::assertSame('BreadcrumbDto', $wire['breadcrumbs'][0]['__className__']);
    }

    public function testInfoEventsDoNotAttachBreadcrumbs(): void
    {
        $transport = new FakeTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
            'flushIntervalMs' => 60_000,
        ], $transport);

        $client->addBreadcrumb(['message' => 'prior', 'type' => 'default']);
        $client->captureMessage('hello', SeverityLevel::Info);

        $event = $transport->allEvents()[0];
        self::assertNull($event->breadcrumbs);
        self::assertArrayNotHasKey('breadcrumbs', $event->toWire());
    }
}
