<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Talaria\TalariaClient;

/**
 * Monolog 1 handler (Silverstripe 4) that forwards array records into the Talaria batch queue.
 *
 * Selected at runtime by {@see LogHandlerFactory} when {@see \Monolog\LogRecord} is absent.
 */
final class TalariaLogHandlerMonolog1 extends AbstractProcessingHandler
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
