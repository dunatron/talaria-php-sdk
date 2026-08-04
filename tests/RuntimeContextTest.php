<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Context\RuntimeContext;

final class RuntimeContextTest extends TestCase
{
    public function testCollectIncludesAutoTags(): void
    {
        $runtime = RuntimeContext::collect();

        self::assertArrayHasKey('tags', $runtime);
        self::assertSame(PHP_VERSION, $runtime['tags']['php.version']);
        self::assertContains($runtime['tags']['cli'], ['true', 'false']);
        self::assertSame(PHP_SAPI === 'cli' ? 'true' : 'false', $runtime['tags']['cli']);
        self::assertSame(PHP_VERSION, $runtime['extra']['php_version']);
    }
}
