<?php

declare(strict_types=1);

namespace Talaria\SilverStripe\Handlers;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Talaria\SilverStripe\LogHandlerSupport;
use Talaria\TalariaClient;

/**
 * Monolog 1 / 2 handler (array records) — Silverstripe 4 and any Monolog 1.x / 2.x stack.
 *
 * Loaded only via {@see \Talaria\SilverStripe\LogHandlerFactory} require_once.
 * Not registered in Composer PSR-4 so it cannot be autoloaded by accident.
 */
final class TalariaLogHandlerMonologLegacy extends AbstractProcessingHandler
{
    use LogHandlerSupport;

    public function __construct(
        private readonly TalariaClient $client,
        int|string $level = Logger::WARNING,
        bool $bubble = true,
    ) {
        if (is_string($level)) {
            $level = Logger::toMonologLevel($level);
        }

        parent::__construct($level, $bubble);
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function write(array $record): void
    {
        $context = is_array($record['context'] ?? null) ? $record['context'] : [];
        $levelName = is_string($record['level_name'] ?? null)
            ? $record['level_name']
            : 'info';

        $this->forwardToClient(
            $this->client,
            (string) ($record['message'] ?? ''),
            strtolower($levelName),
            (string) ($record['channel'] ?? ''),
            $context,
        );
    }
}
