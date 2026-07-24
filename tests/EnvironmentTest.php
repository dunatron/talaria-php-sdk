<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Environment;

final class EnvironmentTest extends TestCase
{
    public function testAliases(): void
    {
        self::assertSame(Environment::Staging, Environment::fromMixed('test'));
        self::assertSame(Environment::Staging, Environment::fromMixed('uat'));
        self::assertSame(Environment::Production, Environment::fromMixed('live'));
        self::assertSame(Environment::Development, Environment::fromMixed('local'));
    }

    public function testStringableCast(): void
    {
        self::assertSame('staging', (string) Environment::Staging);
        self::assertSame('production', (string) Environment::Production);
        self::assertSame('development', (string) Environment::Development);
    }
}
