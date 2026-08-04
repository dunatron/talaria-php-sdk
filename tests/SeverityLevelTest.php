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

    public function testRankAndAtLeast(): void
    {
        self::assertTrue(SeverityLevel::Error->atLeast(SeverityLevel::Warning));
        self::assertFalse(SeverityLevel::Info->atLeast(SeverityLevel::Warning));
        self::assertSame(SeverityLevel::Error, SeverityLevel::max(SeverityLevel::Debug, SeverityLevel::Error));
        self::assertSame(SeverityLevel::Fatal, SeverityLevel::max(SeverityLevel::Fatal, SeverityLevel::Warning));
    }
}
