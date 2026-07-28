<?php

declare(strict_types=1);

namespace Talaria;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger that forwards to TalariaClient (queued, not sent immediately).
 *
 * Parameter types on {@see log()} are intentionally omitted so this class stays
 * compatible with every {@see \Psr\Log\LoggerInterface} shipped by psr/log 1.x,
 * 2.x, and 3.x. Older interfaces declare `log($level, $message, …)` with no
 * `$message` type; adding `\Stringable|string` makes the method incompatible
 * and fatals on install. Document the PSR-3 contract in PHPDoc; cast at runtime.
 */
final class Logger extends AbstractLogger
{
    public function __construct(private readonly TalariaClient $client)
    {
    }

    /**
     * @param mixed $level PSR-3 level name or constant
     * @param mixed $message String or stringable object (PSR-3); cast with (string)
     * @param array<string, mixed> $context
     */
    public function log($level, $message, array $context = []): void
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
