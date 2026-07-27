<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Static facade mirroring the JS SDK singleton API.
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
