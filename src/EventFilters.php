<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Message / stack URL ignore matching (Sentry semantics: string = substring).
 */
final class EventFilters
{
    /**
     * @param list<string> $patterns
     */
    public static function matches(string $value, array $patterns): bool
    {
        if ($value === '' || $patterns === []) {
            return false;
        }
        foreach ($patterns as $pattern) {
            if ($pattern === '') {
                continue;
            }
            if (str_starts_with($pattern, '/') && strlen($pattern) >= 3) {
                $last = strrpos($pattern, '/');
                if ($last !== false && $last > 0) {
                    $body = substr($pattern, 1, $last - 1);
                    $flags = substr($pattern, $last + 1);
                    $ok = @preg_match('/' . $body . '/' . $flags, $value);
                    if ($ok === 1) {
                        return true;
                    }
                    continue;
                }
            }
            if (str_contains($value, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<string> $ignoreErrors
     * @param list<string> $ignoreUrls
     */
    public static function shouldDrop(
        string $message,
        ?string $stackTrace,
        array $ignoreErrors,
        array $ignoreUrls,
    ): bool {
        if (self::matches($message, $ignoreErrors)) {
            return true;
        }
        if ($stackTrace !== null && $stackTrace !== '' && self::matches($stackTrace, $ignoreUrls)) {
            return true;
        }

        return false;
    }

    /**
     * @param mixed $raw
     * @return list<string>
     */
    public static function normalizePatterns(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (is_string($item) && $item !== '') {
                $out[] = $item;
            }
        }

        return $out;
    }
}
