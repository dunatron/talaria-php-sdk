<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Core\Injector\Injector;
use Talaria\TalariaClient;
use Talaria\Tracing\SpanKind;
use Talaria\Tracing\SpanStatus;

/**
 * SERVER span per HTTP request. Continues an incoming W3C `traceparent` when present.
 *
 * FQCN on implements: a `use …\HTTPMiddleware` import collides with this class
 * name because PHP class names are case-insensitive.
 */
final class HttpMiddleware implements \SilverStripe\Control\Middleware\HTTPMiddleware
{
    public function process(HTTPRequest $request, callable $delegate)
    {
        $client = self::client();
        if ($client === null || !$client->getConfig()->enableTracing) {
            return $delegate($request);
        }

        $client->clearBreadcrumbs();
        $client->getTracer()->reset();

        $method = strtoupper((string) $request->httpMethod());
        $route = '/' . ltrim((string) $request->getURL(), '/');
        $name = $method . ' ' . $route;

        $span = $client->startTransaction($name, SpanKind::Server, [
            'http.request.method' => $method,
            'http.route' => $route,
        ]);
        $client->getTracer()->setRequestAttributes([
            'http.request.method' => $method,
            'http.route' => $route,
        ]);
        $client->addBreadcrumb([
            'type' => 'http',
            'category' => 'http',
            'message' => $name,
            'level' => 'info',
            'data' => [
                'method' => $method,
                'url' => $route,
            ],
        ]);

        try {
            $response = $delegate($request);
            $status = method_exists($response, 'getStatusCode') ? (int) $response->getStatusCode() : 200;
            $span->setAttribute('http.response.status_code', (string) $status);
            if ($status >= 500) {
                $span->setStatus(SpanStatus::Error, 'HTTP ' . $status);
                $client->getTracer()->markError('HTTP ' . $status);
            } else {
                $span->setStatus(SpanStatus::Ok);
            }

            return $response;
        } catch (\Throwable $e) {
            $span->setStatus(SpanStatus::Error, $e->getMessage());
            $client->getTracer()->markError($e->getMessage());
            throw $e;
        } finally {
            $span->end();
        }
    }

    private static function client(): ?TalariaClient
    {
        if (!class_exists(Injector::class)) {
            return null;
        }

        try {
            $client = Injector::inst()->get(TalariaClient::class);

            return $client instanceof TalariaClient ? $client : null;
        } catch (\Throwable) {
            return null;
        }
    }
}
