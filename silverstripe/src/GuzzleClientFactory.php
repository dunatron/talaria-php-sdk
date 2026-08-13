<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;
use Talaria\TalariaClient;
use Talaria\Tracing\GuzzleMiddleware;

/**
 * Injector factory for application Guzzle clients.
 *
 * Adds Talaria client-span middleware. Never used by Serverpod ingest
 * transports, which construct Guzzle directly.
 */
final class GuzzleClientFactory implements Factory
{
    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create($service, array $params = [])
    {
        $config = [];
        if (isset($params['constructor'][0]) && is_array($params['constructor'][0])) {
            $config = $params['constructor'][0];
        } elseif (isset($params[0]) && is_array($params[0])) {
            $config = $params[0];
        }

        $stack = $config['handler'] ?? HandlerStack::create();
        if (!$stack instanceof HandlerStack) {
            $stack = HandlerStack::create($stack);
        }

        try {
            $client = Injector::inst()->get(TalariaClient::class);
            if ($client instanceof TalariaClient) {
                $stack->unshift(GuzzleMiddleware::create($client), 'talaria_tracing');
            }
        } catch (\Throwable) {
            // leave uninstrumented if the SDK is not available
        }

        $config['handler'] = $stack;

        return new GuzzleClient($config);
    }
}
