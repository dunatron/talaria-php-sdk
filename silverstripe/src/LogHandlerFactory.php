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
 * Handler implementations live under {@see silverstripe/handlers/} and are
 * **not** Composer-autoloaded. This factory require_once's exactly one file so
 * PHP never compiles a Monolog 3 `write(LogRecord)` class against Monolog 1/2's
 * `write(array)` abstract method (and vice versa).
 *
 * Detection: {@see \Monolog\LogRecord} exists only in Monolog 3+.
 * Monolog 1 and 2 both use array records → Legacy handler.
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

        $handlersDir = dirname(__DIR__) . '/handlers';

        if (class_exists(\Monolog\LogRecord::class)) {
            require_once $handlersDir . '/TalariaLogHandlerMonolog3.php';

            return new Handlers\TalariaLogHandlerMonolog3($client, $minLevel);
        }

        require_once $handlersDir . '/TalariaLogHandlerMonologLegacy.php';

        return new Handlers\TalariaLogHandlerMonologLegacy($client, $minLevel);
    }
}
