<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;
use Talaria\Client;

/**
 * Builds TalariaLogHandler without Injector double-binding constructor args.
 */
final class LogHandlerFactory implements Factory
{
    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create($service, array $params = [])
    {
        /** @var Client $client */
        $client = Injector::inst()->get(Client::class);

        return new TalariaLogHandler($client, Config::minLevel());
    }
}
