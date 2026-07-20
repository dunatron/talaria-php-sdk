<?php

declare(strict_types=1);

namespace Talaria\Context;

/**
 * Best-effort runtime / request context for auto-enrichment.
 */
final class RuntimeContext
{
    /**
     * @return array{url: ?string, requestId: ?string, extra: array<string, mixed>}
     */
    public static function collect(): array
    {
        $url = null;
        if (isset($_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $url = $scheme . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        }

        $requestId = null;
        foreach (['HTTP_X_REQUEST_ID', 'HTTP_X_CORRELATION_ID', 'HTTP_X_AMZN_TRACE_ID'] as $header) {
            if (!empty($_SERVER[$header]) && is_string($_SERVER[$header])) {
                $requestId = $_SERVER[$header];
                break;
            }
        }

        $extra = [
            'php_version' => PHP_VERSION,
            'hostname' => gethostname() ?: null,
            'memory_usage' => memory_get_usage(true),
        ];

        if (isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])) {
            $extra['request_method'] = $_SERVER['REQUEST_METHOD'];
        }
        if (isset($_SERVER['REMOTE_ADDR']) && is_string($_SERVER['REMOTE_ADDR'])) {
            $extra['ip'] = $_SERVER['REMOTE_ADDR'];
        }
        if (isset($_SERVER['HTTP_USER_AGENT']) && is_string($_SERVER['HTTP_USER_AGENT'])) {
            $extra['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
        }

        return [
            'url' => $url,
            'requestId' => $requestId,
            'extra' => array_filter($extra, static fn ($v) => $v !== null),
        ];
    }

    public static function newSessionId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return uniqid('tal_', true);
        }
    }

    public static function isoTimestamp(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
    }
}
