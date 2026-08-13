<?php

declare(strict_types=1);

namespace Talaria\Tracing;

final class UrlSanitizer
{
    /**
     * Keep scheme/host/path; drop userinfo and query values (keys retained).
     */
    public static function sanitize(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false) {
            return substr($url, 0, 256);
        }

        $scheme = $parts['scheme'] ?? 'http';
        $host = $parts['host'] ?? '';
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $path = $parts['path'] ?? '';

        $query = '';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $keys = [];
            parse_str($parts['query'], $parsed);
            foreach (array_keys($parsed) as $key) {
                if (is_string($key) && $key !== '') {
                    $keys[] = $key . '=';
                }
            }
            if ($keys !== []) {
                $query = '?' . implode('&', $keys);
            }
        }

        return $scheme . '://' . $host . $port . $path . $query;
    }
}
