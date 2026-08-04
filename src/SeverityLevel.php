<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Wire severity levels accepted by Talaria ingest.
 */
enum SeverityLevel: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
    case Fatal = 'fatal';

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

    /** Rank from lowest (0) to highest (4) severity. */
    public function rank(): int
    {
        return match ($this) {
            self::Debug => 0,
            self::Info => 1,
            self::Warning => 2,
            self::Error => 3,
            self::Fatal => 4,
        };
    }

    /** True when this level is at least as severe as `$min`. */
    public function atLeast(self $min): bool
    {
        return $this->rank() >= $min->rank();
    }

    /** Higher (stricter floor) of two severities. */
    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }
}
