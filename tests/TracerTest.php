<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

final class TracerTest extends TestCase
{
    public function testIdenticalQuerySpansAreAllSent(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);

        $root = $client->startTransaction('GET /products', SpanKind::Server);
        $sql = 'SELECT * FROM "Product" WHERE "ID" = ?';
        for ($i = 0; $i < 12; $i++) {
            $query = $client->startSpan('SELECT', SpanKind::Client, [
                'db.system.name' => 'mysql',
                'db.operation.name' => 'SELECT',
                'db.query.text' => $sql,
            ]);
            $query->setStatus(SpanStatus::Ok);
            $query->end();
        }
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        $all = $spans->allSpans();
        self::assertCount(13, $all);
        self::assertSame('GET /products', $all[0]->name);
        $queries = array_slice($all, 1);
        self::assertCount(12, $queries);
        foreach ($queries as $query) {
            self::assertSame('SELECT', $query->name);
            $wire = $query->toWire();
            self::assertSame('mysql', $wire['attributes']['db.system.name']);
            self::assertSame('SELECT', $wire['attributes']['db.operation.name']);
            self::assertSame($sql, $wire['attributes']['db.query.text']);
            self::assertSame($all[0]->traceId, $query->traceId);
            self::assertSame($all[0]->spanId, $query->toWire()['parentSpanId']);
        }
    }

    public function testContinuesIncomingTraceparent(): void
    {
        $previous = $_SERVER['HTTP_TRACEPARENT'] ?? null;
        $_SERVER['HTTP_TRACEPARENT'] = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';

        try {
            $spans = new FakeSpanTransport();
            $client = $this->client($spans);
            $root = $client->startTransaction('GET /continued', SpanKind::Server);
            $child = $client->startSpan('SELECT', SpanKind::Client);
            $child->end();
            $root->end();
            $client->flush();

            $all = $spans->allSpans();
            self::assertCount(2, $all);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $all[0]->traceId);
            self::assertSame('00f067aa0ba902b7', $all[0]->toWire()['parentSpanId']);
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $all[1]->traceId);
            self::assertSame($all[0]->spanId, $all[1]->toWire()['parentSpanId']);
        } finally {
            if ($previous === null) {
                unset($_SERVER['HTTP_TRACEPARENT']);
            } else {
                $_SERVER['HTTP_TRACEPARENT'] = $previous;
            }
        }
    }

    public function testDroppedSpansAreNotSent(): void
    {
        $spans = new FakeSpanTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'enableTracing' => true,
            'tracesSampleRate' => 0.0,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], new FakeTransport(), spanTransport: $spans);

        $root = $client->startTransaction('GET /drop', SpanKind::Server);
        $child = $client->startSpan('SELECT', SpanKind::Client);
        $child->end();
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        self::assertSame(0, $spans->batchCount());
        self::assertSame(0, $client->spanQueueSize());
    }

    private function client(FakeSpanTransport $spans): TalariaClient
    {
        return new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'enableTracing' => true,
            'tracesSampleRate' => 1.0,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
            'tags' => ['service' => 'api'],
            'release' => '1.2.3',
        ], new FakeTransport(), spanTransport: $spans);
    }
}
