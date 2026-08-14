<?php

declare(strict_types=1);

namespace Talaria\Tracing;

/**
 * Best-effort SQL literal stripping so query text is low-risk to send.
 *
 * Silverstripe (and ANSI SQL) uses double quotes for identifiers, not strings.
 * Only single-quoted literals and numeric tokens are replaced.
 */
final class SqlSanitizer
{
    private const MAX_LENGTH = 1024;

    public static function sanitize(string $sql): string
    {
        $stripped = preg_replace("/'(?:\\\\'|[^'])*'/", '?', $sql) ?? $sql;
        $stripped = preg_replace_callback(
            '/"([^"]*)"/',
            static function (array $match): string {
                return preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $match[1]) === 1
                    ? $match[0]
                    : '?';
            },
            $stripped,
        ) ?? $stripped;
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

    /**
     * Primary table/collection from FROM / INTO / UPDATE / TABLE, if present.
     */
    public static function table(string $sql): ?string
    {
        if (preg_match(
            '/\b(?:FROM|INTO|UPDATE|TABLE)\s+(?:`|"|\[)?([A-Za-z_][A-Za-z0-9_.]*)/i',
            $sql,
            $matches,
        ) !== 1) {
            return null;
        }

        $name = $matches[1];
        if (strcasecmp($name, 'SELECT') === 0) {
            return null;
        }

        return $name;
    }

    /** OTel-style span name: `SELECT SiteTree`, or `SHOW` when there is no table. */
    public static function spanName(string $sql): string
    {
        $operation = self::operation($sql);
        $table = self::table($sql);

        return $table !== null ? $operation . ' ' . $table : $operation;
    }

    /**
     * @return array<string, string>
     */
    public static function attributes(string $sql, string $system): array
    {
        $operation = self::operation($sql);
        $table = self::table($sql);
        $attributes = [
            'db.system.name' => $system,
            'db.operation.name' => $operation,
            'db.query.text' => self::sanitize($sql),
        ];
        if ($table !== null) {
            $attributes['db.collection.name'] = $table;
        }

        return $attributes;
    }
}
