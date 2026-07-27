<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use Monolog\Handler\HandlerInterface;
use SilverStripe\Core\Injector\Factory;
use SilverStripe\Core\Injector\Injector;
use Talaria\TalariaClient;

/**
 * Builds the Monolog-version-appropriate Talaria handler.
 *
 * Silverstripe 4 ships Monolog 1 (array records); Silverstripe 5/6 ship Monolog 3
 * ({@see \Monolog\LogRecord}). A single subclass cannot satisfy both
 * AbstractProcessingHandler::write() signatures, so we pick at runtime and avoid
 * autoloading the wrong handler class.
 */
final class LogHandlerFactory implements Factory
{
    /**
     * @param string $service
     * @param array<int|string, mixed> $params
     */
    public function create($service, array $params = []): HandlerInterface
    {
        /** @var TalariaClient $client */
        $client = Injector::inst()->get(TalariaClient::class);
        $minLevel = Config::minLevel();

        // LogRecord exists only in Monolog 3+. class_exists avoids loading the
        // Monolog 3 handler file on SS4 (which would fatal on signature mismatch).
        if (class_exists(\Monolog\LogRecord::class)) {
            return new TalariaLogHandler($client, $minLevel);
        }

        return new TalariaLogHandlerMonolog1($client, $minLevel);
    }
}
