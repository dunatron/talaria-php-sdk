<?php

declare(strict_types=1);

namespace Talaria;

/**
 * Wire environments accepted by Talaria ingest.
 */
enum Environment: string
{
    case Production = 'production';
    case Staging = 'staging';
    case Development = 'development';

    public static function fromMixed(string|Environment $environment): self
    {
        if ($environment instanceof self) {
            return $environment;
        }

        $normalized = strtolower(trim($environment));

        return match ($normalized) {
            'prod', 'production', 'live' => self::Production,
            'stage', 'staging', 'uat', 'test' => self::Staging,
            'dev', 'development', 'local' => self::Development,
            default => self::tryFrom($normalized) ?? self::Production,
        };
    }
}
