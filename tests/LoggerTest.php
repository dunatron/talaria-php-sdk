<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use ReflectionMethod;
use Talaria\Logger;
use Talaria\TalariaClient;

final class LoggerTest extends TestCase
{
    public function testImplementsLoggerInterface(): void
    {
        $logger = $this->makeLogger(new FakeTransport());
        self::assertInstanceOf(LoggerInterface::class, $logger);
    }

    /**
     * Guard: typed $message breaks psr/log 1.x (unt typed LoggerInterface::log).
     * Keep the public PSR surface conservative across psr/log 1, 2, and 3.
     */
    public function testLogMessageParameterHasNoTypeDeclaration(): void
    {
        $param = (new ReflectionMethod(Logger::class, 'log'))->getParameters()[1];
        self::assertNull(
            $param->getType(),
            'Logger::log() $message must stay untyped for psr/log 1.x compatibility',
        );
    }

    public function testLogLevelParameterHasNoTypeDeclaration(): void
    {
        $param = (new ReflectionMethod(Logger::class, 'log'))->getParameters()[0];
        self::assertNull(
            $param->getType(),
            'Logger::log() $level must stay untyped for psr/log 1.x compatibility',
        );
    }

    public function testLogForwardsMessage(): void
    {
        $transport = new FakeTransport();
        $logger = $this->makeLogger($transport);

        $logger->log(LogLevel::WARNING, 'checkout slow', ['order_id' => '9']);
        $loggerClient = $this->clientFrom($logger);
        $loggerClient->flush();

        self::assertSame(1, $transport->batchCount());
        self::assertSame('checkout slow', $transport->batches[0][0]->message);
        self::assertSame('warning', $transport->batches[0][0]->level->value);
    }

    public function testLogAcceptsStringableMessage(): void
    {
        $transport = new FakeTransport();
        $logger = $this->makeLogger($transport);

        $logger->info(new class () implements \Stringable {
            public function __toString(): string
            {
                return 'stringable-ok';
            }
        });
        $this->clientFrom($logger)->flush();

        self::assertSame('stringable-ok', $transport->batches[0][0]->message);
    }

    public function testLogForwardsExceptionContext(): void
    {
        $transport = new FakeTransport();
        $logger = $this->makeLogger($transport);

        $logger->error('wrap', ['exception' => new \RuntimeException('boom')]);
        $this->clientFrom($logger)->flush();

        self::assertSame(1, $transport->batchCount());
        self::assertSame('boom', $transport->batches[0][0]->message);
        self::assertSame('error', $transport->batches[0][0]->level->value);
    }

    private function makeLogger(FakeTransport $transport): Logger
    {
        $client = new TalariaClient([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_testkeytestkeytestkeytestkey123456',
            'environment' => 'development',
            'defaultIntegrations' => false,
            'maxBatchSize' => 50,
            'flushIntervalMs' => 60_000,
        ], $transport);

        return new Logger($client);
    }

    private function clientFrom(Logger $logger): TalariaClient
    {
        $ref = new \ReflectionClass($logger);
        $prop = $ref->getProperty('client');
        $prop->setAccessible(true);

        /** @var TalariaClient $client */
        $client = $prop->getValue($logger);

        return $client;
    }
}
