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

    public function testRequestIdPrefersTraceparentOverXRequestId(): void
    {
        $previousTrace = $_SERVER['HTTP_TRACEPARENT'] ?? null;
        $previousReq = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        $_SERVER['HTTP_TRACEPARENT'] = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $_SERVER['HTTP_X_REQUEST_ID'] = 'legacy-request-id';

        try {
            $runtime = RuntimeContext::collect();
            self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $runtime['requestId']);
        } finally {
            if ($previousTrace === null) {
                unset($_SERVER['HTTP_TRACEPARENT']);
            } else {
                $_SERVER['HTTP_TRACEPARENT'] = $previousTrace;
            }
            if ($previousReq === null) {
                unset($_SERVER['HTTP_X_REQUEST_ID']);
            } else {
                $_SERVER['HTTP_X_REQUEST_ID'] = $previousReq;
            }
        }
    }

    public function testRequestIdFallsBackToXRequestId(): void
    {
        $previousTrace = $_SERVER['HTTP_TRACEPARENT'] ?? null;
        $previousReq = $_SERVER['HTTP_X_REQUEST_ID'] ?? null;
        unset($_SERVER['HTTP_TRACEPARENT']);
        $_SERVER['HTTP_X_REQUEST_ID'] = 'legacy-request-id';

        try {
            $runtime = RuntimeContext::collect();
            self::assertSame('legacy-request-id', $runtime['requestId']);
        } finally {
            if ($previousTrace === null) {
                unset($_SERVER['HTTP_TRACEPARENT']);
            } else {
                $_SERVER['HTTP_TRACEPARENT'] = $previousTrace;
            }
            if ($previousReq === null) {
                unset($_SERVER['HTTP_X_REQUEST_ID']);
            } else {
                $_SERVER['HTTP_X_REQUEST_ID'] = $previousReq;
            }
        }
    }
}
