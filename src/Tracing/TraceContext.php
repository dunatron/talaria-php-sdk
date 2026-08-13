<?php

declare(strict_types=1);

namespace Talaria\Tracing;

/**
 * W3C Trace Context (`traceparent`) parse / inject.
 *
 * Format: `{version}-{traceId}-{spanId}-{flags}` e.g. `00-{32 hex}-{16 hex}-01`.
 */
final class TraceContext
{
    public function __construct(
        public readonly string $traceId,
        public readonly string $spanId,
        public readonly bool $sampled,
        public readonly string $version = '00',
    ) {
    }

    public static function parse(?string $header): ?self
    {
        if ($header === null) {
            return null;
        }

        $header = trim($header);
        if ($header === '') {
            return null;
        }

        if (preg_match(
            '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/i',
            $header,
            $matches,
        ) !== 1) {
            return null;
        }

        $traceId = strtolower($matches[2]);
        $spanId = strtolower($matches[3]);
        if ($traceId === str_repeat('0', 32) || $spanId === str_repeat('0', 16)) {
            return null;
        }

        $flags = hexdec($matches[4]);

        return new self(
            traceId: $traceId,
            spanId: $spanId,
            sampled: ($flags & 0x01) === 0x01,
            version: strtolower($matches[1]),
        );
    }

    /**
     * @param array<string, mixed>|null $server Typically $_SERVER
     */
    public static function fromServer(?array $server = null): ?self
    {
        $server ??= $_SERVER;
        $header = $server['HTTP_TRACEPARENT'] ?? null;

        return is_string($header) ? self::parse($header) : null;
    }

    public static function format(string $traceId, string $spanId, bool $sampled): string
    {
        return sprintf(
            '00-%s-%s-%s',
            strtolower($traceId),
            strtolower($spanId),
            $sampled ? '01' : '00',
        );
    }

    public function toHeader(): string
    {
        return self::format($this->traceId, $this->spanId, $this->sampled);
    }

    public static function generateTraceId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return str_pad(bin2hex(uniqid('', true)), 32, '0');
        }
    }

    public static function generateSpanId(): string
    {
        try {
            return bin2hex(random_bytes(8));
        } catch (\Throwable) {
            return substr(bin2hex(uniqid('', false)), 0, 16);
        }
    }
}
