<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Talaria\TalariaClient;

/**
 * Monolog 3 handler (Silverstripe 5 / 6) that forwards records into the Talaria batch queue.
 *
 * Do not instantiate this class when Monolog 1 is installed — use
 * {@see LogHandlerFactory}, which selects {@see TalariaLogHandlerMonolog1} instead.
 * Loading this file against Monolog 1's AbstractProcessingHandler causes a fatal
 * signature mismatch (`write(LogRecord)` vs `write(array)`).
 */
final class TalariaLogHandler extends AbstractProcessingHandler
{
    use LogHandlerSupport;

    public function __construct(
        private readonly TalariaClient $client,
        int|string|Level $level = Level::Warning,
        bool $bubble = true,
    ) {
        if (is_string($level)) {
            $level = Level::fromName($level);
        }

        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $this->forwardToClient(
            $this->client,
            $record->message,
            $record->level->toPsrLogLevel(),
            $record->channel,
            $record->context,
        );
    }
}
