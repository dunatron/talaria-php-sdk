<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;
use Talaria\Environment;
use Talaria\Event;
use Talaria\Exception\TransportException;
use Talaria\SeverityLevel;
use Talaria\Transport\ServerpodHttpTransport;

final class ServerpodHttpTransportTest extends TestCase
{
    public function testSendBatchPostsIngestBatchEnvelope(): void
    {
        $history = [];
        $mock = new MockHandler([new Response(200, [], '{}')]);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($history));
        $http = new GuzzleClient(['handler' => $stack]);

        $transport = new ServerpodHttpTransport(
            'https://api.example.com/',
            'tal_live_abc',
            2.0,
            $http,
        );

        $transport->sendBatch([
            new Event(
                message: 'First',
                environment: Environment::Production,
                level: SeverityLevel::Warning,
            ),
            new Event(
                message: 'Second',
                environment: Environment::Production,
                level: SeverityLevel::Error,
            ),
        ]);

        self::assertCount(1, $history);
        $request = $history[0]['request'];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://api.example.com/events/ingestBatch', (string) $request->getUri());
        self::assertSame('tal_live_abc', $request->getHeaderLine('X-API-Key'));

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('IngestEventBatchInput', $body['input']['__className__']);
        self::assertCount(2, $body['input']['events']);
        self::assertSame('IngestEventInput', $body['input']['events'][0]['__className__']);
        self::assertSame('First', $body['input']['events'][0]['message']);
        self::assertSame('Second', $body['input']['events'][1]['message']);
    }

    public function testNonSuccessThrows(): void
    {
        $mock = new MockHandler([new Response(429, [], '{"message":"quota exceeded"}')]);
        $http = new GuzzleClient(['handler' => HandlerStack::create($mock)]);
        $transport = new ServerpodHttpTransport('https://api.example.com', 'tal_live_abc', 2.0, $http);

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('429');
        $transport->sendBatch([
            new Event(message: 'x', environment: Environment::Development, level: SeverityLevel::Info),
        ]);
    }
}
