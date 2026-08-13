<?php

declare(strict_types=1);

namespace Talaria\Tracing;

/**
 * Best-effort SQL literal stripping so query text is low-risk to send.
 */
final class SqlSanitizer
{
    private const MAX_LENGTH = 1024;

    public static function sanitize(string $sql): string
    {
        $stripped = preg_replace("/'(?:\\\\'|[^'])*'/", '?', $sql) ?? $sql;
        $stripped = preg_replace('/"(?:\\\\"|[^"])*"/', '?', $stripped) ?? $stripped;
        $stripped = preg_replace('/\b\d+\b/', '?', $stripped) ?? $stripped;
        $stripped = preg_replace('/\s+/', ' ', $stripped) ?? $stripped;
        $stripped = trim($stripped);

        if (strlen($stripped) > self::MAX_LENGTH) {
            return substr($stripped, 0, self::MAX_LENGTH - 3) . '...';
        }

        return $stripped;
    }

    public static function operation(string $sql): string
    {
        if (preg_match('/^\s*([A-Za-z]+)/', $sql, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return 'QUERY';
    }
}
