<?php

declare(strict_types=1);

namespace Talaria;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger that forwards to Talaria\Client (queued, not sent immediately).
 */
final class Logger extends AbstractLogger
{
    public function __construct(private readonly Client $client)
    {
    }

    /**
     * @param mixed $level
     * @param \Stringable|string $message
     * @param array<string, mixed> $context
     */
    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $levelString = is_string($level) ? $level : (string) $level;
        $severity = SeverityLevel::tryFromMixed($levelString) ?? SeverityLevel::Info;

        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $this->client->captureException($exception, [
                'extra' => array_diff_key($context, ['exception' => true]),
                'title' => $context['title'] ?? null,
                'tags' => $context['tags'] ?? null,
                'userId' => $context['userId'] ?? null,
            ]);

            return;
        }

        $this->client->captureMessage((string) $message, $severity, [
            'extra' => $context,
            'title' => is_string($context['title'] ?? null) ? $context['title'] : null,
            'tags' => is_array($context['tags'] ?? null) ? $context['tags'] : null,
            'userId' => is_string($context['userId'] ?? null) ? $context['userId'] : null,
        ]);
    }

    /**
     * Map PSR-3 level constants for callers that prefer them.
     *
     * @return list<string>
     */
    public static function supportedLevels(): array
    {
        return [
            LogLevel::DEBUG,
            LogLevel::INFO,
            LogLevel::NOTICE,
            LogLevel::WARNING,
            LogLevel::ERROR,
            LogLevel::CRITICAL,
            LogLevel::ALERT,
            LogLevel::EMERGENCY,
        ];
    }
}
