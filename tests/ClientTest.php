<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Client;
use Talaria\SeverityLevel;
use Talaria\Talaria;

final class ClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Talaria::reset();
    }

    public function testCaptureMessageEnqueuesUntilFlush(): void
    {
        $transport = new FakeTransport();
        $client = new Client([
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
        $client = new Client([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'production',
            'defaultIntegrations' => false,
            'maxBatchSize' => 1,
        ], $transport);

        $client->captureException(new \RuntimeException('db down'));
        self::assertSame(1, $transport->batchCount());
        $event = $transport->batches[0][0];
        self::assertSame('db down', $event->message);
        self::assertSame('error', $event->level->value);
        self::assertSame('RuntimeException', $event->title);
        self::assertNotNull($event->stackTrace);
        self::assertNotNull($event->extraJson);
        self::assertStringContainsString('RuntimeException', (string) $event->extraJson);
    }

    public function testSampleRateZeroDropsEvents(): void
    {
        $transport = new FakeTransport();
        $client = new Client([
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
    }
}
