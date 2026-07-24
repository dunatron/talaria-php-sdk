<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Wire severity levels accepted by Talaria ingest.
 */
enum SeverityLevel: string implements \Stringable
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Fatal = 'fatal';

    public function __toString(): string
    {
        return $this->value;
    }

    public static function tryFromMixed(string|SeverityLevel $level): ?self
    {
        if ($level instanceof self) {
            return $level;
        }

        $normalized = strtolower(trim($level));

        // Monolog / PSR-3 aliases
        return match ($normalized) {
            'debug' => self::Debug,
            'info', 'notice' => self::Info,
            'warning', 'warn' => self::Warning,
            'error', 'err' => self::Error,
            'critical', 'alert', 'emergency', 'fatal' => self::Fatal,
            default => self::tryFrom($normalized),
        };
    }

    /**
     * Map severity to ingest eventType (fatal has no eventType wire value).
     */
    public function toEventType(): string
    {
        return match ($this) {
            self::Fatal, self::Error => 'error',
            self::Warning => 'warning',
            self::Debug => 'debug',
            self::Info => 'info',
        };
    }
}
