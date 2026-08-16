<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Config;

final class ConfigTest extends TestCase
{
    public function testWithoutPhpRuntimeTagsDropsCliAndPhpVersion(): void
    {
        $out = Config::withoutPhpRuntimeTags([
            'platform' => 'php',
            'cli' => '1',
            'php.version' => PHP_VERSION,
            'runtime' => 'silverstripe',
            'entity' => 'TalariaBrowserPage',
        ]);

        self::assertArrayNotHasKey('cli', $out);
        self::assertArrayNotHasKey('php.version', $out);
        self::assertArrayNotHasKey('platform', $out);
        self::assertSame('silverstripe', $out['runtime']);
        self::assertSame('TalariaBrowserPage', $out['entity']);
    }

    public function testWithoutPhpRuntimeTagsKeepsPlatformWeb(): void
    {
        $out = Config::withoutPhpRuntimeTags([
            'platform' => 'web',
            'runtime' => 'silverstripe-frontend',
        ]);

        self::assertSame('web', $out['platform']);
        self::assertSame('silverstripe-frontend', $out['runtime']);
    }
}
