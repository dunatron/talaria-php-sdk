<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Static facade mirroring the browser SDK singleton API.
 */
final class Talaria
{
    private static ?TalariaClient $client = null;

    /**
     * @param array<string, mixed> $options
     */
    public static function init(array $options): TalariaClient
    {
        if (self::$client !== null) {
            error_log('[Talaria] init() called more than once; ignoring subsequent init.');

            return self::$client;
        }

        self::$client = new TalariaClient($options);

        return self::$client;
    }

    public static function getClient(): ?TalariaClient
    {
        return self::$client;
    }

    /**
     * @param string|array{name?: string, tags?: array<string, string>, minLevel?: string|SeverityLevel} $options
     */
    public static function logger(string|array $options = []): Logger
    {
        return self::requireClient()->logger($options);
    }

    public static function isEnforceDefaultLevel(): bool
    {
        return self::requireClient()->isEnforceDefaultLevel();
    }

    public static function setEnforceDefaultLevel(bool $enforce): void
    {
        self::requireClient()->setEnforceDefaultLevel($enforce);
    }

    /**
     * @param array<string, string> $tags
     */
    public static function withTags(array $tags): Logger
    {
        return self::requireClient()->withTags($tags);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function debug(string $message, array $context = []): void
    {
        self::requireClient()->debug($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::requireClient()->info($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warning(string $message, array $context = []): void
    {
        self::requireClient()->warning($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function warn(string $message, array $context = []): void
    {
        self::requireClient()->warn($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::requireClient()->error($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function fatal(string $message, array $context = []): void
    {
        self::requireClient()->fatal($message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function log(
        string|SeverityLevel $level,
        string $message,
        array $context = [],
    ): void {
        self::requireClient()->log($level, $message, $context);
    }

    public static function getMinLevel(): SeverityLevel
    {
        return self::requireClient()->getMinLevel();
    }

    public static function setMinLevel(string|SeverityLevel $level): void
    {
        self::requireClient()->setMinLevel($level);
    }

    public static function isLevelEnabled(string|SeverityLevel $level): bool
    {
        return self::requireClient()->isLevelEnabled($level);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function captureException(\Throwable $exception, array $context = []): void
    {
        self::requireClient()->captureException($exception, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public static function captureMessage(
        string $message,
        string|SeverityLevel $level = SeverityLevel::Info,
        array $context = [],
    ): void {
        self::requireClient()->captureMessage($message, $level, $context);
    }

    public static function flush(): void
    {
        self::$client?->flush();
    }

    public static function close(): void
    {
        self::$client?->close();
        self::$client = null;
    }

    /**
     * @internal Reset singleton between tests.
     */
    public static function reset(): void
    {
        self::$client?->close();
        self::$client = null;
    }

    private static function requireClient(): TalariaClient
    {
        if (self::$client === null) {
            throw new \RuntimeException('Talaria::init() must be called before capturing events.');
        }

        return self::$client;
    }
}
