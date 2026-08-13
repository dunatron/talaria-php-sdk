<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\Context\RuntimeContext;
use Talaria\Exception\TransportException;
use Talaria\Tracing\Span;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanTransport;

final class SpanTransportTest extends TestCase
{
    public function testSendBatchPostsIngestSpanEnvelope(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['handler' => $stack]);

        $transport = new SpanTransport(
            'https://api.example.com/',
            'tal_live_abc',
            2.0,
            $http,
        );

        $span = new Span(
            traceId: '4bf92f3577b34da6a3ce929d0e0e4736',
            spanId: '00f067aa0ba902b7',
            parentSpanId: null,
            name: 'GET /products',
            kind: SpanKind::Server->value,
            startTime: RuntimeContext::isoTimestamp(),
            attributes: [
                'http.request.method' => 'GET',
                'http.route' => '/products',
            ],
            resource: ['service.name' => 'api'],
            environment: 'production',
        );
        $span->end();

        $transport->sendBatch([$span]);

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.com/spans/ingestBatch', (string) $request->getUri());
        self::assertSame('tal_live_abc', $request->getHeaderLine('X-API-Key'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('IngestSpanBatchInput', $body['input']['__className__']);
        self::assertCount(1, $body['input']['spans']);
        self::assertSame('IngestSpanInput', $body['input']['spans'][0]['__className__']);
        self::assertSame('GET /products', $body['input']['spans'][0]['name']);
        self::assertSame('server', $body['input']['spans'][0]['kind']);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $body['input']['spans'][0]['traceId']);
        self::assertArrayNotHasKey('parentSpanId', $body['input']['spans'][0]);
    }

    public function testNonSuccessThrows(): void
    {
        $mock = new MockHandler([new Response(429, [], '{"message":"quota exceeded"}')]);
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        $transport = new SpanTransport('https://api.example.com', 'tal_live_abc', 2.0, $http);

        $span = new Span(
            traceId: str_repeat('a', 32),
            spanId: str_repeat('b', 16),
            parentSpanId: null,
            name: 'x',
            kind: SpanKind::Internal->value,
            startTime: RuntimeContext::isoTimestamp(),
        );
        $span->end();

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('429');
        $transport->sendBatch([$span]);
    }
}
