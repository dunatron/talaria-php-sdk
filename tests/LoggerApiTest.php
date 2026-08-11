<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\SeverityLevel;
use Talaria\TalariaClient;

final class LoggerApiTest extends TestCase
{
    public function testLevelMethodsSendExpectedSeverity(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport);

        $client->info('hello-info');
        $client->warn('hello-warn');
        $client->error('hello-error');
        $client->flush();

        $levels = array_map(
            static fn ($e) => $e->level->value,
            $transport->allEvents(),
        );
        self::assertSame(['info', 'warning', 'error'], $levels);
    }

    public function testMinLevelDropsLowerSeverities(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, ['minLevel' => 'warning']);

        $client->debug('d');
        $client->info('i');
        $client->warning('w');
        $client->error('e');
        $client->flush();

        $messages = array_map(static fn ($e) => $e->message, $transport->allEvents());
        self::assertSame(['w', 'e'], $messages);
    }

    public function testSampleRateZeroDropsAll(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, ['sampleRate' => 0]);

        $client->fatal('nope');
        $client->captureException(new \RuntimeException('nope'));
        $client->flush();

        self::assertSame(0, $transport->batchCount());
    }

    public function testBeforeSendCanDropOrMutate(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, [
            'beforeSend' => static function (array $event): ?array {
                if (($event['message'] ?? '') === 'drop-me') {
                    return null;
                }
                $event['message'] = 'mutated:' . $event['message'];

                return $event;
            },
        ]);

        $client->info('drop-me');
        $client->info('keep-me');
        $client->flush();

        self::assertSame(1, count($transport->allEvents()));
        self::assertSame('mutated:keep-me', $transport->allEvents()[0]->message);
    }

    public function testBeforeSendSkippedWhenMinLevelFilters(): void
    {
        $called = 0;
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, [
            'minLevel' => 'error',
            'beforeSend' => static function (array $event) use (&$called): array {
                $called++;

                return $event;
            },
        ]);

        $client->info('filtered');
        $client->error('sent');
        $client->flush();

        self::assertSame(1, $called);
        self::assertSame(['sent'], array_map(static fn ($e) => $e->message, $transport->allEvents()));
    }

    public function testScopedChildInheritsAndCanOverrideMinLevel(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, ['minLevel' => 'warning']);
        $logger = $client->logger(['tags' => ['feature' => 'blog']]);

        self::assertSame(SeverityLevel::Warning, $logger->getMinLevel());
        self::assertFalse($logger->isLevelEnabled('info'));
        self::assertTrue($logger->isLevelEnabled('warning'));

        $raised = $logger->child(['minLevel' => 'error']);
        self::assertSame(SeverityLevel::Error, $raised->getMinLevel());
        self::assertFalse($raised->isLevelEnabled('warning'));

        // Assign replaces parent — child may lower after a raise.
        $weakened = $raised->child(['minLevel' => 'debug']);
        self::assertSame(SeverityLevel::Debug, $weakened->getMinLevel());

        $logger->info('nope');
        $logger->warning('warn-ok');
        $raised->warning('nope2');
        $raised->error('err-ok', ['tags' => ['component' => 'x']]);
        $weakened->info('info-from-weakened');
        $client->flush();

        $events = $transport->allEvents();
        self::assertSame(
            ['warn-ok', 'err-ok', 'info-from-weakened'],
            array_map(static fn ($e) => $e->message, $events),
        );
        self::assertSame('blog', $events[1]->tags['feature'] ?? null);
        self::assertSame('x', $events[1]->tags['component'] ?? null);
    }

    public function testScopedLoggerCanLowerBelowClientDefault(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, [
            'minLevel' => 'warning',
            'enforceDefaultLevel' => false,
        ]);

        $verbose = $client->logger(['minLevel' => 'info', 'tags' => ['area' => 'businessDirectory']]);
        self::assertSame(SeverityLevel::Info, $verbose->getMinLevel());
        self::assertTrue($verbose->isLevelEnabled('info'));

        $verbose->info('bd-info');
        $client->info('direct-dropped');
        $client->flush();

        $messages = array_map(static fn ($e) => $e->message, $transport->allEvents());
        self::assertSame(['bd-info'], $messages);
        self::assertSame('businessDirectory', $transport->allEvents()[0]->tags['area'] ?? null);
    }

    public function testEnforceDefaultLevelRestoresHardFloor(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, [
            'minLevel' => 'warning',
            'enforceDefaultLevel' => true,
        ]);

        $verbose = $client->logger(['minLevel' => 'info']);
        self::assertSame(SeverityLevel::Warning, $verbose->getMinLevel());
        self::assertFalse($verbose->isLevelEnabled('info'));

        $verbose->info('dropped');
        $verbose->warning('kept');
        $client->flush();

        self::assertSame(['kept'], array_map(static fn ($e) => $e->message, $transport->allEvents()));
    }

    public function testNamedLoggerPresets(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, [
            'minLevel' => 'warning',
            'loggers' => [
                'businessDirectory' => [
                    'minLevel' => 'info',
                    'tags' => ['area' => 'businessDirectory'],
                ],
            ],
        ]);

        $byName = $client->logger('businessDirectory');
        self::assertSame(SeverityLevel::Info, $byName->getMinLevel());

        $merged = $client->logger([
            'name' => 'businessDirectory',
            'tags' => ['request' => 'x'],
        ]);
        $merged->info('named-info');
        $client->flush();

        $event = $transport->allEvents()[0];
        self::assertSame('named-info', $event->message);
        self::assertSame('businessDirectory', $event->tags['area'] ?? null);
        self::assertSame('x', $event->tags['request'] ?? null);
    }

    public function testSetMinLevelUpdatesGlobalFloor(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, ['minLevel' => 'debug']);
        $client->setMinLevel('error');
        self::assertSame(SeverityLevel::Error, $client->getMinLevel());

        $client->warning('dropped');
        $client->error('kept');
        $client->flush();

        self::assertSame(['kept'], array_map(static fn ($e) => $e->message, $transport->allEvents()));
    }

    public function testCaptureExceptionRespectsMinLevelFatal(): void
    {
        $transport = new FakeTransport();
        $client = $this->makeClient($transport, ['minLevel' => 'fatal']);

        $client->captureException(new \RuntimeException('dropped-as-error'));
        $client->fatal('kept-fatal');
        $client->flush();

        self::assertSame(1, count($transport->allEvents()));
        self::assertSame('kept-fatal', $transport->allEvents()[0]->message);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function makeClient(FakeTransport $transport, array $overrides = []): TalariaClient
    {
        return new TalariaClient(array_merge([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $overrides), $transport);
    }
}
