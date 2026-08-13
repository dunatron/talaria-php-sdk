<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\TalariaClient;

/**
 * Front-controller helper for apps that are not PSR-15 or Silverstripe.
 */
final class IncomingHttp
{
    /**
     * @param array<string, mixed>|null $server Typically $_SERVER
     */
    public static function startTransaction(TalariaClient $client, ?array $server = null): Span
    {
        $server ??= $_SERVER;
        $method = isset($server['REQUEST_METHOD']) && is_string($server['REQUEST_METHOD'])
            ? $server['REQUEST_METHOD']
            : 'GET';
        $path = '/';
        if (isset($server['PATH_INFO']) && is_string($server['PATH_INFO']) && $server['PATH_INFO'] !== '') {
            $path = $server['PATH_INFO'];
        } elseif (isset($server['REQUEST_URI']) && is_string($server['REQUEST_URI'])) {
            $parsed = parse_url($server['REQUEST_URI'], PHP_URL_PATH);
            if (is_string($parsed) && $parsed !== '') {
                $path = $parsed;
            }
        }

        return $client->startTransaction(
            $method . ' ' . $path,
            SpanKind::Server,
            [
                'http.request.method' => $method,
                'url.path' => $path,
                'http.route' => $path,
            ],
        );
    }
}
