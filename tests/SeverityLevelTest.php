<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\SeverityLevel;

final class SeverityLevelTest extends TestCase
{
    public function testPsrAliases(): void
    {
        self::assertSame(SeverityLevel::Info, SeverityLevel::tryFromMixed('notice'));
        self::assertSame(SeverityLevel::Fatal, SeverityLevel::tryFromMixed('critical'));
        self::assertSame(SeverityLevel::Warning, SeverityLevel::tryFromMixed('warn'));
    }

    public function testEventTypeMapping(): void
    {
        self::assertSame('error', SeverityLevel::Fatal->toEventType());
        self::assertSame('error', SeverityLevel::Error->toEventType());
        self::assertSame('warning', SeverityLevel::Warning->toEventType());
        self::assertSame('info', SeverityLevel::Info->toEventType());
        self::assertSame('debug', SeverityLevel::Debug->toEventType());
    }

    public function testStringableCast(): void
    {
        self::assertSame('info', (string) SeverityLevel::Info);
        self::assertSame('error', (string) SeverityLevel::Error);
    }
}
