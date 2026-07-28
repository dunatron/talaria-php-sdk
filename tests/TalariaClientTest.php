<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\SeverityLevel;
use Talaria\Talaria;
use Talaria\TalariaClient;

final class TalariaClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Talaria::reset();
    }

    public function testCaptureMessageEnqueuesUntilFlush(): void
    {
        $transport = new FakeTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $transport);

        $client->captureMessage('hello', SeverityLevel::Info);
        self::assertSame(0, $transport->batchCount());
        self::assertSame(1, $client->queueSize());

        $client->flush();
        self::assertSame(1, $transport->batchCount());
        self::assertSame('hello', $transport->batches[0][0]->message);
        self::assertSame('info', $transport->batches[0][0]->level->value);
    }

    public function testCaptureExceptionDefaultsToError(): void
    {
        $transport = new FakeTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'production',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
        ], $transport);

        $client->captureException(new \RuntimeException('db down'), [
            'extra' => ['cart_id' => 'abc'],
        ]);
        self::assertSame(1, $transport->batchCount());
        $event = $transport->batches[0][0];
        self::assertSame('db down', $event->message);
        self::assertSame('error', $event->level->value);
        self::assertSame('RuntimeException', $event->title);
        self::assertNotNull($event->stackTrace);
        self::assertSame('php', $event->platform);
        self::assertNotNull($event->exception);
        self::assertSame('ExceptionDataDto', $event->exception['__className__']);
        self::assertSame(\RuntimeException::class, $event->exception['values'][0]['type']);
        self::assertSame('db down', $event->exception['values'][0]['value']);
        self::assertTrue($event->exception['values'][0]['mechanism']['handled']);
        self::assertIsArray($event->exception['values'][0]['stacktrace']['frames']);

        $wire = $event->toWire();
        self::assertSame('php', $wire['platform']);
        self::assertArrayHasKey('exception', $wire);
        self::assertIsString($wire['extraJson']);
        $extra = json_decode((string) $wire['extraJson'], true);
        self::assertIsArray($extra);
        self::assertSame('abc', $extra['cart_id']);
        self::assertArrayNotHasKey('exception_class', $extra);
        self::assertArrayNotHasKey('file', $extra);
        self::assertArrayNotHasKey('line', $extra);
        self::assertArrayNotHasKey('code', $extra);

        foreach ($event->exception['values'][0]['stacktrace']['frames'] as $frame) {
            self::assertArrayNotHasKey('function', $frame);
            if (isset($frame['functionName'])) {
                self::assertIsString($frame['functionName']);
            }
        }
    }

    public function testCaptureMessageHasNoException(): void
    {
        $transport = new FakeTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
        ], $transport);

        $client->captureMessage('hello');
        $event = $transport->batches[0][0];
        self::assertNull($event->exception);
        self::assertNull($event->platform);
        self::assertArrayNotHasKey('exception', $event->toWire());
    }

    public function testSampleRateZeroDropsEvents(): void
    {
        $transport = new FakeTransport();
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'sampleRate' => 0,
            'maxBatchSize' => 1,
        ], $transport);

        $client->captureMessage('nope');
        $client->flush();
        self::assertSame(0, $transport->batchCount());
    }

    public function testFacadeRequiresInit(): void
    {
        $this->expectException(\RuntimeException::class);
        Talaria::captureMessage('x');
    }

    public function testFacadeInitAndCapture(): void
    {
        Talaria::init([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'staging',
            'defaultIntegrations' => false,
            'sampleRate' => 0,
        ]);

        Talaria::captureMessage('sampled out');
        Talaria::flush();
        self::assertNotNull(Talaria::getClient());
        self::assertInstanceOf(TalariaClient::class, Talaria::getClient());
    }

    public function testDeprecatedClientAlias(): void
    {
        self::assertTrue(class_exists(\Talaria\Client::class));
        $transport = new FakeTransport();
        $client = new \Talaria\Client([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
        ], $transport);
        self::assertInstanceOf(TalariaClient::class, $client);
    }
}
