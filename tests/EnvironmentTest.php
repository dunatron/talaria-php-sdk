<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Config;
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

    public function testUnknownFallsBackToProduction(): void
    {
        self::assertSame(Environment::Production, Environment::fromMixed(''));
        self::assertSame(Environment::Production, Environment::fromMixed('whatever'));
        self::assertSame(Environment::Production, Environment::fromMixed('qa'));
    }

    public function testConfigExposesEnvironmentAsWireString(): void
    {
        $config = new Config([
            'dsn' => 'https://api.example.com',
            'apiKey' => 'tal_live_test_key_xxxxxxxxxxxxxxxxxxxx',
            'environment' => 'test',
        ]);

        self::assertSame('staging', $config->environment);
        self::assertSame('staging', (string) $config->environment);
    }
}
