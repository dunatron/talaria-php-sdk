<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Config;
use Talaria\TalariaClient;
use Talaria\Tracing\Sampling;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

final class SamplingTest extends TestCase
{
    public function testHeadHonorsParentSampledFlag(): void
    {
        self::assertTrue(Sampling::head(0.0, true));
        self::assertFalse(Sampling::head(1.0, false));
        self::assertTrue(Sampling::head(1.0, null));
        self::assertFalse(Sampling::head(0.0, null));
    }

    public function testErrorOverrideAlwaysSends(): void
    {
        self::assertTrue(Sampling::shouldSend(false, true));
        self::assertTrue(Sampling::shouldSend(true, false));
        self::assertFalse(Sampling::shouldSend(false, false));
    }

    public function testSuccessfulTransactionDroppedWhenRateZero(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client(['enableTracing' => true, 'tracesSampleRate' => 0.0], $spans);

        $root = $client->startTransaction('GET /ok', SpanKind::Server);
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        self::assertSame(0, $spans->batchCount());
    }

    public function testErrorTransactionSentWhenRateZero(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client(['enableTracing' => true, 'tracesSampleRate' => 0.0], $spans);

        $root = $client->startTransaction('GET /fail', SpanKind::Server);
        $root->setStatus(SpanStatus::Error, 'boom');
        $root->end();
        $client->flush();

        self::assertSame(1, $spans->batchCount());
        self::assertCount(1, $spans->allSpans());
        self::assertSame('GET /fail', $spans->allSpans()[0]->name);
        self::assertSame('error', $spans->allSpans()[0]->getStatus());
    }

    public function testMarkErrorFromCaptureSendsTransaction(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client(['enableTracing' => true, 'tracesSampleRate' => 0.0], $spans);

        $root = $client->startTransaction('GET /captured', SpanKind::Server);
        $client->captureException(new \RuntimeException('db down'));
        $root->end();
        $client->flush();

        self::assertNotEmpty($spans->allSpans());
        self::assertSame('error', $spans->allSpans()[0]->getStatus());
    }

    public function testWarningEventRetainsUnsampledTransaction(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client(['enableTracing' => true, 'tracesSampleRate' => 0.0], $spans);

        $root = $client->startTransaction('GET /server-error', SpanKind::Server);
        $client->captureMessage('session_start(): headers already sent', 'warning');
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        self::assertNotEmpty($spans->allSpans());
        self::assertSame('GET /server-error', $spans->allSpans()[0]->name);
        self::assertSame('ok', $spans->allSpans()[0]->getStatus());
    }

    public function testSuccessfulTransactionSentWhenRateOne(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client(['enableTracing' => true, 'tracesSampleRate' => 1.0], $spans);

        $root = $client->startTransaction('GET /ok', SpanKind::Server);
        $root->setStatus(SpanStatus::Ok);
        $root->end();
        $client->flush();

        self::assertCount(1, $spans->allSpans());
    }

    public function testTracingOffDropsEvenErrorSpans(): void
    {
        $spans = new FakeSpanTransport();
        $client = $this->client([], $spans);

        $root = $client->startTransaction('GET /fail', SpanKind::Server);
        $root->setStatus(SpanStatus::Error, 'boom');
        $root->end();
        $client->flush();

        self::assertSame(0, $spans->batchCount());
        self::assertFalse($client->getConfig()->enableTracing);
    }

    public function testEnableTracingDefaultsSampleRateToTenPercent(): void
    {
        $config = new Config([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'enableTracing' => true,
        ]);
        self::assertTrue($config->enableTracing);
        self::assertSame(0.1, $config->tracesSampleRate);
    }

    public function testTracesSampleRateAloneEnablesTracing(): void
    {
        $config = new Config([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'tracesSampleRate' => 0.5,
        ]);
        self::assertTrue($config->enableTracing);
        self::assertSame(0.5, $config->tracesSampleRate);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function client(array $overrides, FakeSpanTransport $spans): TalariaClient
    {
        return new TalariaClient(array_merge([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $overrides), new FakeTransport(), spanTransport: $spans);
    }
}
