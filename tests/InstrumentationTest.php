<?php

declare(strict_types=1);

namespace Talaria\Tests;

use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\ServerRequest;
use PDO;
use PHPUnit\Framework\TestCase;
use Talaria\TalariaClient;
use Talaria\Tracing\IncomingHttp;
use Talaria\Tracing\Psr15Middleware;
use Talaria\Tracing\RedisInstrumentation;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\TracingPdo;

final class InstrumentationTest extends TestCase
{
    public function testGetTraceparentMatchesActiveSpan(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('GET /checkout', SpanKind::Server);

        $header = $client->getTraceparent();
        self::assertNotNull($header);
        self::assertStringStartsWith('00-' . $root->traceId . '-' . $root->spanId . '-', $header);

        $root->end();
        $client->flush();
    }

    public function testPdoWrapperRecordsSanitizedQuerySpans(): void
    {
        if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
            self::markTestSkipped('pdo_sqlite is required');
        }

        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('GET /products', SpanKind::Server);

        $pdo = new PDO('sqlite::memory:');
        $pdo->exec('CREATE TABLE Product (ID INTEGER PRIMARY KEY, Title TEXT)');
        $pdo->exec("INSERT INTO Product (Title) VALUES ('Alpha')");

        $traced = new TracingPdo($pdo, $client, 'sqlite');
        $traced->query("SELECT * FROM Product WHERE Title = 'secret-title'");
        $stmt = $traced->prepare('SELECT * FROM Product WHERE ID = ?');
        self::assertNotFalse($stmt);
        $stmt->execute([1]);

        $root->end();
        $client->flush();

        $db = array_values(array_filter(
            $spans->allSpans(),
            static fn ($span) => ($span->toWire()['attributes']['db.system.name'] ?? null) === 'sqlite',
        ));
        self::assertCount(2, $db);
        $first = $db[0]->toWire();
        self::assertSame('SELECT', $first['attributes']['db.operation.name']);
        self::assertStringNotContainsString('secret-title', $first['attributes']['db.query.text']);
        self::assertStringContainsString('SELECT', $first['attributes']['db.query.text']);
    }

    public function testRedisProxySpansCommands(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $root = $client->startTransaction('GET /cache', SpanKind::Server);

        $fake = new FakeRedis();
        $redis = RedisInstrumentation::wrap($fake, $client);
        self::assertSame('ok', $redis->get('session:abc'));
        self::assertSame(1, $redis->set('session:abc', 'ok'));

        $root->end();
        $client->flush();

        $redisSpans = array_values(array_filter(
            $spans->allSpans(),
            static fn ($span) => str_starts_with($span->name, 'redis '),
        ));
        self::assertCount(2, $redisSpans);
        self::assertSame('GET', $redisSpans[0]->toWire()['attributes']['db.operation.name']);
        self::assertSame('redis', $redisSpans[0]->toWire()['attributes']['db.system.name']);
        self::assertSame('SET', $redisSpans[1]->toWire()['attributes']['db.operation.name']);
    }

    public function testPsr15MiddlewareRecordsServerSpan(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $middleware = new Psr15Middleware($client);
        $request = new ServerRequest('GET', 'https://shop.example/products/42');
        $handler = new class {
            public function handle(object $request): Response
            {
                return new Response(200);
            }
        };

        $response = $middleware->process($request, $handler);
        $client->flush();

        self::assertSame(200, $response->getStatusCode());
        $all = $spans->allSpans();
        self::assertCount(1, $all);
        $wire = $all[0]->toWire();
        self::assertSame('GET /products/42', $all[0]->name);
        self::assertSame('server', $all[0]->kind);
        self::assertSame('200', $wire['attributes']['http.response.status_code']);
    }

    public function testIncomingHttpUsesServerGlobals(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client($spans);
        $span = IncomingHttp::startTransaction($client, [
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/checkout?cart=1',
        ]);
        $span->end();
        $client->flush();

        self::assertSame('POST /checkout', $span->name);
        self::assertSame('POST', $span->toWire()['attributes']['http.request.method']);
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

final class FakeRedis
{
    public function get(string $key): string
    {
        return 'ok';
    }

    public function set(string $key, string $value): int
    {
        return 1;
    }
}
