<?php

declare(strict_types=1);

namespace Talaria;

use Psr\Log\AbstractLogger;
use Psr\Log\LogLevel;

/**
 * PSR-3 logger + scoped Talaria capture facade (queued, not sent immediately).
 *
 * Parameter types on {@see log()} are intentionally omitted so this class stays
 * compatible with every {@see \Psr\Log\LoggerInterface} shipped by psr/log 1.x,
 * 2.x, and 3.x. Older interfaces declare `log($level, $message, …)` with no
 * `$message` type; adding `\Stringable|string` makes the method incompatible
 * and fatals on install. Document the PSR-3 contract in PHPDoc; cast at runtime.
 */
final class Logger extends AbstractLogger
{
    /** @var array<string, string> */
    private array $scopeTags;

    private ?SeverityLevel $scopeMinLevel;

    /**
     * @param array{tags?: array<string, string>, minLevel?: string|SeverityLevel} $options
     */
    public function __construct(
        private readonly TalariaClient $client,
        array $options = [],
    ) {
        $this->scopeTags = Config::normalizeTags(
            is_array($options['tags'] ?? null) ? $options['tags'] : [],
        );
        $min = $options['minLevel'] ?? null;
        $this->scopeMinLevel = $min === null
            ? null
            : (SeverityLevel::tryFromMixed(
                $min instanceof SeverityLevel ? $min : (string) $min,
            ));
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

        if (!$severity->atLeast($this->effectiveMinLevel())) {
            return;
        }

        $interpolated = self::interpolate((string) $message, $context);
        $merged = $this->mergeContext($context);

        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $this->client->captureException($exception, [
                'extra' => $merged['extra'],
                'title' => $merged['title'],
                'tags' => $merged['tags'],
                'userId' => $merged['userId'],
            ]);

            return;
        }

        $this->client->captureMessage($interpolated, $severity, [
            'extra' => $merged['extra'],
            'title' => $merged['title'],
            'tags' => $merged['tags'],
            'userId' => $merged['userId'],
        ]);
    }

    /**
     * Alias of {@see warning()} — wire level stays `warning`.
     *
     * @param array<string, mixed> $context
     */
    public function warn(string|\Stringable $message, array $context = []): void
    {
        $this->log(LogLevel::WARNING, $message, $context);
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     * } $context
     */
    public function captureMessage(
        string $message,
        string|SeverityLevel $level = SeverityLevel::Info,
        array $context = [],
    ): void {
        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;
        if (!$severity->atLeast($this->effectiveMinLevel())) {
            return;
        }

        $this->client->captureMessage($message, $severity, $this->mergeContext($context));
    }

    /**
     * @param array{
     *   tags?: array<string, mixed>,
     *   extra?: array<string, mixed>,
     *   userId?: string|null,
     *   title?: string|null,
     *   mechanism?: array{type?: string, handled?: bool, synthetic?: bool},
     * } $context
     */
    public function captureException(\Throwable $exception, array $context = []): void
    {
        if (!SeverityLevel::Error->atLeast($this->effectiveMinLevel())) {
            return;
        }

        $this->client->captureException($exception, $this->mergeContext($context));
    }

    /**
     * @param array<string, string> $tags
     */
    public function withTags(array $tags): self
    {
        return new self($this->client, [
            'tags' => array_merge($this->scopeTags, Config::normalizeTags($tags)),
            'minLevel' => $this->scopeMinLevel,
        ]);
    }

    public function withMinLevel(string|SeverityLevel $minLevel): self
    {
        $next = SeverityLevel::tryFromMixed($minLevel);
        if ($next === null) {
            return $this;
        }

        return new self($this->client, [
            'tags' => $this->scopeTags,
            'minLevel' => $this->scopeMinLevel !== null
                ? SeverityLevel::max($this->scopeMinLevel, $next)
                : $next,
        ]);
    }

    /**
     * @param array{tags?: array<string, string>, minLevel?: string|SeverityLevel} $options
     */
    public function child(array $options = []): self
    {
        $logger = $this;
        if (isset($options['tags']) && is_array($options['tags'])) {
            $logger = $logger->withTags($options['tags']);
        }
        if (isset($options['minLevel'])) {
            $logger = $logger->withMinLevel(
                $options['minLevel'] instanceof SeverityLevel
                    ? $options['minLevel']
                    : (string) $options['minLevel'],
            );
        }

        return $logger;
    }

    public function isLevelEnabled(string|SeverityLevel $level): bool
    {
        $severity = SeverityLevel::tryFromMixed($level) ?? SeverityLevel::Info;

        return $severity->atLeast($this->effectiveMinLevel());
    }

    public function getMinLevel(): SeverityLevel
    {
        return $this->effectiveMinLevel();
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

    private function effectiveMinLevel(): SeverityLevel
    {
        $global = $this->client->getMinLevel();

        return $this->scopeMinLevel !== null
            ? SeverityLevel::max($global, $this->scopeMinLevel)
            : $global;
    }

    /**
     * @param array<string, mixed> $context
     * @return array{
     *   tags: array<string, string>,
     *   extra: array<string, mixed>,
     *   userId: string|null,
     *   title: string|null,
     *   mechanism?: array{type?: string, handled?: bool, synthetic?: bool},
     * }
     */
    private function mergeContext(array $context): array
    {
        $reserved = ['exception' => true, 'tags' => true, 'userId' => true, 'title' => true, 'mechanism' => true];
        $extra = array_diff_key($context, $reserved);
        if (isset($extra['extra']) && is_array($extra['extra'])) {
            $nested = $extra['extra'];
            unset($extra['extra']);
            $extra = array_merge($extra, $nested);
        }

        $callTags = is_array($context['tags'] ?? null) ? $context['tags'] : [];
        $tags = array_merge($this->scopeTags, Config::normalizeTags($callTags));

        $merged = [
            'tags' => $tags,
            'extra' => $extra,
            'userId' => is_string($context['userId'] ?? null) ? $context['userId'] : null,
            'title' => is_string($context['title'] ?? null) ? $context['title'] : null,
        ];
        if (isset($context['mechanism']) && is_array($context['mechanism'])) {
            $merged['mechanism'] = $context['mechanism'];
        }

        return $merged;
    }

    /**
     * PSR-3 / Monolog-style `{key}` interpolation from context.
     *
     * @param array<string, mixed> $context
     */
    private static function interpolate(string $message, array $context): string
    {
        $replace = [];
        foreach ($context as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            if ($key === 'exception') {
                continue;
            }
            if (is_array($value) || (is_object($value) && !$value instanceof \Stringable)) {
                continue;
            }
            if ($value === null || is_bool($value) || is_scalar($value) || $value instanceof \Stringable) {
                $replace['{' . $key . '}'] = $value === null ? '' : (string) $value;
            }
        }

        return $replace === [] ? $message : strtr($message, $replace);
    }
}
