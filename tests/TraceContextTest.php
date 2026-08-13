<?php

declare(strict_types=1);

namespace Talaria\Tests;

use PHPUnit\Framework\TestCase;
use Talaria\Tracing\TraceContext;

final class TraceContextTest extends TestCase
{
    public function testParseAndInjectRoundTrip(): void
    {
        $header = '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01';
        $parsed = TraceContext::parse($header);

        self::assertNotNull($parsed);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $parsed->traceId);
        self::assertSame('00f067aa0ba902b7', $parsed->spanId);
        self::assertTrue($parsed->sampled);
        self::assertSame($header, $parsed->toHeader());
        self::assertSame(
            $header,
            TraceContext::format($parsed->traceId, $parsed->spanId, true),
        );
    }

    public function testParseUnsampledFlags(): void
    {
        $parsed = TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00');
        self::assertNotNull($parsed);
        self::assertFalse($parsed->sampled);
        self::assertSame(
            '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-00',
            $parsed->toHeader(),
        );
    }

    public function testParseRejectsInvalidAndAllZero(): void
    {
        self::assertNull(TraceContext::parse('not-a-header'));
        self::assertNull(TraceContext::parse(''));
        self::assertNull(TraceContext::parse(null));
        self::assertNull(TraceContext::parse('00-' . str_repeat('0', 32) . '-00f067aa0ba902b7-01'));
        self::assertNull(TraceContext::parse('00-4bf92f3577b34da6a3ce929d0e0e4736-' . str_repeat('0', 16) . '-01'));
    }

    public function testFromServerReadsTraceparent(): void
    {
        $parsed = TraceContext::fromServer([
            'HTTP_TRACEPARENT' => '00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01',
        ]);
        self::assertNotNull($parsed);
        self::assertSame('4bf92f3577b34da6a3ce929d0e0e4736', $parsed->traceId);
    }

    public function testGenerateIdsHaveW3cLengths(): void
    {
        self::assertSame(32, strlen(TraceContext::generateTraceId()));
        self::assertSame(16, strlen(TraceContext::generateSpanId()));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', TraceContext::generateTraceId());
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', TraceContext::generateSpanId());
    }
}
