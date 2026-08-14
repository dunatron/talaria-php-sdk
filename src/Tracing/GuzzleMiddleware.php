<?php

declare(strict_types=1);

namespace Talaria\Tracing;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Talaria\TalariaClient;

/**
 * Guzzle middleware for **application** HTTP clients.
 *
 * Do not install this on {@see \Talaria\Transport\ServerpodHttpTransport} or
 * {@see SpanTransport} — ingest must not span itself.
 */
final class GuzzleMiddleware
{
    public static function create(TalariaClient $client): callable
    {
        return static function (callable $handler) use ($client): callable {
            return static function (RequestInterface $request, array $options) use ($handler, $client) {
                $path = $request->getUri()->getPath();
                if (str_contains($path, '/events/ingestBatch') || str_contains($path, '/spans/ingestBatch')) {
                    return $handler($request, $options);
                }

                $tracer = $client->getTracer();
                if (!$tracer->isEnabled()) {
                    return $handler($request, $options);
                }

                $method = $request->getMethod();
                $uri = $request->getUri();
                $host = $uri->getHost();
                $path = $uri->getPath() !== '' ? $uri->getPath() : '/';
                $name = $host !== '' ? $method . ' ' . $host . $path : $method . ' ' . $path;
                $span = $tracer->startSpan(
                    $name,
                    SpanKind::Client,
                    [
                        'http.request.method' => $method,
                        'url.full' => UrlSanitizer::sanitize((string) $uri),
                        'server.address' => $host,
                    ],
                );

                if ($span->isRecording()) {
                    $request = $request->withHeader(
                        'traceparent',
                        TraceContext::format($span->traceId, $span->spanId, $tracer->isSampled()),
                    );
                }

                $finish = static function (?ResponseInterface $response, mixed $reason) use ($span, $client, $method): void {
                    if ($response !== null) {
                        $status = $response->getStatusCode();
                        $span->setAttribute('http.response.status_code', (string) $status);
                        if ($status >= 500) {
                            $span->setStatus(SpanStatus::Error, 'HTTP ' . $status);
                        } else {
                            $span->setStatus(SpanStatus::Ok);
                        }
                    }
                    if ($reason !== null) {
                        $message = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
                        $span->setStatus(SpanStatus::Error, $message !== '' ? $message : 'request failed');
                    }
                    $span->end();
                    $client->addBreadcrumb([
                        'type' => 'http',
                        'category' => 'http',
                        'message' => $span->name,
                        'level' => $reason !== null ? 'error' : 'info',
                        'data' => [
                            'method' => $method,
                        ],
                    ]);
                };

                try {
                    $result = $handler($request, $options);
                    if (is_object($result) && method_exists($result, 'then')) {
                        return $result->then(
                            static function ($response) use ($finish) {
                                $finish($response instanceof ResponseInterface ? $response : null, null);

                                return $response;
                            },
                            static function ($reason) use ($finish) {
                                $finish(null, $reason);
                                throw $reason instanceof \Throwable
                                    ? $reason
                                    : new \RuntimeException((string) $reason);
                            },
                        );
                    }

                    $finish($result instanceof ResponseInterface ? $result : null, null);

                    return $result;
                } catch (\Throwable $e) {
                    $finish(null, $e);
                    throw $e;
                }
            };
        };
    }
}
