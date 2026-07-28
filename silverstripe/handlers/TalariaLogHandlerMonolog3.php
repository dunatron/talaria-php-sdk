<?php

declare(strict_types=1);

namespace Talaria\SilverStripe\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Talaria\SilverStripe\LogHandlerSupport;
use Talaria\TalariaClient;

/**
 * Monolog 3 handler (LogRecord) — Silverstripe 5 / 6.
 *
 * Loaded only via {@see \Talaria\SilverStripe\LogHandlerFactory} require_once.
 * Not registered in Composer PSR-4 so it cannot be autoloaded on Monolog 1/2
 * (which would fatal: write(LogRecord) vs write(array)).
 */
final class TalariaLogHandlerMonolog3 extends AbstractProcessingHandler
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
