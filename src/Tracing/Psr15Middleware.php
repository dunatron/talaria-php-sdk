<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Talaria\TalariaClient;

/**
 * PSR-15-shaped middleware (duck-typed so psr/http-server-middleware is optional).
 *
 * `$handler` must implement `handle($request): $response`.
 * `$request` should expose `getMethod()` and `getUri()->getPath()`.
 */
final class Psr15Middleware
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    public function process(object $request, object $handler): mixed
    {
        $method = method_exists($request, 'getMethod') ? (string) $request->getMethod() : 'GET';
        $path = '/';
        if (method_exists($request, 'getUri')) {
            $uri = $request->getUri();
            if (is_object($uri) && method_exists($uri, 'getPath')) {
                $parsed = (string) $uri->getPath();
                if ($parsed !== '') {
                    $path = $parsed;
                }
            }
        }

        $span = $this->client->startTransaction(
            $method . ' ' . $path,
            SpanKind::Server,
            [
                'http.request.method' => $method,
                'url.path' => $path,
                'http.route' => $path,
            ],
        );

        try {
            $response = $handler->handle($request);
            if (is_object($response) && method_exists($response, 'getStatusCode')) {
                $status = (int) $response->getStatusCode();
                $span->setAttribute('http.response.status_code', (string) $status);
                if ($status >= 500) {
                    $span->setStatus(SpanStatus::Error, 'HTTP ' . $status);
                } else {
                    $span->setStatus(SpanStatus::Ok);
                }
            } else {
                $span->setStatus(SpanStatus::Ok);
            }

            return $response;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            throw $e;
        } finally {
            $span->end();
            $this->client->flush();
        }
    }
}
