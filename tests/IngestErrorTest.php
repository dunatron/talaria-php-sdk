<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Transport\IngestError;

final class IngestErrorTest extends TestCase
{
    public function testParseClassNameAndRetry(): void
    {
        $parsed = IngestError::parse(json_encode([
            '__className__' => 'ApiUnauthorizedException',
            'message' => 'Invalid API key',
            'retry' => false,
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ApiUnauthorizedException', $parsed->className);
        self::assertSame('Invalid API key', $parsed->message);
        self::assertFalse($parsed->retry);
        self::assertTrue($parsed->isPermanent());
    }

    public function testParseAlternateClassNameKey(): void
    {
        $parsed = IngestError::parse(json_encode([
            'className' => 'ApiNotFoundException',
            'message' => 'Project not found',
        ], JSON_THROW_ON_ERROR));

        self::assertSame('ApiNotFoundException', $parsed->className);
        self::assertTrue($parsed->isPermanent());
    }

    public function testQuotaRetryTrueIsNotPermanent(): void
    {
        $parsed = IngestError::parse(json_encode([
            'className' => 'ApiConflictException',
            'message' => 'quota exceeded',
            'retry' => true,
        ], JSON_THROW_ON_ERROR));

        self::assertFalse($parsed->isPermanent());
    }

    public function testInactiveProjectIsPermanent(): void
    {
        $parsed = new IngestError(
            className: 'ApiConflictException',
            message: 'Project is not active',
            retry: null,
        );
        self::assertTrue($parsed->isPermanent());
    }

    public function testMissingScopeIsScopeOnly(): void
    {
        $parsed = new IngestError(
            className: 'ApiUnauthorizedException',
            message: 'API key lacks required scope: spansWrite',
            retry: false,
        );
        self::assertTrue($parsed->isPermanent());
        self::assertTrue($parsed->isScopeOnly());
    }
}
