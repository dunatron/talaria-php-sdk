<?php

declare(strict_types=1);

namespace Talaria\SilverStripe;

/**
 * Resolves silverstripe/framework version for filterable runtime_version tags.
 */
final class FrameworkVersion
{
    public static function resolve(): ?string
    {
        if (class_exists(\Composer\InstalledVersions::class)) {
            try {
                $version = \Composer\InstalledVersions::getVersion('silverstripe/framework');
                if (is_string($version) && $version !== '') {
                    return self::truncate($version);
                }
            } catch (\Throwable) {
                // ignore
            }
        }

        if (defined('SILVERSTRIPE_VERSION') && is_string(SILVERSTRIPE_VERSION) && SILVERSTRIPE_VERSION !== '') {
            return self::truncate(SILVERSTRIPE_VERSION);
        }

        return null;
    }

    private static function truncate(string $version): string
    {
        if (strlen($version) <= 128) {
            return $version;
        }

        return substr($version, 0, 128);
    }
}
