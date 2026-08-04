<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\SilverStripe\FrameworkVersion;
use Talaria\SilverStripe\RequestTags;

final class RequestTagsTest extends TestCase
{
    public function testCollectWithoutDirectorReturnsEmpty(): void
    {
        if (class_exists(\SilverStripe\Control\Director::class)) {
            self::markTestSkipped('Silverstripe Director is available in this environment.');
        }

        self::assertSame([], RequestTags::collect());
    }

    public function testFrameworkVersionResolveDoesNotThrow(): void
    {
        $version = FrameworkVersion::resolve();
        self::assertTrue($version === null || (is_string($version) && $version !== ''));
        if (is_string($version)) {
            self::assertLessThanOrEqual(128, strlen($version));
        }
    }
}
