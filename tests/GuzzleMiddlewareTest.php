<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\TalariaClient;
use Talaria\Tracing\GuzzleMiddleware;
use Talaria\Tracing\SpanKind;

final class GuzzleMiddlewareTest extends TestCase
{
    public function testInjectsTraceparentOnAppClient(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);

        $stack = HandlerStack::create($mock);
        // Last push is innermost; history must sit inside Talaria so it sees injected headers.
        $stack->push(GuzzleMiddleware::create($client), 'talaria_tracing');
        $stack->push(Middleware::history($history));

        $root = $client->startTransaction('GET /page', SpanKind::Server);
        $http = new GuzzleClient(['handler' => $stack]);
        $http->request('GET', 'https://payments.example.com/charge?card=secret');
        $root->end();
        $client->flush();

        self::assertCount(1, $history);
        $outgoing = $history[0]['request'];
        $traceparent = $outgoing->getHeaderLine('traceparent');
        self::assertNotSame('', $traceparent);
        self::assertStringStartsWith('00-' . $root->traceId . '-', $traceparent);

        $child = null;
        foreach ($spans->allSpans() as $span) {
            if ($span->kind === SpanKind::Client->value) {
                $child = $span;
            }
        }
        self::assertNotNull($child);
        self::assertSame('GET payments.example.com/charge', $child->name);
        $wire = $child->toWire();
        self::assertSame('GET', $wire['attributes']['http.request.method']);
        self::assertSame('200', $wire['attributes']['http.response.status_code']);
        self::assertStringNotContainsString('secret', $wire['attributes']['url.full']);
    }

    public function testSkipsTalariaIngestUrls(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);

        $stack = HandlerStack::create($mock);
        $stack->push(GuzzleMiddleware::create($client), 'talaria_tracing');
        $stack->push(Middleware::history($history));

        $root = $client->startTransaction('GET /page', SpanKind::Server);
        $http = new GuzzleClient(['handler' => $stack]);
        $http->send(new Request('POST', 'https://api.example.com/spans/ingestBatch'));
        $root->end();
        $client->flush();

        self::assertCount(1, $history);
        self::assertSame('', $history[0]['request']->getHeaderLine('traceparent'));
        $kinds = array_map(static fn ($span) => $span->kind, $spans->allSpans());
        self::assertNotContains(SpanKind::Client->value, $kinds);
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
        ], new FakeTransport(), spanTransport: $spans);
    }
}
