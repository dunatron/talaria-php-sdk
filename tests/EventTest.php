<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Environment;
use Talaria\Event;
use Talaria\SeverityLevel;

final class EventTest extends TestCase
{
    public function testToWireIncludesClassNameAndCamelCase(): void
    {
        $event = new Event(
            message: 'Payment failed',
            environment: Environment::Production,
            level: SeverityLevel::Error,
            title: 'RuntimeException',
            stackTrace: '#0 /app/Checkout.php',
            release: '1.2.3',
            userId: 'user-1',
            sessionId: 'sess-1',
            requestId: 'req-1',
            url: 'https://example.com/checkout',
            tags: ['module' => 'checkout'],
            extraJson: '{"exception_class":"RuntimeException"}',
            timestamp: '2026-07-19T10:00:00.000Z',
        );

        $wire = $event->toWire();

        self::assertSame('IngestEventInput', $wire['__className__']);
        self::assertSame('Payment failed', $wire['message']);
        self::assertSame('production', $wire['environment']);
        self::assertSame('error', $wire['level']);
        self::assertSame('error', $wire['eventType']);
        self::assertSame('RuntimeException', $wire['title']);
        self::assertSame('#0 /app/Checkout.php', $wire['stackTrace']);
        self::assertSame('1.2.3', $wire['release']);
        self::assertSame('user-1', $wire['userId']);
        self::assertSame('sess-1', $wire['sessionId']);
        self::assertSame('req-1', $wire['requestId']);
        self::assertSame('https://example.com/checkout', $wire['url']);
        self::assertSame(['module' => 'checkout'], $wire['tags']);
        self::assertSame('{"exception_class":"RuntimeException"}', $wire['extraJson']);
        self::assertSame('2026-07-19T10:00:00.000Z', $wire['timestamp']);
        self::assertArrayNotHasKey('stack_trace', $wire);
    }

    public function testFatalMapsEventTypeToError(): void
    {
        $event = new Event(
            message: 'Boom',
            environment: Environment::Staging,
            level: SeverityLevel::Fatal,
        );

        self::assertSame('error', $event->toWire()['eventType']);
    }

    public function testEmptyMessageRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Event(message: '  ', environment: Environment::Development, level: SeverityLevel::Info);
    }
}
